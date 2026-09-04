<?php
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/EmailQueue.php';

class Email
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $scheme;
    private string $fromAddress;
    private string $fromName;

    public function __construct()
    {
        $this->host = EmailConfig::getHost();
        $this->port = EmailConfig::getPort();
        $this->username = EmailConfig::getUsername();
        $this->password = EmailConfig::getPassword();
        $this->scheme = EmailConfig::getScheme();
        $this->fromAddress = EmailConfig::getFromAddress();
        $this->fromName = EmailConfig::getFromName();
    }

    /**
     * Send a plain-text email.
     *
     * @param string $toEmail
     * @param string $subject
     * @param string $body
     * @return array{success: bool, message: string}
     */
    public function send(string $toEmail, string $subject, string $body): array
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Invalid recipient email address',
            ];
        }

        if ($this->host === '' || $this->port <= 0) {
            return [
                'success' => false,
                'message' => 'Email server not configured',
            ];
        }

        $headers = [];
        $encodedName = mb_encode_mimeheader($this->fromName, 'UTF-8');
        $headers[] = 'From: ' . $encodedName . ' <' . $this->fromAddress . '>';
        $headers[] = 'Reply-To: ' . $this->fromAddress;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;

        $message = $this->normalizeNewlines($body);
        $headerString = implode("\r\n", $headers);

        $result = $this->sendViaSmtp($toEmail, $subject, $message, $headerString);
        return $result;
    }

    /**
     * Store non-interactive email for background delivery.
     *
     * @return array{success: bool, queued: bool, message: string}
     */
    public function queue(string $toEmail, string $subject, string $body, string $type = 'general'): array
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Invalid recipient email address',
            ];
        }

        try {
            $queued = (new EmailQueue())->queue($toEmail, $subject, $body, $type);
            return [
                'success' => $queued,
                'queued' => $queued,
                'message' => $queued ? 'Email queued for delivery' : 'Unable to queue email',
            ];
        } catch (Throwable $e) {
            error_log('Email queue error: ' . $e->getMessage());
            return [
                'success' => false,
                'queued' => false,
                'message' => 'Unable to queue email',
            ];
        }
    }

    private function normalizeNewlines(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace("\n", "\r\n", $text);
    }

    /**
     * Minimal SMTP client for LOGIN-authenticated, SSL/TLS connections.
     */
    private function sendViaSmtp(string $to, string $subject, string $body, string $headers): array
    {
        $remote = $this->host . ':' . $this->port;
        if ($this->scheme === 'ssl' || $this->scheme === 'smtps') {
            $remote = 'ssl://' . $remote;
        }

        $errno = 0;
        $errstr = '';
        $timeout = 30;

        $stream = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$stream) {
            return [
                'success' => false,
                'message' => 'Unable to connect to mail server: ' . $errstr,
            ];
        }

        stream_set_timeout($stream, $timeout);

        // Read full server greeting (may be multi-line 220- ... 220 )
        $read = $this->readMultiline($stream);
        if ($this->getResponseCode($read) !== '220') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'Unexpected SMTP greeting: ' . trim($read),
            ];
        }

        $domain = 'localhost';
        $ehloResp = $this->sendCommand($stream, 'EHLO ' . $domain);
        if ($this->getResponseCode($ehloResp) !== '250') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'EHLO failed: ' . trim($ehloResp),
            ];
        }

        if ($this->scheme === 'tls') {
            $starttls = $this->sendCommand($stream, 'STARTTLS');
            if ($this->getResponseCode($starttls) !== '220') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'STARTTLS failed: ' . trim($starttls),
                ];
            }
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'Failed to enable TLS encryption',
                ];
            }
            $ehloResp = $this->sendCommand($stream, 'EHLO ' . $domain);
            if ($this->getResponseCode($ehloResp) !== '250') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'EHLO after STARTTLS failed: ' . trim($ehloResp),
                ];
            }
        }

        if ($this->username !== '') {
            $authResp = $this->sendCommand($stream, 'AUTH LOGIN');
            if ($this->getResponseCode($authResp) !== '334') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'AUTH LOGIN not accepted: ' . trim($authResp),
                ];
            }
            $userResp = $this->sendCommand($stream, base64_encode($this->username));
            if ($this->getResponseCode($userResp) !== '334') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'Username not accepted: ' . trim($userResp),
                ];
            }
            $passResp = $this->sendCommand($stream, base64_encode($this->password));
            if ($this->getResponseCode($passResp) !== '235') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'Password not accepted: ' . trim($passResp),
                ];
            }
        }

        $mailFrom = $this->sendCommand($stream, 'MAIL FROM: <' . $this->fromAddress . '>');
        if ($this->getResponseCode($mailFrom) !== '250') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'MAIL FROM failed: ' . trim($mailFrom),
            ];
        }

        $rcptTo = $this->sendCommand($stream, 'RCPT TO: <' . $to . '>');
        $rcptCode = $this->getResponseCode($rcptTo);
        if ($rcptCode !== '250' && $rcptCode !== '251') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'RCPT TO failed: ' . trim($rcptTo),
            ];
        }

        $dataResp = $this->sendCommand($stream, 'DATA');
        if ($this->getResponseCode($dataResp) !== '354') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'DATA command rejected: ' . trim($dataResp),
            ];
        }

        $subjectHeader = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8');
        $data = $subjectHeader . "\r\n" . $headers . "\r\n\r\n" . $body . "\r\n.";
        fwrite($stream, $data . "\r\n");
        $dataResult = $this->readLine($stream);
        if ($this->getResponseCode($dataResult) !== '250') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'Message not accepted: ' . trim($dataResult),
            ];
        }

        $this->sendCommand($stream, 'QUIT');
        fclose($stream);

        return [
            'success' => true,
            'message' => 'Email sent successfully',
        ];
    }

    private function sendCommand($stream, string $command): string
    {
        fwrite($stream, $command . "\r\n");
        return $this->readMultiline($stream);
    }

    private function readLine($stream): string
    {
        $line = fgets($stream, 515);
        return $line === false ? '' : $line;
    }

    private function readMultiline($stream): string
    {
        $data = '';
        while (($line = fgets($stream, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    private function getResponseCode(string $response): string
    {
        $response = trim($response);
        if ($response === '') {
            return '';
        }

        $lines = preg_split("/(\r\n|\r|\n)/", $response);
        if ($lines === false || count($lines) === 0) {
            return '';
        }

        $lastLine = '';
        // Find the last non-empty line
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $candidate = trim($lines[$i]);
            if ($candidate !== '') {
                $lastLine = $candidate;
                break;
            }
        }

        if ($lastLine === '' || strlen($lastLine) < 3) {
            return '';
        }

        return substr($lastLine, 0, 3);
    }
}

?>
