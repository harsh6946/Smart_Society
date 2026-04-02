<?php

class ApiAuth {
    private $conn;
    private $user = null;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Authenticate user via Bearer token. Exits with 401 if invalid.
     */
    public function authenticate() {
        $token = $this->getBearerToken();
        if (!$token) {
            ApiResponse::unauthorized('Authorization token required');
        }

        $stmt = $this->conn->prepare(
            "SELECT u.id, u.phone, u.name, u.email, u.avatar, u.firebase_uid, u.fcm_token, u.status,
                    r.id as resident_id, r.society_id, r.flat_id, r.resident_type, r.is_primary, r.is_guard
             FROM tbl_user u
             LEFT JOIN tbl_resident r ON r.user_id = u.id AND r.status = 'approved'
             WHERE u.auth_token = ? AND u.status = 'active'
               AND (u.token_expires_at IS NULL OR u.token_expires_at > NOW())"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::unauthorized('Invalid or expired token');
        }

        $this->user = $result->fetch_assoc();
        return $this->user;
    }

    /**
     * Optional authentication — doesn't fail if no token provided.
     */
    public function authenticateOptional() {
        $token = $this->getBearerToken();
        if (!$token) return null;

        $stmt = $this->conn->prepare(
            "SELECT u.id, u.phone, u.name, u.email, u.avatar, u.fcm_token,
                    r.id as resident_id, r.society_id, r.flat_id, r.resident_type, r.is_primary, r.is_guard
             FROM tbl_user u
             LEFT JOIN tbl_resident r ON r.user_id = u.id AND r.status = 'approved'
             WHERE u.auth_token = ? AND u.status = 'active'
               AND (u.token_expires_at IS NULL OR u.token_expires_at > NOW())"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $this->user = $result->fetch_assoc();
        }
        return $this->user;
    }

    public function getUser() {
        return $this->user;
    }

    public function getUserId() {
        return $this->user ? (int)$this->user['id'] : null;
    }

    public function getSocietyId() {
        return $this->user && $this->user['society_id'] ? (int)$this->user['society_id'] : null;
    }

    public function getFlatId() {
        return $this->user && $this->user['flat_id'] ? (int)$this->user['flat_id'] : null;
    }

    public function getResidentId() {
        return $this->user && $this->user['resident_id'] ? (int)$this->user['resident_id'] : null;
    }

    public function isGuard() {
        return $this->user && $this->user['is_guard'];
    }

    /**
     * Require user to be part of a society. Exits with 403 if not.
     */
    public function requireSociety() {
        if (!$this->user || !$this->user['society_id']) {
            ApiResponse::forbidden('You must be part of a society to access this resource');
        }
        return (int)$this->user['society_id'];
    }

    /**
     * Require user to be a primary owner (admin-like permissions in app).
     */
    public function requirePrimary() {
        if (!$this->user || !$this->user['is_primary']) {
            ApiResponse::forbidden('Only primary owners can perform this action');
        }
        return true;
    }

    /**
     * Extract Bearer token from Authorization header.
     */
    private function getBearerToken() {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback for servers that don't support getallheaders()
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) === 'HTTP_') {
                    $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                    $headers[$header] = $value;
                }
            }
        }

        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
