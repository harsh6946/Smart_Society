<?php
/**
 * Securis Smart Society Platform — Guard Dashboard Handler
 * Provides guard-specific dashboard data: expected visitors, staff entries, stats.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class GuardHandler {
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
                switch ($action) {
                    case 'expected-visitors': $this->getExpectedVisitors(); break;
                    case 'recent-staff': $this->getRecentStaff(); break;
                    case 'stats': $this->getStats(); break;
                    case 'shifts': $this->getShifts(); break;
                    default: ApiResponse::notFound('Unknown guard action: ' . $action);
                }
                break;
            case 'POST':
                if ($action === 'shift') { $this->createShift(); break; }
                ApiResponse::notFound('Unknown guard action');
                break;
            case 'PUT':
                if ($id && $action === 'shifts') { $this->updateShift($id); break; }
                ApiResponse::notFound('Shift ID required');
                break;
            case 'DELETE':
                if ($id && $action === 'shifts') { $this->deleteShift($id); break; }
                ApiResponse::notFound('Shift ID required');
                break;
            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    private function getExpectedVisitors() {
        $today = date('Y-m-d');
        $stmt = $this->conn->prepare(
            "SELECT v.id, v.name, v.phone, v.visitor_type, v.purpose, v.vehicle_number,
                    v.valid_from, v.valid_until, v.status, v.photo,
                    f.flat_number, t.name AS tower_name
             FROM tbl_visitor v
             JOIN tbl_flat f ON v.flat_id = f.id
             JOIN tbl_tower t ON f.tower_id = t.id
             WHERE v.society_id = ?
               AND v.status IN ('expected', 'approved')
               AND DATE(v.valid_from) <= ?
               AND (v.valid_until IS NULL OR DATE(v.valid_until) >= ?)
             ORDER BY v.valid_from ASC
             LIMIT 50"
        );
        $stmt->bind_param('iss', $this->societyId, $today, $today);
        $stmt->execute();
        $visitors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($visitors as &$v) {
            $v['id'] = (int)$v['id'];
            $v['flat'] = $v['tower_name'] . ' - ' . $v['flat_number'];
            unset($v['flat_number'], $v['tower_name']);
        }

        ApiResponse::success(['visitors' => $visitors]);
    }

    private function getRecentStaff() {
        $stmt = $this->conn->prepare(
            "SELECT se.id, se.staff_name, se.staff_type, se.phone, se.check_in, se.check_out,
                    f.flat_number, t.name AS tower_name
             FROM tbl_staff_entry se
             LEFT JOIN tbl_flat f ON se.flat_id = f.id
             LEFT JOIN tbl_tower t ON f.tower_id = t.id
             WHERE se.society_id = ?
             ORDER BY se.check_in DESC
             LIMIT 20"
        );
        $stmt->bind_param('i', $this->societyId);
        $stmt->execute();
        $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($entries as &$e) {
            $e['id'] = (int)$e['id'];
            $e['flat'] = $e['tower_name'] && $e['flat_number']
                ? $e['tower_name'] . ' - ' . $e['flat_number']
                : null;
            unset($e['flat_number'], $e['tower_name']);
        }

        ApiResponse::success(['staff_entries' => $entries]);
    }

    private function getStats() {
        $today = date('Y-m-d');

        // Visitors today (all statuses except expired/rejected)
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as count FROM tbl_visitor
             WHERE society_id = ?
               AND DATE(created_at) = ?
               AND status NOT IN ('expired', 'rejected')"
        );
        $stmt->bind_param('is', $this->societyId, $today);
        $stmt->execute();
        $visitorsToday = (int)$stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        // Staff currently checked in (check_in today, no check_out)
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as count FROM tbl_staff_entry
             WHERE society_id = ?
               AND DATE(check_in) = ?
               AND check_out IS NULL"
        );
        $stmt->bind_param('is', $this->societyId, $today);
        $stmt->execute();
        $staffCheckedIn = (int)$stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        ApiResponse::success([
            'visitors_today' => $visitorsToday,
            'staff_checked_in' => $staffCheckedIn
        ]);
    }

    // ---- Guard Shift Management ----

    private function getShifts() {
        $date = $this->input['date'] ?? date('Y-m-d');
        $guardId = $this->input['guard_id'] ?? null;

        $where = "gs.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        if ($guardId) {
            $where .= " AND gs.guard_id = ?";
            $params[] = (int)$guardId;
            $types .= 'i';
        }

        if ($date !== 'all') {
            $where .= " AND gs.date = ?";
            $params[] = $date;
            $types .= 's';
        }

        $stmt = $this->conn->prepare(
            "SELECT gs.*, u.name AS guard_name, u.phone AS guard_phone
             FROM tbl_guard_shift gs
             JOIN tbl_user u ON gs.guard_id = u.id
             WHERE $where
             ORDER BY gs.date DESC, gs.start_time ASC
             LIMIT 50"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $shifts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($shifts as &$s) {
            $s['id'] = (int)$s['id'];
            $s['guard_id'] = (int)$s['guard_id'];
        }

        ApiResponse::success(['shifts' => $shifts]);
    }

    private function createShift() {
        $guardId = intval($this->input['guard_id'] ?? 0);
        $shiftType = $this->input['shift_type'] ?? 'morning';
        $startTime = $this->input['start_time'] ?? '06:00';
        $endTime = $this->input['end_time'] ?? '14:00';
        $date = $this->input['date'] ?? date('Y-m-d');

        if ($guardId <= 0) {
            ApiResponse::error('guard_id is required', 400);
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_guard_shift (society_id, guard_id, shift_type, start_time, end_time, date, status)
             VALUES (?, ?, ?, ?, ?, ?, 'scheduled')"
        );
        $stmt->bind_param('iissss', $this->societyId, $guardId, $shiftType, $startTime, $endTime, $date);
        $stmt->execute();
        $shiftId = $stmt->insert_id;
        $stmt->close();

        ApiResponse::success(['id' => $shiftId, 'message' => 'Shift created']);
    }

    private function updateShift($id) {
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['status'])) {
            $fields[] = 'status = ?';
            $params[] = $this->input['status'];
            $types .= 's';
        }
        if (isset($this->input['handover_notes'])) {
            $fields[] = 'handover_notes = ?';
            $params[] = $this->input['handover_notes'];
            $types .= 's';
        }
        if (isset($this->input['start_time'])) {
            $fields[] = 'start_time = ?';
            $params[] = $this->input['start_time'];
            $types .= 's';
        }
        if (isset($this->input['end_time'])) {
            $fields[] = 'end_time = ?';
            $params[] = $this->input['end_time'];
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $params[] = $id;
        $params[] = $this->societyId;
        $types .= 'ii';

        $sql = "UPDATE tbl_guard_shift SET " . implode(', ', $fields) . " WHERE id = ? AND society_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        ApiResponse::success(['message' => 'Shift updated']);
    }

    private function deleteShift($id) {
        $stmt = $this->conn->prepare(
            "DELETE FROM tbl_guard_shift WHERE id = ? AND society_id = ? AND status = 'scheduled'"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            ApiResponse::error('Cannot delete active/completed shift', 400);
        }

        ApiResponse::success(['message' => 'Shift deleted']);
    }
}
