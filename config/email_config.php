<?php

// Lightweight .env loader for this app (not Laravel)
// Loads key=value pairs from the project root .env into environment variables
// before EmailConfig is used.
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

class EmailConfig {
    public static function getMailer()
    {
        $mailer = getenv('EMAIL_MAILER');
        return $mailer !== false && $mailer !== '' ? $mailer : 'smtp';
    }

    public static function getScheme()
    {
        $scheme = getenv('EMAIL_SCHEME');
        if ($scheme !== false && $scheme !== '') {
            return strtolower($scheme);
        }
        $enc = getenv('EMAIL_ENCRYPTION');
        return $enc !== false && $enc !== '' ? strtolower($enc) : 'ssl';
    }

    public static function getHost()
    {
        $host = getenv('EMAIL_HOST');
        return $host !== false && $host !== '' ? $host : 'localhost';
    }

    public static function getPort()
    {
        $port = getenv('EMAIL_PORT');
        return $port !== false && $port !== '' ? (int)$port : 25;
    }

    public static function getUsername()
    {
        $user = getenv('EMAIL_USERNAME');
        return $user !== false ? $user : '';
    }

    public static function getPassword()
    {
        $pass = getenv('EMAIL_PASSWORD');
        return $pass !== false ? $pass : '';
    }

    public static function getFromAddress()
    {
        $addr = getenv('EMAIL_FROM_ADDRESS');
        if ($addr !== false && $addr !== '') {
            return $addr;
        }
        $user = self::getUsername();
        return $user !== '' ? $user : 'noreply@example.com';
    }

    public static function getFromName()
    {
        $name = getenv('EMAIL_FROM_NAME');
        if ($name !== false && $name !== '') {
            return $name;
        }
        $app = getenv('APP_NAME');
        return $app !== false && $app !== '' ? $app : 'WBS Portal';
    }
}

?>
