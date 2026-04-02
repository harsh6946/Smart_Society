<?php
/**
 * Simple database-based rate limiter
 */
class RateLimiter {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Check if request is allowed under rate limit
     * @return bool true if allowed, false if rate-limited
     */
    public function check($identifier, $endpoint, $maxAttempts, $windowSeconds) {
        // Probabilistic cleanup (1% chance per request)
        if (random_int(1, 100) === 1) {
            $this->cleanup($windowSeconds);
        }

        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        $stmt = $this->conn->prepare(
            "SELECT id, attempts FROM tbl_rate_limit
             WHERE identifier = ? AND endpoint = ? AND window_start > ?
             ORDER BY window_start DESC LIMIT 1"
        );
        $stmt->bind_param('sss', $identifier, $endpoint, $windowStart);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $now = date('Y-m-d H:i:s');
            $ins = $this->conn->prepare(
                "INSERT INTO tbl_rate_limit (identifier, endpoint, attempts, window_start)
                 VALUES (?, ?, 1, ?)"
            );
            $ins->bind_param('sss', $identifier, $endpoint, $now);
            $ins->execute();
            $ins->close();
            $stmt->close();
            return true;
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row['attempts'] >= $maxAttempts) {
            return false;
        }

        $upd = $this->conn->prepare(
            "UPDATE tbl_rate_limit SET attempts = attempts + 1 WHERE id = ?"
        );
        $upd->bind_param('i', $row['id']);
        $upd->execute();
        $upd->close();

        return true;
    }

    private function cleanup($windowSeconds) {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds * 2);
        $this->conn->query("DELETE FROM tbl_rate_limit WHERE window_start < '" . $this->conn->real_escape_string($cutoff) . "'");
    }
}
