<?php
require_once __DIR__ . '/../config/mpesa_config.php';

class PaymentLink
{
    private const BASE_URL = 'https://wbs.bremac.co.ke';

    public static function generateToken(int $billId): string
    {
        $billId = (int)$billId;
        if ($billId <= 0) {
            throw new InvalidArgumentException('Invalid bill id for payment link');
        }
        $data = (string)$billId;
        $secret = MpesaConfig::getPaymentLinkSecret();
        $signature = hash_hmac('sha256', $data, $secret);
        $payload = $data . '|' . $signature;
        return self::base64UrlEncode($payload);
    }

    public static function getBillIdFromToken(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $decoded = self::base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 2) {
            return null;
        }
        [$billId, $signature] = $parts;
        if (!ctype_digit($billId)) {
            return null;
        }
        $expected = hash_hmac('sha256', $billId, MpesaConfig::getPaymentLinkSecret());
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        return (int)$billId;
    }

    public static function generateLink(int $billId): string
    {
        $token = self::generateToken($billId);
        return rtrim(self::BASE_URL, '/') . '/pay-link?t=' . rawurlencode($token);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $data .= str_repeat('=', $padLen);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
