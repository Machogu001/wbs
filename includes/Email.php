<?php
require_once __DIR__ . '/../config/email_config.php';

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

        $read = $this->readLine($stream);
        if (substr($read, 0, 3) !== '220') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'Unexpected SMTP greeting: ' . trim($read),
            ];
        }

        $domain = 'localhost';
        $ehloResp = $this->sendCommand($stream, 'EHLO ' . $domain);
        if (substr($ehloResp, 0, 3) !== '250') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'EHLO failed: ' . trim($ehloResp),
            ];
        }

        if ($this->scheme === 'tls') {
            $starttls = $this->sendCommand($stream, 'STARTTLS');
            if (substr($starttls, 0, 3) !== '220') {
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
            if (substr($ehloResp, 0, 3) !== '250') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'EHLO after STARTTLS failed: ' . trim($ehloResp),
                ];
            }
        }

        if ($this->username !== '') {
            $authResp = $this->sendCommand($stream, 'AUTH LOGIN');
            if (substr($authResp, 0, 3) !== '334') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'AUTH LOGIN not accepted: ' . trim($authResp),
                ];
            }
            $userResp = $this->sendCommand($stream, base64_encode($this->username));
            if (substr($userResp, 0, 3) !== '334') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'Username not accepted: ' . trim($userResp),
                ];
            }
            $passResp = $this->sendCommand($stream, base64_encode($this->password));
            if (substr($passResp, 0, 3) !== '235') {
                fclose($stream);
                return [
                    'success' => false,
                    'message' => 'Password not accepted: ' . trim($passResp),
                ];
            }
        }

        $mailFrom = $this->sendCommand($stream, 'MAIL FROM: <' . $this->fromAddress . '>');
        if (substr($mailFrom, 0, 3) !== '250') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'MAIL FROM failed: ' . trim($mailFrom),
            ];
        }

        $rcptTo = $this->sendCommand($stream, 'RCPT TO: <' . $to . '>');
        if (substr($rcptTo, 0, 3) !== '250' && substr($rcptTo, 0, 3) !== '251') {
            fclose($stream);
            return [
                'success' => false,
                'message' => 'RCPT TO failed: ' . trim($rcptTo),
            ];
        }

        $dataResp = $this->sendCommand($stream, 'DATA');
        if (substr($dataResp, 0, 3) !== '354') {
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
        if (substr($dataResult, 0, 3) !== '250') {
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
}

?>
