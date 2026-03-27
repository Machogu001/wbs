<?php
require_once __DIR__ . '/sms_config.php';

class MpesaConfig {
    const BASE_URL_SANDBOX = 'https://sandbox.safaricom.co.ke';
    const BASE_URL_PRODUCTION = 'https://api.safaricom.co.ke';

    private static function env(string $key, string $default = ''): string {
        $value = getenv($key);
        return ($value !== false && $value !== null && $value !== '') ? trim((string)$value) : $default;
    }

    public static function getConsumerKey(): string {
        return self::env('MPESA_CONSUMER_KEY');
    }

    public static function getConsumerSecret(): string {
        return self::env('MPESA_CONSUMER_SECRET');
    }

    public static function getShortCode(): string {
        // Backward compatibility: support legacy MPESA_SHORT_CODE key as fallback.
        $shortcode = self::env('MPESA_SHORTCODE');
        if ($shortcode !== '') {
            return $shortcode;
        }
        return self::env('MPESA_SHORT_CODE');
    }

    public static function getPassKey(): string {
        return self::env('MPESA_PASSKEY');
    }

    public static function getCallbackUrl(): string {
        return self::env('MPESA_CALLBACK_URL', 'https://wbs.bremac.co.ke/api/payments/payment_callback');
    }

    public static function getPaymentLinkSecret(): string {
        return self::env('PAYMENT_LINK_SECRET', 'change_this_payment_link_secret_please');
    }

    public static function isProduction() {
        $mode = strtolower(self::env('MPESA_ENV', 'sandbox'));
        return in_array($mode, ['production', 'prod', 'live'], true);
    }
    
    public static function getBaseUrl() {
        return self::isProduction() ? self::BASE_URL_PRODUCTION : self::BASE_URL_SANDBOX;
    }
}
?>
