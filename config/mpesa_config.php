<?php
class MpesaConfig {
    // Sandbox credentials (Change these for production)
    const CONSUMER_KEY = "qJZQH7y0Rkr5ZB2BGPtiREqsadATxNxcYTXauGfEApJqZ4ub";
    const CONSUMER_SECRET = "YOIx5GrupnXjJPs4Bmdzy5A6XI7SAO2gqPrwo4oJ3ieCA9bfGyf8aHLEr6t73HjZ";
    const SHORTCODE = "4166503"; // Production shortcode
    const PASSKEY = "759a508b982bd9c4b11f2120a204000f1a8db4858e023c8a4ceda8dc5e1e8869";
    // M-Pesa STK callback endpoint in this app
    // This should point to api/payments/payment_callback.php (pretty URL without .php)
    const CALLBACK_URL = "https://wbs.bremac.co.ke/api/payments/payment_callback";
    
    // URLs
    // Sandbox URL (kept for testing, not used when in production)
    const BASE_URL_SANDBOX = "https://sandbox.safaricom.co.ke";
    // Production Daraja URL
    const BASE_URL_PRODUCTION = "https://api.safaricom.co.ke";
    
    // System Configuration
    const SYSTEM_NAME = "Water Billing System";
    const CURRENCY = "KES";

    // Secret used to sign one-click payment links sent via SMS.
    // Change this to a long random string in production and keep it private.
    const PAYMENT_LINK_SECRET = "change_this_payment_link_secret_please";
    
    // Determine if in production mode
    public static function isProduction() {
        // Using live shortcode and callback; run against production Daraja
        return true;
    }
    
    public static function getBaseUrl() {
        return self::isProduction() ? self::BASE_URL_PRODUCTION : self::BASE_URL_SANDBOX;
    }
}
?>
