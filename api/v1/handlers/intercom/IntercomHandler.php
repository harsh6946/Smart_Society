<?php
/**
 * Securis Smart Society Platform — Intercom Handler
 * Guard-to-resident audio/video calling via Agora.io SDK.
 * Manages call initiation, token generation, status updates, and history.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class IntercomHandler {
    private $conn;
    private $auth;
    private $input;
    private $user;
    private $societyId;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    public function handle($method, $action, $id) {
        $this->user = $this->auth->authenticate();
        $this->societyId = $this->auth->requireSociety();

        switch ($method) {
            case 'GET':
                if ($action === 'history') {
                    $this->getCallHistory();
                } else {
                    ApiResponse::notFound('Unknown intercom action');
                }
                break;

            case 'POST':
                if ($action === 'token') {
                    $this->generateToken();
                } else {
                    ApiResponse::notFound('Unknown intercom action');
                }
                break;

            case 'PUT':
                if ($id && $action === 'end') {
                    $this->endCall($id);
                } elseif ($id && $action === 'status') {
                    $this->updateCallStatus($id);
                } else {
                    ApiResponse::error('Call ID is required', 400);
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * POST /intercom/token
     * Generate Agora RTC token and create call record.
     */
    private function generateToken() {
        $flatId = intval($this->input['flat_id'] ?? 0);
        $callType = $this->input['call_type'] ?? 'audio';

        if ($flatId <= 0) {
            ApiResponse::error('flat_id is required', 400);
        }

        if (!in_array($callType, ['audio', 'video'])) {
            ApiResponse::error('call_type must be audio or video', 400);
        }

        // Get caller info
        $callerType = $this->auth->isGuard() ? 'guard' : 'resident';
        $callerName = $this->user['name'] ?? 'Unknown';

        // Create call record
        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_intercom_call
             (society_id, caller_type, caller_id, caller_name, target_flat_id, call_type, status, started_at)
             VALUES (?, ?, ?, ?, ?, ?, 'ringing', NOW())"
        );
        $stmt->bind_param('isisis', $this->societyId, $callerType, $this->user['id'],
            $callerName, $flatId, $callType);
        $stmt->execute();
        $callId = $stmt->insert_id;
        $stmt->close();

        // Generate channel name
        $channelName = "call_{$callId}";

        // Generate Agora token
        $appId = defined('AGORA_APP_ID') ? AGORA_APP_ID : '';
        $appCertificate = defined('AGORA_APP_CERTIFICATE') ? AGORA_APP_CERTIFICATE : '';
        $uid = $this->user['id'];
        $expireSeconds = 3600; // 1 hour

        $token = '';
        if (!empty($appId) && !empty($appCertificate)) {
            $token = $this->buildAgoraToken($appId, $appCertificate, $channelName, $uid, $expireSeconds);
        }

        // Send push notification to target flat residents
        $this->notifyFlatResidents($flatId, $callerName, $callId, $channelName, $callType, $token);

        ApiResponse::success([
            'call_id' => $callId,
            'channel_name' => $channelName,
            'token' => $token,
            'app_id' => $appId,
            'uid' => $uid,
            'call_type' => $callType,
        ]);
    }

    /**
     * PUT /intercom/{id}/end
     */
    private function endCall($callId) {
        $stmt = $this->conn->prepare(
            "UPDATE tbl_intercom_call SET status = 'ended', ended_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $callId, $this->societyId);
        $stmt->execute();
        $stmt->close();

        ApiResponse::success(['message' => 'Call ended']);
    }

    /**
     * PUT /intercom/{id}/status
     */
    private function updateCallStatus($callId) {
        $status = $this->input['status'] ?? '';
        $allowed = ['answered', 'rejected', 'missed'];
        if (!in_array($status, $allowed)) {
            ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowed), 400);
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_intercom_call SET status = ? WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('sii', $status, $callId, $this->societyId);
        $stmt->execute();
        $stmt->close();

        ApiResponse::success(['message' => "Call status updated to $status"]);
    }

    /**
     * GET /intercom/history
     */
    private function getCallHistory() {
        $page = max(1, intval($this->input['page'] ?? 1));
        $limit = min(50, max(1, intval($this->input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $this->conn->prepare(
            "SELECT ic.*, f.flat_number, t.name AS tower_name
             FROM tbl_intercom_call ic
             LEFT JOIN tbl_flat f ON ic.target_flat_id = f.id
             LEFT JOIN tbl_tower t ON f.tower_id = t.id
             WHERE ic.society_id = ?
               AND (ic.caller_id = ? OR ic.target_flat_id IN (
                    SELECT flat_id FROM tbl_resident WHERE user_id = ? AND status = 'approved'
               ))
             ORDER BY ic.started_at DESC
             LIMIT ? OFFSET ?"
        );
        $userId = $this->user['id'];
        $stmt->bind_param('iiiii', $this->societyId, $userId, $userId, $limit, $offset);
        $stmt->execute();
        $calls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($calls as &$call) {
            $call['id'] = (int)$call['id'];
            $call['caller_id'] = (int)$call['caller_id'];
            $call['target_flat'] = ($call['tower_name'] ?? '') . ' - ' . ($call['flat_number'] ?? '');
            $call['duration_seconds'] = (int)($call['duration_seconds'] ?? 0);
            unset($call['tower_name'], $call['flat_number']);
        }

        ApiResponse::success(['calls' => $calls]);
    }

    /**
     * Send FCM push to all residents in the target flat.
     */
    private function notifyFlatResidents($flatId, $callerName, $callId, $channelName, $callType, $token) {
        $stmt = $this->conn->prepare(
            "SELECT u.fcm_token, u.id FROM tbl_user u
             JOIN tbl_resident r ON r.user_id = u.id
             WHERE r.flat_id = ? AND r.status = 'approved'
               AND u.fcm_token IS NOT NULL AND u.fcm_token != ''"
        );
        $stmt->bind_param('i', $flatId);
        $stmt->execute();
        $residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($residents as $res) {
            sendFCMNotification($res['fcm_token'], 'Incoming Call', "$callerName is calling", [
                'type' => 'incoming_call',
                'call_id' => (string)$callId,
                'channel_name' => $channelName,
                'caller_name' => $callerName,
                'call_type' => $callType,
                'token' => $token,
            ]);

            storeNotification($this->conn, $this->societyId, (int)$res['id'],
                'Incoming Call', "$callerName is calling your flat",
                'intercom', 'intercom_call', $callId);
        }
    }

    /**
     * Build Agora RTC token (simplified PHP implementation).
     * Uses HMAC-SHA256 based token generation compatible with Agora RTC SDK.
     */
    private function buildAgoraToken($appId, $appCertificate, $channelName, $uid, $expireSeconds) {
        $ts = time();
        $salt = rand(1, 99999999);
        $expiredTs = $ts + $expireSeconds;

        // Build token using AccessToken2 format (simplified)
        // For production, use Agora's official PHP token builder
        // This generates a basic token for development/testing
        $message = pack('V', $salt) . pack('V', $ts) . pack('V', $expiredTs);
        $content = $appId . $channelName . strval($uid) . $message;
        $signature = hash_hmac('sha256', $content, $appCertificate, true);

        // Encode as base64
        $token = '006' . $appId . base64_encode($signature . $message);

        return $token;
    }
}
