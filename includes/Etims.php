<?php
class Etims {
    private $conn;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    private function getSettings(): ?array
    {
        try {
            $stmt = $this->conn->prepare("SELECT company_pin, etims_integration_url, etims_api_key, company_name, currency_code, vat_rate, etims_taxation_type_code FROM billing_settings WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return $settings;
        } catch (\PDOException $e) {
            error_log('ETIMS settings error: ' . $e->getMessage());
            return null;
        }
    }

    public function isConfigured(): bool
    {
        $settings = $this->getSettings();
        if (!$settings) {
            return false;
        }
        $url = trim((string)($settings['etims_integration_url'] ?? ''));
        $key = trim((string)($settings['etims_api_key'] ?? ''));
        return $url !== '' && $key !== '';
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Normalise saveSales path casing like getssl implementation
        $url = preg_replace('~(/api/etimsswitch/)savesales~i', '$1saveSales', $url) ?? $url;
        return $url;
    }

	private function baseUrlFromEndpoint(string $endpointUrl): string
	{
		$parts = parse_url($endpointUrl);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$port = isset($parts['port']) ? (':' . $parts['port']) : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port;
	}

    private function buildPayload(array $payment, array $bill, array $user, array $settings): array
    {
        $companyTin = (string)($settings['company_pin'] ?? '');
        $customerName = (string)($user['full_name'] ?? '');
        $customerPin = (string)($user['tax_pin'] ?? '');
        $companyName = (string)($settings['company_name'] ?? '');
        $currency = (string)($settings['currency_code'] ?? 'KES');
        if ($currency === '') {
            $currency = 'KES';
        }
		$vatRate = isset($settings['vat_rate']) ? (float)$settings['vat_rate'] : 0.0;
		$taxCodeSetting = strtoupper(trim((string)($settings['etims_taxation_type_code'] ?? '')));
		$eps = 0.00001;
		if (abs($vatRate) < $eps) {
			$effectiveTaxCode = $taxCodeSetting !== '' ? $taxCodeSetting : 'A';
		} else {
			$effectiveTaxCode = $taxCodeSetting !== '' ? $taxCodeSetting : 'B';
		}

        // Align SaleDate behaviour with getssl implementation: use payment timestamp (paid/created) in Y-m-d format
        $saleTimestamp = null;
        if (!empty($payment['transaction_date'])) {
            $saleTimestamp = strtotime($payment['transaction_date']);
        } elseif (!empty($payment['created_at'])) {
            $saleTimestamp = strtotime($payment['created_at']);
        } else {
            $saleTimestamp = time();
        }
        $saleDate = date('Y-m-d', $saleTimestamp);
        $invoiceNo = 'INV-' . (int)($bill['id'] ?? $payment['id']);
        $description = 'Water bill';
        if (!empty($bill['billing_month'])) {
            $description .= ' for ' . date('M Y', strtotime($bill['billing_month']));
        }

        $amount = isset($bill['amount']) ? (float)$bill['amount'] : (float)($payment['amount'] ?? 0.0);

        return [
            'tin' => $companyTin,
            'BranchId' => '02',
            'DocumentType' => 'Sale',
            'InvoiceNo' => $invoiceNo,
            'CustPIN' => $customerPin,
            'CustName' => $customerName,
			'SaleDate' => $saleDate,
			'CurrencyCode' => $currency,
            'ExchangeRate' => 1,
            'RefInvoiceNo' => 0,
            'CreditNoteReason' => '',
            'CreatedBy' => 'SYSTEM',
            'CreatedByName' => $companyName !== '' ? $companyName : ($companyTin !== '' ? $companyTin : 'SYSTEM'),
			'itemList' => [
				[
                    'ItemCode' => 'WATER',
                    'ItemClassCode' => '4323151200',
                    'ItemName' => $description,
                    'PackagingUnitCode' => 'OU',
                    'QuantityUnitCode' => 'U',
                    'Quantity' => 1,
                    'UnitPriceExcl' => $amount,
					'TaxRate' => $vatRate,
					'TaxationTypeCode' => $effectiveTaxCode,
                    'DiscountRate' => 0,
                    'DiscountAmount' => 0,
                ],
            ],
        ];
    }

    /**
     * Submit a sale to the configured ETIMS gateway.
     * Returns an array with ok/status/body similar to getssl implementation.
     */
    public function submitSale(array $payment, array $bill, array $user): array
    {
        $settings = $this->getSettings();
        if (!$settings) {
            return ['ok' => false, 'status' => null, 'body' => ['error' => 'No ETIMS settings']];
        }

        $url = $this->normalizeUrl((string)($settings['etims_integration_url'] ?? ''));
        $apiKey = trim((string)($settings['etims_api_key'] ?? ''));

        if ($url === '' || $apiKey === '') {
            return ['ok' => false, 'status' => null, 'body' => ['error' => 'ETIMS not configured']];
        }

        $payload = $this->buildPayload($payment, $bill, $user, $settings);

        $headers = [
            'Content-Type: application/json',
            'X-SOURCE-KEY: ' . $apiKey,
            'X-Source-Key: ' . $apiKey,
            'x-source-key: ' . $apiKey,
            'Authorization: Bearer ' . $apiKey,
            'X-API-KEY: ' . $apiKey,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if (defined('CURL_HTTP_VERSION_1_1')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($responseBody === false) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            $this->logResult($payload, $httpCode ?: 0, ['error' => $errorMsg]);
            return ['ok' => false, 'status' => $httpCode ?: 0, 'body' => ['error' => $errorMsg]];
        }
        curl_close($ch);

        $decoded = json_decode($responseBody, true);
        $body = $decoded !== null ? $decoded : $responseBody;
        $ok = $httpCode >= 200 && $httpCode < 300;

        $this->logResult($payload, $httpCode, $body);

        // Persist ETIMS status against the payment record if possible
        $this->updatePaymentEtimsStatus($payment, $ok, $httpCode, $body);

        return ['ok' => $ok, 'status' => $httpCode, 'body' => $body];
    }

    private function logResult(array $payload, int $status, $body): void
    {
        $logFile = __DIR__ . '/../logs/etims.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }

        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'status' => $status,
            'payload_summary' => [
                'tin' => $payload['tin'] ?? null,
                'InvoiceNo' => $payload['InvoiceNo'] ?? null,
                'CustPIN' => $payload['CustPIN'] ?? null,
                'SaleDate' => $payload['SaleDate'] ?? null,
                'Amount' => isset($payload['itemList'][0]['UnitPriceExcl']) ? $payload['itemList'][0]['UnitPriceExcl'] : null,
            ],
            'response' => $body,
        ];

        file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND);
    }

    private function updatePaymentEtimsStatus(array $payment, bool $ok, int $status, $body): void
    {
        if (!isset($payment['id'])) {
            return;
        }

        // Ensure ETIMS tracking columns exist on payments table
        try {
            $this->conn->exec("ALTER TABLE payments ADD COLUMN etims_status VARCHAR(20) NULL");
        } catch (\PDOException $e) {
            // ignore if exists
        }
        try {
            $this->conn->exec("ALTER TABLE payments ADD COLUMN etims_sent_at DATETIME NULL");
        } catch (\PDOException $e) {
        }
        try {
            $this->conn->exec("ALTER TABLE payments ADD COLUMN etims_last_status_code INT NULL");
        } catch (\PDOException $e) {
        }
        try {
            $this->conn->exec("ALTER TABLE payments ADD COLUMN etims_last_response TEXT NULL");
        } catch (\PDOException $e) {
        }
        try {
            $this->conn->exec("ALTER TABLE payments ADD COLUMN etims_last_error TEXT NULL");
        } catch (\PDOException $e) {
        }
		try {
			$this->conn->exec("ALTER TABLE payments ADD COLUMN etims_invoice_id BIGINT NULL");
		} catch (\PDOException $e) {
		}
		try {
			$this->conn->exec("ALTER TABLE payments ADD COLUMN etims_qr_svg_url VARCHAR(255) NULL");
		} catch (\PDOException $e) {
		}

        $statusText = $ok ? 'sent' : 'failed';
        $now = date('Y-m-d H:i:s');
        $code = $status ?: null;
        $bodyStr = is_string($body) ? $body : json_encode($body);
        $errorSummary = '';
        if (!$ok) {
            if (is_array($body) && isset($body['error'])) {
                $errorSummary = (string)$body['error'];
            } elseif (is_string($bodyStr)) {
                $snippet = trim($bodyStr);
                if (strlen($snippet) > 180) {
                    $snippet = substr($snippet, 0, 180) . '…';
                }
                $errorSummary = $snippet;
            }
        }

        $invoiceId = null;
        $qrSvgUrl = null;
        if ($ok) {
            $decoded = is_array($body) ? $body : json_decode($bodyStr, true);
            if (is_array($decoded)) {
                if (isset($decoded['invoice_id'])) {
                    $invoiceId = (int)$decoded['invoice_id'];
                }
                if (isset($decoded['qr_svg_url'])) {
                    $qrSvgUrl = (string)$decoded['qr_svg_url'];
                }
            }
            if (empty($qrSvgUrl) && $invoiceId) {
                $settings = $this->getSettings();
                $endpoint = isset($settings['etims_integration_url']) ? (string)$settings['etims_integration_url'] : '';
                $base = $this->baseUrlFromEndpoint($endpoint);
                if ($base === '') {
                    $base = 'https://etims.bremac.co.ke';
                }
                $qrSvgUrl = rtrim($base, '/') . '/qr/' . $invoiceId . '.svg';
            }
        }

        try {
            $stmt = $this->conn->prepare("UPDATE payments
                SET etims_status = :status,
                    etims_sent_at = :sent_at,
                    etims_last_status_code = :code,
                    etims_last_response = :resp,
                    etims_last_error = :err,
                    etims_invoice_id = :invoice_id,
                    etims_qr_svg_url = :qr_url
                WHERE id = :id");
            $stmt->bindParam(':status', $statusText);
            $stmt->bindParam(':sent_at', $now);
            $stmt->bindParam(':code', $code, PDO::PARAM_INT);
            $stmt->bindParam(':resp', $bodyStr);
            $stmt->bindParam(':err', $errorSummary);
            $stmt->bindValue(':invoice_id', $invoiceId !== null ? $invoiceId : null, $invoiceId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':qr_url', $qrSvgUrl !== null ? $qrSvgUrl : null, $qrSvgUrl !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindParam(':id', $payment['id'], PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            error_log('ETIMS payment status update error: ' . $e->getMessage());
        }
    }
}
