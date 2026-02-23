<?php
class SmsConfig {
    // MobileSasa API settings
    // Update these values from https://account.mobilesasa.com/
    const BASE_URL = "https://api.mobilesasa.com";
    const SEND_ENDPOINT = "/v1/send/message";

    const API_TOKEN = "qE9BhKnKubJVSS24FPkO6MstZmgOAXRsP8QUE2RtLNtZNP8DcIEv84EsPiZT";      // Bearer token from MobileSasa API Integration
    const SENDER_ID = "BREMAC LTD";      // Approved sender ID

    // Optional: request timeout in seconds
    const TIMEOUT = 30;
}
?>
