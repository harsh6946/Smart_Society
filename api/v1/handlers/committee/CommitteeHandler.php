<?php
/**
 * Securis Smart Society Platform -- Committee, Documents & Meetings Handler
 * Manages committee members, society documents, and meeting scheduling.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class CommitteeHandler {
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
                    case 'members':
                        $this->listMembers();
                        break;
                    case 'documents':
                        $this->listDocuments();
                        break;
                    case 'meetings':
                        if ($id) {
                            $this->getMeetingDetail($id);
                        } else {
                            $this->listMeetings();
                        }
                        break;
                    default:
                        ApiResponse::notFound('Committee endpoint not found');
                }
                break;

            case 'POST':
                switch ($action) {
                    case 'members':
                        $this->addMember();
                        break;
                    case 'documents':
                        $this->uploadDocument();
                        break;
                    case 'meetings':
                        $this->scheduleMeeting();
                        break;
                    default:
                        ApiResponse::notFound('Committee endpoint not found');
                }
                break;

            case 'PUT':
                switch ($action) {
                    case 'members':
                        if ($id) {
                            $this->updateMember($id);
                        } else {
                            ApiResponse::error('Member ID is required');
                        }
                        break;
                    case 'meetings':
                        if ($id) {
                            // Check for /cancel suffix in URI
                            $uri = $_SERVER['REQUEST_URI'] ?? '';
                            if (strpos($uri, '/cancel') !== false) {
                                $this->cancelMeeting($id);
                            } else {
                                $this->updateMeeting($id);
                            }
                        } else {
                            ApiResponse::error('Meeting ID is required');
                        }
                        break;
                    default:
                        ApiResponse::notFound('Committee endpoint not found');
                }
                break;

            case 'DELETE':
                switch ($action) {
                    case 'members':
                        if ($id) {
                            $this->removeMember($id);
                        } else {
                            ApiResponse::error('Member ID is required');
                        }
                        break;
                    case 'documents':
                        if ($id) {
                            $this->deleteDocument($id);
                        } else {
                            ApiResponse::error('Document ID is required');
                        }
                        break;
                    default:
                        ApiResponse::notFound('Committee endpoint not found');
                }
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // =========================================================================
    // Committee Members
    // =========================================================================

    /**
     * GET /committee/members
     * List active committee members with user details.
     * All authenticated society members can view.
     */
    private function listMembers() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT cm.id, cm.society_id, cm.user_id, cm.designation,
                    cm.tenure_start, cm.tenure_end, cm.is_active, cm.created_at,
                    u.name as user_name, u.phone as user_phone,
                    f.flat_number, t.name as tower_name
             FROM tbl_committee_member cm
             JOIN tbl_user u ON u.id = cm.user_id
             LEFT JOIN tbl_resident r ON r.user_id = cm.user_id AND r.status = 'approved'
             LEFT JOIN tbl_flat f ON f.id = r.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             WHERE cm.society_id = ? AND cm.is_active = 1
             ORDER BY cm.designation ASC, u.name ASC"
        );
        $stmt->bind_param('i', $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $members = [];
        while ($row = $result->fetch_assoc()) {
            $members[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'user_id' => (int)$row['user_id'],
                'user_name' => $row['user_name'],
                'user_phone' => $row['user_phone'],
                'flat_number' => $row['flat_number'],
                'tower_name' => $row['tower_name'],
                'designation' => $row['designation'],
                'tenure_start' => $row['tenure_start'],
                'tenure_end' => $row['tenure_end'],
                'is_active' => (bool)$row['is_active'],
                'created_at' => $row['created_at'],
            ];
        }

        ApiResponse::success($members, 'Committee members retrieved successfully');
    }

    /**
     * POST /committee/members
     * Add a committee member. Admin only.
     * Input: user_id, designation, tenure_start, tenure_end.
     */
    private function addMember() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $userId = intval($this->input['user_id'] ?? 0);
        $designation = sanitizeInput($this->input['designation'] ?? '');
        $tenureStart = sanitizeInput($this->input['tenure_start'] ?? '');
        $tenureEnd = sanitizeInput($this->input['tenure_end'] ?? '');

        if (!$userId) {
            ApiResponse::error('User ID is required');
        }

        if (empty($designation)) {
            ApiResponse::error('Designation is required');
        }

        // Verify user exists
        $stmt = $this->conn->prepare("SELECT id FROM tbl_user WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('User not found');
        }

        // Check if user is already an active committee member in this society
        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_committee_member
             WHERE society_id = ? AND user_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $societyId, $userId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            ApiResponse::error('This user is already an active committee member');
        }

        $tenureStartVal = !empty($tenureStart) ? $tenureStart : null;
        $tenureEndVal = !empty($tenureEnd) ? $tenureEnd : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_committee_member (society_id, user_id, designation, tenure_start, tenure_end, is_active)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->bind_param('iisss', $societyId, $userId, $designation, $tenureStartVal, $tenureEndVal);
        $stmt->execute();
        $memberId = $this->conn->insert_id;

        // Fetch created member with user details
        $stmt = $this->conn->prepare(
            "SELECT cm.id, cm.society_id, cm.user_id, cm.designation,
                    cm.tenure_start, cm.tenure_end, cm.is_active, cm.created_at,
                    u.name as user_name, u.phone as user_phone
             FROM tbl_committee_member cm
             JOIN tbl_user u ON u.id = cm.user_id
             WHERE cm.id = ?"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        ApiResponse::created([
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'user_id' => (int)$row['user_id'],
            'user_name' => $row['user_name'],
            'user_phone' => $row['user_phone'],
            'designation' => $row['designation'],
            'tenure_start' => $row['tenure_start'],
            'tenure_end' => $row['tenure_end'],
            'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'],
        ], 'Committee member added successfully');
    }

    /**
     * PUT /committee/members/{id}
     * Update committee member. Admin only.
     */
    private function updateMember($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify member exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id, designation, tenure_start, tenure_end
             FROM tbl_committee_member
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Committee member not found');
        }

        $member = $result->fetch_assoc();

        $designation = sanitizeInput($this->input['designation'] ?? $member['designation']);
        $tenureStart = sanitizeInput($this->input['tenure_start'] ?? $member['tenure_start']);
        $tenureEnd = sanitizeInput($this->input['tenure_end'] ?? $member['tenure_end']);

        if (empty($designation)) {
            ApiResponse::error('Designation is required');
        }

        $tenureStartVal = !empty($tenureStart) ? $tenureStart : null;
        $tenureEndVal = !empty($tenureEnd) ? $tenureEnd : null;

        $stmt = $this->conn->prepare(
            "UPDATE tbl_committee_member
             SET designation = ?, tenure_start = ?, tenure_end = ?
             WHERE id = ?"
        );
        $stmt->bind_param('sssi', $designation, $tenureStartVal, $tenureEndVal, $id);
        $stmt->execute();

        ApiResponse::success([
            'id' => (int)$id,
            'designation' => $designation,
            'tenure_start' => $tenureStartVal,
            'tenure_end' => $tenureEndVal,
        ], 'Committee member updated successfully');
    }

    /**
     * DELETE /committee/members/{id}
     * Remove committee member (soft delete: set is_active=0). Admin only.
     */
    private function removeMember($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify member exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id, is_active FROM tbl_committee_member
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Committee member not found');
        }

        $member = $result->fetch_assoc();

        if (!$member['is_active']) {
            ApiResponse::success(null, 'Committee member is already removed');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_committee_member SET is_active = 0 WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();

        ApiResponse::success(null, 'Committee member removed successfully');
    }

    // =========================================================================
    // Society Documents
    // =========================================================================

    /**
     * GET /committee/documents
     * List society documents. Public docs visible to all; private docs admin only.
     * Filter by category. Paginated.
     */
    private function listDocuments() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $user = $this->auth->getUser();
        $isPrimary = !empty($user['is_primary']);

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $category = sanitizeInput($this->input['category'] ?? '');

        $where = "WHERE d.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        // Non-admin users can only see public documents
        if (!$isPrimary) {
            $where .= " AND d.is_public = 1";
        }

        if (!empty($category)) {
            $allowedCategories = ['rules', 'bylaws', 'circular', 'minutes', 'financial', 'other'];
            if (in_array($category, $allowedCategories)) {
                $where .= " AND d.category = ?";
                $params[] = $category;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_society_document d $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated documents
        $sql = "SELECT d.id, d.society_id, d.title, d.category, d.file_path,
                       d.file_size, d.uploaded_by, d.is_public, d.created_at,
                       u.name as uploaded_by_name
                FROM tbl_society_document d
                LEFT JOIN tbl_user u ON u.id = d.uploaded_by
                $where
                ORDER BY d.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $documents = [];
        while ($row = $result->fetch_assoc()) {
            $documents[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'title' => $row['title'],
                'category' => $row['category'],
                'file_path' => $row['file_path'],
                'file_size' => (int)$row['file_size'],
                'uploaded_by' => (int)$row['uploaded_by'],
                'uploaded_by_name' => $row['uploaded_by_name'],
                'is_public' => (bool)$row['is_public'],
                'created_at' => $row['created_at'],
            ];
        }

        ApiResponse::paginated($documents, $total, $page, $perPage, 'Documents retrieved successfully');
    }

    /**
     * POST /committee/documents
     * Upload a society document. Admin only.
     * Input: title, category, is_public, file (upload).
     */
    private function uploadDocument() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $title = sanitizeInput($this->input['title'] ?? '');
        $category = sanitizeInput($this->input['category'] ?? 'other');
        $isPublic = isset($this->input['is_public']) ? (int)(bool)$this->input['is_public'] : 1;

        if (empty($title)) {
            ApiResponse::error('Document title is required');
        }

        $allowedCategories = ['rules', 'bylaws', 'circular', 'minutes', 'financial', 'other'];
        if (!in_array($category, $allowedCategories)) {
            ApiResponse::error('Invalid category. Allowed: ' . implode(', ', $allowedCategories));
        }

        // Handle file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            ApiResponse::error('Document file is required');
        }

        $upload = uploadFile($_FILES['file'], 'documents', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png']);
        if (isset($upload['error'])) {
            ApiResponse::error($upload['error']);
        }

        $filePath = $upload['path'];
        $fileSize = $_FILES['file']['size'];
        $uploadedBy = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_society_document (society_id, title, category, file_path, file_size, uploaded_by, is_public)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssiis', $societyId, $title, $category, $filePath, $fileSize, $uploadedBy, $isPublic);
        $stmt->execute();
        $docId = $this->conn->insert_id;

        ApiResponse::created([
            'id' => $docId,
            'society_id' => $societyId,
            'title' => $title,
            'category' => $category,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'uploaded_by' => $uploadedBy,
            'is_public' => (bool)$isPublic,
        ], 'Document uploaded successfully');
    }

    /**
     * DELETE /committee/documents/{id}
     * Delete a society document. Admin only.
     */
    private function deleteDocument($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify document exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id, file_path FROM tbl_society_document
             WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Document not found');
        }

        $doc = $result->fetch_assoc();

        // Delete file from filesystem if it exists
        if (!empty($doc['file_path']) && file_exists($doc['file_path'])) {
            unlink($doc['file_path']);
        }

        $stmt = $this->conn->prepare(
            "DELETE FROM tbl_society_document WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();

        ApiResponse::success(null, 'Document deleted successfully');
    }

    // =========================================================================
    // Meetings
    // =========================================================================

    /**
     * GET /committee/meetings
     * List meetings. Paginated. Filter by meeting_type, status.
     */
    private function listMeetings() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $meetingType = sanitizeInput($this->input['meeting_type'] ?? '');
        $status = sanitizeInput($this->input['status'] ?? '');

        $where = "WHERE m.society_id = ?";
        $params = [$societyId];
        $types = 'i';

        if (!empty($meetingType)) {
            $allowedTypes = ['agm', 'sgm', 'committee', 'emergency'];
            if (in_array($meetingType, $allowedTypes)) {
                $where .= " AND m.meeting_type = ?";
                $params[] = $meetingType;
                $types .= 's';
            }
        }

        if (!empty($status)) {
            $allowedStatuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];
            if (in_array($status, $allowedStatuses)) {
                $where .= " AND m.status = ?";
                $params[] = $status;
                $types .= 's';
            }
        }

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM tbl_meeting m $where";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Fetch paginated meetings
        $sql = "SELECT m.id, m.society_id, m.title, m.description, m.meeting_type,
                       m.meeting_date, m.venue, m.status, m.created_by, m.created_at,
                       u.name as created_by_name
                FROM tbl_meeting m
                LEFT JOIN tbl_user u ON u.id = m.created_by
                $where
                ORDER BY m.meeting_date DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $meetings = [];
        while ($row = $result->fetch_assoc()) {
            $meetings[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'meeting_type' => $row['meeting_type'],
                'meeting_date' => $row['meeting_date'],
                'venue' => $row['venue'],
                'status' => $row['status'],
                'created_by' => (int)$row['created_by'],
                'created_by_name' => $row['created_by_name'],
                'created_at' => $row['created_at'],
            ];
        }

        ApiResponse::paginated($meetings, $total, $page, $perPage, 'Meetings retrieved successfully');
    }

    /**
     * GET /committee/meetings/{id}
     * Meeting detail with agenda, minutes, and attendees.
     */
    private function getMeetingDetail($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();

        $stmt = $this->conn->prepare(
            "SELECT m.id, m.society_id, m.title, m.description, m.meeting_type,
                    m.meeting_date, m.venue, m.agenda, m.minutes, m.minutes_file,
                    m.attendees_json, m.status, m.created_by, m.created_at,
                    u.name as created_by_name
             FROM tbl_meeting m
             LEFT JOIN tbl_user u ON u.id = m.created_by
             WHERE m.id = ? AND m.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Meeting not found');
        }

        $row = $result->fetch_assoc();

        $attendees = null;
        if (!empty($row['attendees_json'])) {
            $attendees = json_decode($row['attendees_json'], true);
        }

        $meeting = [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'meeting_type' => $row['meeting_type'],
            'meeting_date' => $row['meeting_date'],
            'venue' => $row['venue'],
            'agenda' => $row['agenda'],
            'minutes' => $row['minutes'],
            'minutes_file' => $row['minutes_file'],
            'attendees' => $attendees,
            'status' => $row['status'],
            'created_by' => (int)$row['created_by'],
            'created_by_name' => $row['created_by_name'],
            'created_at' => $row['created_at'],
        ];

        ApiResponse::success($meeting, 'Meeting detail retrieved successfully');
    }

    /**
     * POST /committee/meetings
     * Schedule a new meeting. Admin only.
     * Input: title, description, meeting_type, meeting_date, venue, agenda.
     */
    private function scheduleMeeting() {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        $title = sanitizeInput($this->input['title'] ?? '');
        $description = sanitizeInput($this->input['description'] ?? '');
        $meetingType = sanitizeInput($this->input['meeting_type'] ?? 'committee');
        $meetingDate = sanitizeInput($this->input['meeting_date'] ?? '');
        $venue = sanitizeInput($this->input['venue'] ?? '');
        $agenda = sanitizeInput($this->input['agenda'] ?? '');

        if (empty($title)) {
            ApiResponse::error('Meeting title is required');
        }

        if (empty($meetingDate)) {
            ApiResponse::error('Meeting date is required');
        }

        $allowedTypes = ['agm', 'sgm', 'committee', 'emergency'];
        if (!in_array($meetingType, $allowedTypes)) {
            ApiResponse::error('Invalid meeting type. Allowed: ' . implode(', ', $allowedTypes));
        }

        $createdBy = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_meeting (society_id, title, description, meeting_type, meeting_date, venue, agenda, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)"
        );
        $stmt->bind_param('issssssi', $societyId, $title, $description, $meetingType, $meetingDate, $venue, $agenda, $createdBy);
        $stmt->execute();
        $meetingId = $this->conn->insert_id;

        ApiResponse::created([
            'id' => $meetingId,
            'society_id' => $societyId,
            'title' => $title,
            'description' => $description,
            'meeting_type' => $meetingType,
            'meeting_date' => $meetingDate,
            'venue' => $venue,
            'agenda' => $agenda,
            'status' => 'scheduled',
            'created_by' => $createdBy,
        ], 'Meeting scheduled successfully');
    }

    /**
     * PUT /committee/meetings/{id}
     * Update meeting details. Admin only.
     * Can update title, description, meeting_date, venue, agenda, minutes, minutes_file,
     * attendees_json, status.
     */
    private function updateMeeting($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify meeting exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_meeting WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Meeting not found');
        }

        $existing = $result->fetch_assoc();

        if ($existing['status'] === 'cancelled') {
            ApiResponse::error('Cannot update a cancelled meeting');
        }

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['title'])) {
            $fields[] = 'title = ?';
            $params[] = sanitizeInput($this->input['title']);
            $types .= 's';
        }

        if (isset($this->input['description'])) {
            $fields[] = 'description = ?';
            $params[] = sanitizeInput($this->input['description']);
            $types .= 's';
        }

        if (isset($this->input['meeting_type'])) {
            $meetingType = sanitizeInput($this->input['meeting_type']);
            $allowedTypes = ['agm', 'sgm', 'committee', 'emergency'];
            if (!in_array($meetingType, $allowedTypes)) {
                ApiResponse::error('Invalid meeting type. Allowed: ' . implode(', ', $allowedTypes));
            }
            $fields[] = 'meeting_type = ?';
            $params[] = $meetingType;
            $types .= 's';
        }

        if (isset($this->input['meeting_date'])) {
            $fields[] = 'meeting_date = ?';
            $params[] = sanitizeInput($this->input['meeting_date']);
            $types .= 's';
        }

        if (isset($this->input['venue'])) {
            $fields[] = 'venue = ?';
            $params[] = sanitizeInput($this->input['venue']);
            $types .= 's';
        }

        if (isset($this->input['agenda'])) {
            $fields[] = 'agenda = ?';
            $params[] = sanitizeInput($this->input['agenda']);
            $types .= 's';
        }

        if (isset($this->input['minutes'])) {
            $fields[] = 'minutes = ?';
            $params[] = sanitizeInput($this->input['minutes']);
            $types .= 's';
        }

        if (isset($this->input['status'])) {
            $status = sanitizeInput($this->input['status']);
            $allowedStatuses = ['scheduled', 'in_progress', 'completed'];
            if (!in_array($status, $allowedStatuses)) {
                ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses));
            }
            $fields[] = 'status = ?';
            $params[] = $status;
            $types .= 's';
        }

        if (isset($this->input['attendees_json'])) {
            $attendeesJson = $this->input['attendees_json'];
            if (is_array($attendeesJson)) {
                $attendeesJson = json_encode($attendeesJson);
            }
            $fields[] = 'attendees_json = ?';
            $params[] = $attendeesJson;
            $types .= 's';
        }

        // Handle minutes file upload
        if (isset($_FILES['minutes_file']) && $_FILES['minutes_file']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['minutes_file'], 'minutes', ['pdf', 'doc', 'docx']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error']);
            }
            $fields[] = 'minutes_file = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update');
        }

        $sql = "UPDATE tbl_meeting SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // Fetch updated meeting
        $stmt = $this->conn->prepare(
            "SELECT m.id, m.society_id, m.title, m.description, m.meeting_type,
                    m.meeting_date, m.venue, m.agenda, m.minutes, m.minutes_file,
                    m.attendees_json, m.status, m.created_by, m.created_at,
                    u.name as created_by_name
             FROM tbl_meeting m
             LEFT JOIN tbl_user u ON u.id = m.created_by
             WHERE m.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $attendees = null;
        if (!empty($row['attendees_json'])) {
            $attendees = json_decode($row['attendees_json'], true);
        }

        ApiResponse::success([
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'meeting_type' => $row['meeting_type'],
            'meeting_date' => $row['meeting_date'],
            'venue' => $row['venue'],
            'agenda' => $row['agenda'],
            'minutes' => $row['minutes'],
            'minutes_file' => $row['minutes_file'],
            'attendees' => $attendees,
            'status' => $row['status'],
            'created_by' => (int)$row['created_by'],
            'created_by_name' => $row['created_by_name'],
            'created_at' => $row['created_at'],
        ], 'Meeting updated successfully');
    }

    /**
     * PUT /committee/meetings/{id}/cancel
     * Cancel a meeting. Admin only.
     */
    private function cancelMeeting($id) {
        $this->auth->authenticate();
        $societyId = $this->auth->requireSociety();
        $this->auth->requirePrimary();

        // Verify meeting exists and belongs to this society
        $stmt = $this->conn->prepare(
            "SELECT id, status FROM tbl_meeting WHERE id = ? AND society_id = ?"
        );
        $stmt->bind_param('ii', $id, $societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Meeting not found');
        }

        $meeting = $result->fetch_assoc();

        if ($meeting['status'] === 'cancelled') {
            ApiResponse::success(null, 'Meeting is already cancelled');
        }

        if ($meeting['status'] === 'completed') {
            ApiResponse::error('Cannot cancel a completed meeting');
        }

        $stmt = $this->conn->prepare(
            "UPDATE tbl_meeting SET status = 'cancelled' WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();

        ApiResponse::success(null, 'Meeting cancelled successfully');
    }
}
