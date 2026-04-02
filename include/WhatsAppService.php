<?php
/**
 * Securis Smart Society Platform — WhatsApp Notification Service
 * Sends WhatsApp messages via Gupshup/Twilio API.
 * Configure WA_PROVIDER, WA_API_KEY, WA_FROM_NUMBER in api_config.php.
 */

class WhatsAppService {

    /**
     * Send a WhatsApp message to a phone number.
     */
    public static function send($phone, $message, $conn, $societyId, $userId = null) {
        $provider = defined('WA_PROVIDER') ? WA_PROVIDER : '';
        $apiKey = defined('WA_API_KEY') ? WA_API_KEY : '';
        $fromNumber = defined('WA_FROM_NUMBER') ? WA_FROM_NUMBER : '';

        if (empty($provider) || empty($apiKey)) {
            return false;
        }

        $success = false;
        $response = '';

        if ($provider === 'gupshup') {
            $success = self::sendViaGupshup($phone, $message, $apiKey, $fromNumber, $response);
        } elseif ($provider === 'twilio') {
            $success = self::sendViaTwilio($phone, $message, $apiKey, $fromNumber, $response);
        }

        self::log($conn, $societyId, $userId, $phone, $message, $success ? 'sent' : 'failed', $response);
        return $success;
    }

    /**
     * Send visitor pass via WhatsApp.
     */
    public static function sendVisitorPass($phone, $visitorName, $societyName, $qrCode, $pin, $validUntil, $conn, $societyId) {
        $message = "$societyName - Visitor Pass\n\n"
            . "Welcome $visitorName!\n"
            . "Your access PIN: $pin\n"
            . "Valid until: $validUntil\n\n"
            . "Show this message or QR code at the gate.\n"
            . "- Securis Smart Society";

        return self::send($phone, $message, $conn, $societyId);
    }

    /**
     * Send bill reminder via WhatsApp.
     */
    public static function sendBillReminder($phone, $residentName, $amount, $dueDate, $societyName, $conn, $societyId, $userId) {
        $message = "$societyName - Bill Reminder\n\n"
            . "Dear $residentName,\n"
            . "Your maintenance bill of Rs.$amount is due on $dueDate.\n"
            . "Please pay via the Securis app to avoid penalties.\n\n"
            . "- Securis Smart Society";

        return self::send($phone, $message, $conn, $societyId, $userId);
    }

    private static function sendViaGupshup($phone, $message, $apiKey, $fromNumber, &$response) {
        $data = [
            'channel' => 'whatsapp',
            'source' => $fromNumber,
            'destination' => preg_replace('/[^0-9]/', '', $phone),
            'message' => json_encode(['type' => 'text', 'text' => $message]),
            'src.name' => 'Securis',
        ];

        $ch = curl_init('https://api.gupshup.io/sm/api/v1/msg');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    private static function sendViaTwilio($phone, $message, $apiKey, $fromNumber, &$response) {
        $parts = explode(':', $apiKey, 2);
        if (count($parts) < 2) return false;

        $accountSid = $parts[0];
        $authToken = $parts[1];
        $data = ['From' => "whatsapp:$fromNumber", 'To' => 'whatsapp:' . $phone, 'Body' => $message];

        $url = "https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_USERPWD => "$accountSid:$authToken",
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    private static function log($conn, $societyId, $userId, $phone, $message, $status, $providerResponse) {
        $stmt = $conn->prepare(
            "INSERT INTO tbl_whatsapp_log (society_id, user_id, phone, message, status, provider_response, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $truncMsg = substr($message, 0, 500);
        $truncResp = substr($providerResponse ?? '', 0, 1000);
        $stmt->bind_param('iissss', $societyId, $userId, $phone, $truncMsg, $status, $truncResp);
        $stmt->execute();
        $stmt->close();
    }
}
