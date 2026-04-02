<?php
/**
 * Securis Smart Society Platform — Payment Gateway Handler
 * Manages payment gateway configuration, Razorpay order creation,
 * payment verification, webhooks, and online payment history.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class PaymentGatewayHandler {
    private $conn;
    private $auth;
    private $input;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    public function handle($method, $action, $id) {
        switch ($method) {
            case 'GET':
                switch ($action) {
                    case 'gateway':
                        $this->getGatewayConfig();
                        break;
                    case 'online-history':
                        $this->listOnlinePayments();
                        break;
                    default:
                        ApiResponse::notFound('Payment endpoint not found');
                }
                break;

            case 'POST':
                switch ($action) {
                    case 'gateway':
                        $this->configureGateway();
                        break;
                    case 'create-order':
                        $this->createOrder();
                        break;
                    case 'verify':
                        $this->verifyPayment();
                        break;
                    case 'webhook':
                        $this->handleWebhook();
                        break;
                    default:
                        ApiResponse::notFound('Payment endpoint not found');
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // =========================================================================
    // Gateway Configuration
    // =========================================================================

    /**
     * GET /payment/gateway
     * Get the society's payment gateway configuration. Admin only.
     */
    private function getGatewayConfig() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $stmt = $this->conn->prepare(
            "SELECT id, society_id, gateway_name, api_key, is_active, created_at
             FROM tbl_payment_gateway
             WHERE society_id = ?"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::success(null, 'No payment gateway configured');
        }

        $row = $result->fetch_assoc();

        // Mask the API key for security — show only last 4 chars
        $maskedKey = $row['api_key']
            ? str_repeat('*', max(0, strlen($row['api_key']) - 4)) . substr($row['api_key'], -4)
            : null;

        $gateway = [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'gateway_name' => $row['gateway_name'],
            'api_key_masked' => $maskedKey,
            'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'],
        ];

        ApiResponse::success($gateway, 'Payment gateway configuration retrieved');
    }

    /**
     * POST /payment/gateway
     * Configure or update the payment gateway for the society. Admin only.
     * Input: gateway_name, api_key, api_secret, webhook_secret, is_active.
     * Upserts — if a config already exists for this society, it is updated.
     */
    private function configureGateway() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $gatewayName = sanitizeInput($this->input['gateway_name'] ?? 'razorpay');
        $apiKey = trim($this->input['api_key'] ?? '');
        $apiSecret = trim($this->input['api_secret'] ?? '');
        $webhookSecret = trim($this->input['webhook_secret'] ?? '');
        $isActive = isset($this->input['is_active']) ? (int)(bool)$this->input['is_active'] : 1;

        $allowed = ['razorpay', 'paytm', 'phonepe', 'stripe'];
        if (!in_array($gatewayName, $allowed)) {
            ApiResponse::error('Invalid gateway. Allowed: ' . implode(', ', $allowed));
        }

        if (empty($apiKey) || empty($apiSecret)) {
            ApiResponse::error('API key and API secret are required');
        }

        // Check if config already exists
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_payment_gateway WHERE society_id = ?"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $existing = $stmt->get_result();

        if ($existing->num_rows > 0) {
            $existingId = (int)$existing->fetch_assoc()['id'];
            $stmt = $this->conn->prepare(
                "UPDATE tbl_payment_gateway
                 SET gateway_name = ?, api_key = ?, api_secret = ?, webhook_secret = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('ssssii', $gatewayName, $apiKey, $apiSecret, $webhookSecret, $isActive, $existingId);
            $stmt->execute();
            $configId = $existingId;
            $message = 'Payment gateway updated successfully';
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO tbl_payment_gateway
                 (society_id, gateway_name, api_key, api_secret, webhook_secret, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issssi', $societyId, $gatewayName, $apiKey, $apiSecret, $webhookSecret, $isActive);
            $stmt->execute();
            $configId = $this->conn->insert_id;
            $message = 'Payment gateway configured successfully';
        }

        $maskedKey = str_repeat('*', max(0, strlen($apiKey) - 4)) . substr($apiKey, -4);

        ApiResponse::success([
            'id' => $configId,
            'society_id' => $societyId,
            'gateway_name' => $gatewayName,
            'api_key_masked' => $maskedKey,
            'is_active' => (bool)$isActive,
        ], $message);
    }

    // =========================================================================
    // Order Creation (Razorpay)
    // =========================================================================

    /**
     * POST /payment/create-order
     * Create a Razorpay order for a maintenance bill.
     * Input: bill_id.
     * Returns order_id for the frontend Razorpay checkout.
     */
    private function createOrder() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $billId = intval($this->input['bill_id'] ?? 0);
        if ($billId <= 0) {
            ApiResponse::error('Valid bill_id is required');
        }

        // Fetch the bill and verify it belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT b.id, b.flat_id, b.total_amount, b.penalty_amount, b.status
             FROM tbl_maintenance_bill b
             WHERE b.id = ? AND b.society_id = ?"
        );
        $stmt->bind_param('ii', $billId, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Bill not found');
        }

        $bill = $result->fetch_assoc();

        if ($bill['status'] === 'paid') {
            ApiResponse::error('This bill is already fully paid');
        }

        // Calculate remaining amount
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total_paid FROM tbl_payment WHERE bill_id = ?"
        );
        $stmt->bind_param('i', $billId);
        $stmt->execute();
        $totalPaid = (float)$stmt->get_result()->fetch_assoc()['total_paid'];

        $billTotal = (float)$bill['total_amount'] + (float)$bill['penalty_amount'];
        $remaining = round($billTotal - $totalPaid, 2);

        if ($remaining <= 0) {
            ApiResponse::error('No outstanding balance on this bill');
        }

        // Fetch gateway config
        $gateway = $this->getActiveGateway($societyId);

        // Create Razorpay order via API
        $amountInPaise = (int)($remaining * 100);
        $receiptId = 'bill_' . $billId . '_' . time();

        $orderPayload = [
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'receipt' => $receiptId,
            'notes' => [
                'bill_id' => $billId,
                'society_id' => $societyId,
            ],
        ];

        $response = $this->razorpayRequest(
            'https://api.razorpay.com/v1/orders',
            'POST',
            $gateway['api_key'],
            $gateway['api_secret'],
            $orderPayload
        );

        if (isset($response['error'])) {
            $errorDesc = $response['error']['description'] ?? 'Unknown error';
            ApiResponse::error('Failed to create payment order: ' . $errorDesc, 502);
        }

        if (empty($response['id'])) {
            ApiResponse::error('Invalid response from payment gateway', 502);
        }

        // Create a payment record in pending state
        $residentId = $this->auth->getResidentId();
        $recordedBy = $this->auth->getUserId();

        $gatewayOrderId = $response['id'];
        $receiptNumber = generateReceiptNumber();
        $paymentNotes = 'Razorpay order created';

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_payment
             (bill_id, resident_id, amount, payment_mode, transaction_id, payment_date, receipt_number, recorded_by, notes)
             VALUES (?, ?, ?, 'online', ?, NOW(), ?, ?, ?)"
        );
        $stmt->bind_param('iidssss', $billId, $residentId, $remaining, $gatewayOrderId, $receiptNumber, $recordedBy, $paymentNotes);
        $stmt->execute();
        $paymentId = $this->conn->insert_id;

        // Store in tbl_online_payment
        $gatewayName = $gateway['gateway_name'];
        $responseJson = json_encode($response);
        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_online_payment
             (payment_id, gateway_name, gateway_order_id, amount, currency, status, response_json)
             VALUES (?, ?, ?, ?, 'INR', 'created', ?)"
        );
        $stmt->bind_param('issds', $paymentId, $gatewayName, $gatewayOrderId, $remaining, $responseJson);
        $stmt->execute();

        ApiResponse::created([
            'order_id' => $gatewayOrderId,
            'amount' => $remaining,
            'amount_in_paise' => $amountInPaise,
            'currency' => 'INR',
            'bill_id' => $billId,
            'payment_id' => $paymentId,
            'receipt_number' => $receiptNumber,
            'key_id' => $gateway['api_key'],
        ], 'Payment order created successfully');
    }

    // =========================================================================
    // Payment Verification (Razorpay)
    // =========================================================================

    /**
     * POST /payment/verify
     * Verify Razorpay payment after frontend checkout.
     * Input: razorpay_order_id, razorpay_payment_id, razorpay_signature.
     * Verifies HMAC signature, updates tbl_online_payment, tbl_payment, and tbl_maintenance_bill.
     */
    private function verifyPayment() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $orderId = sanitizeInput($this->input['razorpay_order_id'] ?? '');
        $paymentId = sanitizeInput($this->input['razorpay_payment_id'] ?? '');
        $signature = sanitizeInput($this->input['razorpay_signature'] ?? '');

        if (empty($orderId) || empty($paymentId) || empty($signature)) {
            ApiResponse::error('razorpay_order_id, razorpay_payment_id, and razorpay_signature are required');
        }

        // Fetch the online payment record
        $stmt = $this->conn->prepare(
            "SELECT op.id, op.payment_id, op.amount, op.status,
                    p.bill_id
             FROM tbl_online_payment op
             JOIN tbl_payment p ON p.id = op.payment_id
             JOIN tbl_maintenance_bill b ON b.id = p.bill_id
             WHERE op.gateway_order_id = ? AND b.society_id = ?"
        );
        $stmt->bind_param('si', $orderId, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Payment order not found');
        }

        $onlinePayment = $result->fetch_assoc();

        if ($onlinePayment['status'] === 'captured') {
            ApiResponse::error('This payment has already been verified');
        }

        // Fetch gateway config to get secret
        $gateway = $this->getActiveGateway($societyId);

        // Verify HMAC SHA256 signature
        $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $gateway['api_secret']);

        if (!hash_equals($expectedSignature, $signature)) {
            // Mark as failed
            $stmt = $this->conn->prepare(
                "UPDATE tbl_online_payment SET status = 'failed', gateway_payment_id = ?, updated_at = NOW() WHERE id = ?"
            );
            $onlinePaymentId = (int)$onlinePayment['id'];
            $stmt->bind_param('si', $paymentId, $onlinePaymentId);
            $stmt->execute();

            ApiResponse::error('Payment verification failed: invalid signature', 400);
        }

        // Signature valid — update records
        $this->conn->begin_transaction();

        try {
            // Update online payment record
            $responseJson = json_encode($this->input);
            $onlinePaymentId = (int)$onlinePayment['id'];
            $stmt = $this->conn->prepare(
                "UPDATE tbl_online_payment
                 SET gateway_payment_id = ?, gateway_signature = ?, status = 'captured',
                     response_json = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param('sssi', $paymentId, $signature, $responseJson, $onlinePaymentId);
            $stmt->execute();

            // Update tbl_payment with the gateway payment ID
            $paymentRecordId = (int)$onlinePayment['payment_id'];
            $capturedNotes = 'Payment captured via Razorpay';
            $stmt = $this->conn->prepare(
                "UPDATE tbl_payment SET transaction_id = ?, notes = ? WHERE id = ?"
            );
            $stmt->bind_param('ssi', $paymentId, $capturedNotes, $paymentRecordId);
            $stmt->execute();

            // Determine bill status
            $billId = (int)$onlinePayment['bill_id'];
            $this->updateBillStatus($billId);

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            ApiResponse::error('Failed to process payment verification: ' . $e->getMessage(), 500);
        }

        ApiResponse::success([
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'status' => 'captured',
            'amount' => (float)$onlinePayment['amount'],
            'bill_id' => (int)$onlinePayment['bill_id'],
        ], 'Payment verified and captured successfully');
    }

    // =========================================================================
    // Webhook (Razorpay)
    // =========================================================================

    /**
     * POST /payment/webhook
     * Handle Razorpay webhook callbacks. No auth required.
     * Verifies webhook signature using the webhook_secret.
     * Handles payment.captured and payment.failed events.
     */
    private function handleWebhook() {
        // Read raw POST body for signature verification
        $rawBody = file_get_contents('php://input');
        $webhookSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

        if (empty($rawBody) || empty($webhookSignature)) {
            ApiResponse::error('Invalid webhook request', 400);
        }

        $payload = json_decode($rawBody, true);
        if (!$payload) {
            ApiResponse::error('Invalid webhook payload', 400);
        }

        // Extract the order_id from payload to find the society
        $orderId = $payload['payload']['payment']['entity']['order_id'] ?? '';
        if (empty($orderId)) {
            ApiResponse::error('Missing order_id in webhook payload', 400);
        }

        // Find the online payment and corresponding society
        $stmt = $this->conn->prepare(
            "SELECT op.id, op.payment_id, op.amount, op.status,
                    p.bill_id, b.society_id
             FROM tbl_online_payment op
             JOIN tbl_payment p ON p.id = op.payment_id
             JOIN tbl_maintenance_bill b ON b.id = p.bill_id
             WHERE op.gateway_order_id = ?"
        );
        $stmt->bind_param('s', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::error('Order not found', 404);
        }

        $onlinePayment = $result->fetch_assoc();
        $webhookSocietyId = (int)$onlinePayment['society_id'];

        // Fetch webhook secret for this society
        $stmt = $this->conn->prepare(
            "SELECT webhook_secret FROM tbl_payment_gateway WHERE society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('i', $webhookSocietyId);
        $stmt->execute();
        $gwResult = $stmt->get_result();

        if ($gwResult->num_rows === 0) {
            ApiResponse::error('Payment gateway not configured', 400);
        }

        $webhookSecret = $gwResult->fetch_assoc()['webhook_secret'];

        // Verify webhook signature
        if (!empty($webhookSecret)) {
            $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);
            if (!hash_equals($expectedSignature, $webhookSignature)) {
                ApiResponse::error('Invalid webhook signature', 401);
            }
        }

        $event = $payload['event'] ?? '';
        $gatewayPaymentId = $payload['payload']['payment']['entity']['id'] ?? '';

        switch ($event) {
            case 'payment.captured':
                if ($onlinePayment['status'] !== 'captured') {
                    $this->conn->begin_transaction();
                    try {
                        $responseJson = json_encode($payload);
                        $onlinePaymentId = (int)$onlinePayment['id'];
                        $stmt = $this->conn->prepare(
                            "UPDATE tbl_online_payment
                             SET gateway_payment_id = ?, status = 'captured', response_json = ?, updated_at = NOW()
                             WHERE id = ?"
                        );
                        $stmt->bind_param('ssi', $gatewayPaymentId, $responseJson, $onlinePaymentId);
                        $stmt->execute();

                        // Update tbl_payment
                        $webhookNotes = 'Captured via webhook';
                        $paymentRecordId = (int)$onlinePayment['payment_id'];
                        $stmt = $this->conn->prepare(
                            "UPDATE tbl_payment SET transaction_id = ?, notes = ? WHERE id = ?"
                        );
                        $stmt->bind_param('ssi', $gatewayPaymentId, $webhookNotes, $paymentRecordId);
                        $stmt->execute();

                        // Update bill status
                        $billId = (int)$onlinePayment['bill_id'];
                        $this->updateBillStatus($billId);

                        $this->conn->commit();
                    } catch (Exception $e) {
                        $this->conn->rollback();
                        ApiResponse::error('Webhook processing failed', 500);
                    }
                }
                break;

            case 'payment.failed':
                $responseJson = json_encode($payload);
                $onlinePaymentId = (int)$onlinePayment['id'];
                $stmt = $this->conn->prepare(
                    "UPDATE tbl_online_payment
                     SET gateway_payment_id = ?, status = 'failed', response_json = ?, updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->bind_param('ssi', $gatewayPaymentId, $responseJson, $onlinePaymentId);
                $stmt->execute();
                break;

            default:
                // Ignore other events
                break;
        }

        ApiResponse::success(['event' => $event, 'status' => 'processed'], 'Webhook processed');
    }

    // =========================================================================
    // Online Payment History
    // =========================================================================

    /**
     * GET /payment/online-history
     * List online payment transactions. Paginated.
     * Residents see only their own; admins see all for the society.
     */
    private function listOnlinePayments() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $user = $this->auth->getUser();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $isPrimary = !empty($user['is_primary']);

        $where = "b.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!$isPrimary) {
            $residentId = $this->auth->getResidentId();
            if (!$residentId) {
                ApiResponse::error('No resident record found for your account');
            }
            $where .= " AND p.resident_id = ?";
            $params[] = $residentId;
            $types .= 'i';
        }

        // Filter by status
        if (!empty($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $where .= " AND op.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        // Count
        $countSql = "SELECT COUNT(*) as total
                     FROM tbl_online_payment op
                     JOIN tbl_payment p ON p.id = op.payment_id
                     JOIN tbl_maintenance_bill b ON b.id = p.bill_id
                     WHERE $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch
        $sql = "SELECT op.id, op.payment_id, op.gateway_name, op.gateway_order_id,
                       op.gateway_payment_id, op.amount, op.currency, op.status,
                       op.created_at, op.updated_at,
                       p.bill_id, p.receipt_number,
                       b.month, b.year,
                       f.flat_number, t.name as tower_name
                FROM tbl_online_payment op
                JOIN tbl_payment p ON p.id = op.payment_id
                JOIN tbl_maintenance_bill b ON b.id = p.bill_id
                JOIN tbl_flat f ON f.id = b.flat_id
                JOIN tbl_tower t ON t.id = f.tower_id
                WHERE $where
                ORDER BY op.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = [
                'id' => (int)$row['id'],
                'payment_id' => (int)$row['payment_id'],
                'gateway_name' => $row['gateway_name'],
                'gateway_order_id' => $row['gateway_order_id'],
                'gateway_payment_id' => $row['gateway_payment_id'],
                'amount' => (float)$row['amount'],
                'currency' => $row['currency'],
                'status' => $row['status'],
                'bill_id' => (int)$row['bill_id'],
                'bill_month' => (int)$row['month'],
                'bill_year' => (int)$row['year'],
                'receipt_number' => $row['receipt_number'],
                'flat_number' => $row['flat_number'],
                'tower_name' => $row['tower_name'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        ApiResponse::paginated($payments, $total, $page, $perPage, 'Online payments retrieved successfully');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Fetch the active payment gateway configuration for a society.
     * Exits with error if not configured.
     */
    private function getActiveGateway($societyId) {
        $stmt = $this->conn->prepare(
            "SELECT id, gateway_name, api_key, api_secret, webhook_secret
             FROM tbl_payment_gateway
             WHERE society_id = ? AND is_active = 1"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::error('Payment gateway is not configured for this society. Please configure it first.');
        }

        return $result->fetch_assoc();
    }

    /**
     * Make a request to the Razorpay API using cURL.
     */
    private function razorpayRequest($url, $method, $apiKey, $apiSecret, $data = null) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $apiKey . ':' . $apiSecret,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['error' => ['description' => 'cURL error: ' . $curlError]];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['error' => ['description' => 'Invalid JSON response from gateway']];
        }

        return $decoded;
    }

    /**
     * Update the bill status based on total payments received.
     */
    private function updateBillStatus($billId) {
        $stmt = $this->conn->prepare(
            "SELECT total_amount, penalty_amount FROM tbl_maintenance_bill WHERE id = ?"
        );
        $stmt->bind_param('i', $billId);
        $stmt->execute();
        $bill = $stmt->get_result()->fetch_assoc();
        $billTotal = (float)$bill['total_amount'] + (float)$bill['penalty_amount'];

        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total_paid FROM tbl_payment WHERE bill_id = ?"
        );
        $stmt->bind_param('i', $billId);
        $stmt->execute();
        $totalPaid = (float)$stmt->get_result()->fetch_assoc()['total_paid'];

        if ($totalPaid >= $billTotal) {
            $stmt = $this->conn->prepare(
                "UPDATE tbl_maintenance_bill SET status = 'paid', paid_at = NOW() WHERE id = ?"
            );
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE tbl_maintenance_bill SET status = 'partially_paid' WHERE id = ?"
            );
        }
        $stmt->bind_param('i', $billId);
        $stmt->execute();
    }
}
