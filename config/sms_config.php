<?php

// Lightweight .env loader for this app (not Laravel)
// Loads key=value pairs from the project root .env into environment variables
// before SmsConfig is used.
if (!function_exists('wbs_load_env')) {
    function wbs_load_env($path)
    {
        if (!is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '') {
                continue;
            }
            // Strip surrounding quotes
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Attempt to load root .env once when this config is included
wbs_load_env(__DIR__ . '/../.env');

class SmsConfig {
    // MobileSasa API settings
    // Update these values from https://account.mobilesasa.com/
    const BASE_URL = "https://api.mobilesasa.com";
    const SEND_ENDPOINT = "/v1/send/message";

    // NOTE: Do NOT hardcode real tokens in this file.
    // Configure your token via environment variable SMS_API_TOKEN.
    // This placeholder is only used if the env var is not set.
    const API_TOKEN = "";

    // Default sender ID. Can be overridden via SMS_SENDER_ID env var.
    const SENDER_ID = "BREMAC LTD";

    // Optional: request timeout in seconds
    const TIMEOUT = 30;

    public static function getApiToken() {
        $token = getenv('SMS_API_TOKEN');
        if ($token !== false && $token !== '') {
            return $token;
        }
        return self::API_TOKEN;
    }

    public static function getSenderId() {
        $sender = getenv('SMS_SENDER_ID');
        if ($sender !== false && $sender !== '') {
            return $sender;
        }
        return self::SENDER_ID;
    }
}
?>
