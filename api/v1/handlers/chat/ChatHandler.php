<?php
/**
 * Securis Smart Society Platform — Chat Handler
 * Manages chat groups, messages, members, read receipts, and mute settings.
 */

require_once __DIR__ . '/../../../../include/helpers.php';
require_once __DIR__ . '/../../../../include/security.php';

class ChatHandler {
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

        // Route: /chat/messages/{id} (DELETE for soft delete)
        if ($action === 'messages' && $method === 'DELETE' && $id) {
            $this->deleteMessage($id);
            return;
        }

        // Route: /chat/groups/...
        if ($action === 'groups') {
            $this->handleGroups($method, $id);
            return;
        }

        ApiResponse::error('Invalid endpoint', 400);
    }

    // ─── Groups routing ─────────────────────────────────────────────────

    private function handleGroups($method, $id) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Sub-resource: /chat/groups/{id}/messages
        if ($id && strpos($uri, '/messages') !== false) {
            $this->handleGroupMessages($method, $id);
            return;
        }

        // Sub-resource: /chat/groups/{id}/read
        if ($id && strpos($uri, '/read') !== false && $method === 'POST') {
            $this->markAsRead($id);
            return;
        }

        // Sub-resource: /chat/groups/{id}/members
        if ($id && strpos($uri, '/members') !== false) {
            $this->handleGroupMembers($method, $id);
            return;
        }

        // Sub-resource: /chat/groups/{id}/mute
        if ($id && strpos($uri, '/mute') !== false && $method === 'PUT') {
            $this->toggleMute($id);
            return;
        }

        // Base groups resource
        switch ($method) {
            case 'GET':
                $this->listGroups();
                break;

            case 'POST':
                $this->createGroup();
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── GET /api/v1/chat/groups ────────────────────────────────────────

    /**
     * List chat groups the current user is a member of.
     */
    private function listGroups() {
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT g.id, g.society_id, g.name, g.type, g.tower_id, g.icon,
                    g.created_by, g.is_active, g.created_at,
                    cm.role, cm.is_muted,
                    (SELECT COUNT(*) FROM tbl_chat_member WHERE group_id = g.id) as member_count,
                    (SELECT m.content FROM tbl_chat_message m
                     WHERE m.group_id = g.id AND m.is_deleted = 0
                     ORDER BY m.created_at DESC LIMIT 1) as last_message,
                    (SELECT m.created_at FROM tbl_chat_message m
                     WHERE m.group_id = g.id AND m.is_deleted = 0
                     ORDER BY m.created_at DESC LIMIT 1) as last_message_at,
                    (SELECT COUNT(*) FROM tbl_chat_message m
                     WHERE m.group_id = g.id AND m.is_deleted = 0
                     AND m.id NOT IN (
                         SELECT rr.message_id FROM tbl_chat_read_receipt rr WHERE rr.user_id = ?
                     )
                     AND m.sender_id != ?
                    ) as unread_count
             FROM tbl_chat_group g
             INNER JOIN tbl_chat_member cm ON cm.group_id = g.id AND cm.user_id = ?
             WHERE g.society_id = ? AND g.is_active = 1
             ORDER BY last_message_at DESC, g.created_at DESC"
        );
        $stmt->bind_param('iiii', $userId, $userId, $userId, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[] = [
                'id' => (int)$row['id'],
                'society_id' => (int)$row['society_id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'tower_id' => $row['tower_id'] !== null ? (int)$row['tower_id'] : null,
                'icon' => $row['icon'],
                'created_by' => (int)$row['created_by'],
                'is_active' => (bool)$row['is_active'],
                'role' => $row['role'],
                'is_muted' => (bool)$row['is_muted'],
                'member_count' => (int)$row['member_count'],
                'last_message' => $row['last_message'],
                'last_message_at' => $row['last_message_at'],
                'unread_count' => (int)$row['unread_count'],
                'created_at' => $row['created_at'],
            ];
        }
        $stmt->close();

        ApiResponse::success($groups, 'Chat groups retrieved successfully');
    }

    // ─── POST /api/v1/chat/groups ───────────────────────────────────────

    /**
     * Create a custom chat group. Input: name, member user_ids.
     */
    private function createGroup() {
        $name = sanitizeInput($this->input['name'] ?? '');
        $memberIds = $this->input['member_ids'] ?? [];

        if (empty($name)) {
            ApiResponse::error('Group name is required', 400);
        }

        if (!is_array($memberIds) || empty($memberIds)) {
            ApiResponse::error('At least one member user_id is required', 400);
        }

        $userId = $this->auth->getUserId();

        // Insert group
        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_chat_group (society_id, name, type, created_by, is_active, created_at)
             VALUES (?, ?, 'custom', ?, 1, NOW())"
        );
        $stmt->bind_param('isi', $this->societyId, $name, $userId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to create chat group', 500);
        }

        $groupId = $stmt->insert_id;
        $stmt->close();

        // Add creator as admin member
        $memberStmt = $this->conn->prepare(
            "INSERT INTO tbl_chat_member (group_id, user_id, role, joined_at) VALUES (?, ?, 'admin', NOW())"
        );
        $memberStmt->bind_param('ii', $groupId, $userId);
        $memberStmt->execute();
        $memberStmt->close();

        // Add other members
        $addStmt = $this->conn->prepare(
            "INSERT IGNORE INTO tbl_chat_member (group_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())"
        );
        foreach ($memberIds as $memberId) {
            $memberId = (int)$memberId;
            if ($memberId !== $userId) {
                $addStmt->bind_param('ii', $groupId, $memberId);
                $addStmt->execute();
            }
        }
        $addStmt->close();

        // Fetch and return
        $fetchStmt = $this->conn->prepare(
            "SELECT id, society_id, name, type, tower_id, icon, created_by, is_active, created_at
             FROM tbl_chat_group WHERE id = ?"
        );
        $fetchStmt->bind_param('i', $groupId);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $group = [
            'id' => (int)$row['id'],
            'society_id' => (int)$row['society_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'tower_id' => $row['tower_id'] !== null ? (int)$row['tower_id'] : null,
            'icon' => $row['icon'],
            'created_by' => (int)$row['created_by'],
            'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'],
        ];

        ApiResponse::created($group, 'Chat group created successfully');
    }

    // ─── Group messages ─────────────────────────────────────────────────

    private function handleGroupMessages($method, $groupId) {
        // Verify membership
        $this->requireGroupMember($groupId);

        switch ($method) {
            case 'GET':
                $this->listMessages($groupId);
                break;

            case 'POST':
                $this->sendMessage($groupId);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── GET /api/v1/chat/groups/{id}/messages ──────────────────────────

    /**
     * Get messages for a group. Paginated, latest first. Includes sender name/avatar.
     */
    private function listMessages($groupId) {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        // Count total
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) as total FROM tbl_chat_message WHERE group_id = ? AND is_deleted = 0"
        );
        $countStmt->bind_param('i', $groupId);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch messages
        $stmt = $this->conn->prepare(
            "SELECT m.id, m.group_id, m.sender_id, m.message_type, m.content,
                    m.file_path, m.reply_to, m.is_deleted, m.created_at,
                    u.name as sender_name, u.avatar as sender_avatar,
                    rm.content as reply_to_content, ru.name as reply_to_sender_name
             FROM tbl_chat_message m
             LEFT JOIN tbl_user u ON u.id = m.sender_id
             LEFT JOIN tbl_chat_message rm ON rm.id = m.reply_to AND rm.is_deleted = 0
             LEFT JOIN tbl_user ru ON ru.id = rm.sender_id
             WHERE m.group_id = ? AND m.is_deleted = 0
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $groupId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $message = [
                'id' => (int)$row['id'],
                'group_id' => (int)$row['group_id'],
                'sender' => [
                    'id' => (int)$row['sender_id'],
                    'name' => $row['sender_name'],
                    'avatar' => $row['sender_avatar'],
                ],
                'message_type' => $row['message_type'],
                'content' => $row['content'],
                'file_path' => $row['file_path'],
                'reply_to' => $row['reply_to'] !== null ? [
                    'id' => (int)$row['reply_to'],
                    'content' => $row['reply_to_content'],
                    'sender_name' => $row['reply_to_sender_name'],
                ] : null,
                'created_at' => $row['created_at'],
            ];
            $messages[] = $message;
        }
        $stmt->close();

        ApiResponse::paginated($messages, $total, $page, $perPage, 'Messages retrieved successfully');
    }

    // ─── POST /api/v1/chat/groups/{id}/messages ─────────────────────────

    /**
     * Send a message. Supports text and image (file upload).
     */
    private function sendMessage($groupId) {
        $userId = $this->auth->getUserId();
        $content = sanitizeInput($this->input['content'] ?? '');
        $messageType = sanitizeInput($this->input['message_type'] ?? 'text');
        $replyTo = isset($this->input['reply_to']) ? (int)$this->input['reply_to'] : null;

        $allowedTypes = ['text', 'image', 'file', 'audio'];
        if (!in_array($messageType, $allowedTypes)) {
            ApiResponse::error('Invalid message type. Allowed: ' . implode(', ', $allowedTypes), 400);
        }

        $filePath = null;

        // Handle file upload for image/file/audio types
        if (in_array($messageType, ['image', 'file', 'audio'])) {
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'mp3', 'wav', 'ogg'];
                $upload = uploadFile($_FILES['file'], 'chat', $allowedExt);
                if (isset($upload['error'])) {
                    ApiResponse::error($upload['error'], 400);
                }
                $filePath = $upload['path'];
            } else {
                ApiResponse::error('File is required for ' . $messageType . ' messages', 400);
            }
        } else {
            // Text message requires content
            if (empty($content)) {
                ApiResponse::error('Message content is required', 400);
            }
        }

        // Validate reply_to if provided
        if ($replyTo) {
            $replyStmt = $this->conn->prepare(
                "SELECT id FROM tbl_chat_message WHERE id = ? AND group_id = ? AND is_deleted = 0"
            );
            $replyStmt->bind_param('ii', $replyTo, $groupId);
            $replyStmt->execute();
            if ($replyStmt->get_result()->num_rows === 0) {
                ApiResponse::error('Reply message not found', 400);
            }
            $replyStmt->close();
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_chat_message (group_id, sender_id, message_type, content, file_path, reply_to, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('iisssi', $groupId, $userId, $messageType, $content, $filePath, $replyTo);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to send message', 500);
        }

        $messageId = $stmt->insert_id;
        $stmt->close();

        // Fetch the created message
        $fetchStmt = $this->conn->prepare(
            "SELECT m.id, m.group_id, m.sender_id, m.message_type, m.content,
                    m.file_path, m.reply_to, m.is_deleted, m.created_at,
                    u.name as sender_name, u.avatar as sender_avatar
             FROM tbl_chat_message m
             LEFT JOIN tbl_user u ON u.id = m.sender_id
             WHERE m.id = ?"
        );
        $fetchStmt->bind_param('i', $messageId);
        $fetchStmt->execute();
        $row = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        $message = [
            'id' => (int)$row['id'],
            'group_id' => (int)$row['group_id'],
            'sender' => [
                'id' => (int)$row['sender_id'],
                'name' => $row['sender_name'],
                'avatar' => $row['sender_avatar'],
            ],
            'message_type' => $row['message_type'],
            'content' => $row['content'],
            'file_path' => $row['file_path'],
            'reply_to' => $row['reply_to'] !== null ? (int)$row['reply_to'] : null,
            'created_at' => $row['created_at'],
        ];

        // Send FCM to non-muted group members (excluding sender)
        $memberStmt = $this->conn->prepare(
            "SELECT u.id as user_id, u.fcm_token
             FROM tbl_chat_member cm
             INNER JOIN tbl_user u ON u.id = cm.user_id
             WHERE cm.group_id = ? AND cm.user_id != ? AND cm.is_muted = 0 AND u.status = 'active'"
        );
        $memberStmt->bind_param('ii', $groupId, $userId);
        $memberStmt->execute();
        $memberResult = $memberStmt->get_result();

        // Get group name and sender name for notification
        $groupStmt = $this->conn->prepare("SELECT name FROM tbl_chat_group WHERE id = ?");
        $groupStmt->bind_param('i', $groupId);
        $groupStmt->execute();
        $groupName = $groupStmt->get_result()->fetch_assoc()['name'] ?? 'Chat';
        $groupStmt->close();

        $senderName = $row['sender_name'] ?? 'Someone';
        $notifBody = $messageType === 'text' ? $content : 'Sent a ' . $messageType;

        $fcmTokens = [];
        while ($member = $memberResult->fetch_assoc()) {
            if (!empty($member['fcm_token'])) {
                $fcmTokens[] = $member['fcm_token'];
            }
        }
        $memberStmt->close();

        if (!empty($fcmTokens)) {
            sendBulkFCMNotification($fcmTokens, $senderName . ' in ' . $groupName, $notifBody, [
                'type' => 'chat_message',
                'group_id' => $groupId,
                'message_id' => $messageId,
            ]);
        }

        ApiResponse::created($message, 'Message sent successfully');
    }

    // ─── DELETE /api/v1/chat/messages/{id} ──────────────────────────────

    /**
     * Soft delete a message. Only the sender can delete.
     */
    private function deleteMessage($id) {
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT id, sender_id, group_id FROM tbl_chat_message WHERE id = ? AND is_deleted = 0"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Message not found');
        }

        $message = $result->fetch_assoc();
        $stmt->close();

        if ((int)$message['sender_id'] !== $userId) {
            ApiResponse::forbidden('You can only delete your own messages');
        }

        // Verify the group belongs to user's society
        $groupStmt = $this->conn->prepare(
            "SELECT id FROM tbl_chat_group WHERE id = ? AND society_id = ?"
        );
        $groupStmt->bind_param('ii', $message['group_id'], $this->societyId);
        $groupStmt->execute();
        if ($groupStmt->get_result()->num_rows === 0) {
            ApiResponse::notFound('Message not found');
        }
        $groupStmt->close();

        $deleteStmt = $this->conn->prepare(
            "UPDATE tbl_chat_message SET is_deleted = 1 WHERE id = ?"
        );
        $deleteStmt->bind_param('i', $id);

        if (!$deleteStmt->execute()) {
            ApiResponse::error('Failed to delete message', 500);
        }
        $deleteStmt->close();

        ApiResponse::success(null, 'Message deleted successfully');
    }

    // ─── POST /api/v1/chat/groups/{id}/read ─────────────────────────────

    /**
     * Mark messages as read up to the latest message. Bulk insert read receipts.
     */
    private function markAsRead($groupId) {
        $this->requireGroupMember($groupId);

        $userId = $this->auth->getUserId();

        // Get all unread message IDs in this group (not sent by current user)
        $stmt = $this->conn->prepare(
            "SELECT m.id FROM tbl_chat_message m
             WHERE m.group_id = ? AND m.is_deleted = 0 AND m.sender_id != ?
             AND m.id NOT IN (
                 SELECT rr.message_id FROM tbl_chat_read_receipt rr WHERE rr.user_id = ?
             )"
        );
        $stmt->bind_param('iii', $groupId, $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $messageIds = [];
        while ($row = $result->fetch_assoc()) {
            $messageIds[] = (int)$row['id'];
        }
        $stmt->close();

        if (!empty($messageIds)) {
            $insertStmt = $this->conn->prepare(
                "INSERT IGNORE INTO tbl_chat_read_receipt (message_id, user_id, read_at) VALUES (?, ?, NOW())"
            );
            foreach ($messageIds as $msgId) {
                $insertStmt->bind_param('ii', $msgId, $userId);
                $insertStmt->execute();
            }
            $insertStmt->close();
        }

        ApiResponse::success([
            'messages_read' => count($messageIds),
        ], 'Messages marked as read');
    }

    // ─── Group members ──────────────────────────────────────────────────

    private function handleGroupMembers($method, $groupId) {
        // Extract member user_id from URL for DELETE
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $memberUserId = null;
        if (preg_match('/\/members\/(\d+)/', $uri, $matches)) {
            $memberUserId = (int)$matches[1];
        }

        switch ($method) {
            case 'GET':
                $this->requireGroupMember($groupId);
                $this->listMembers($groupId);
                break;

            case 'POST':
                $this->addMembers($groupId);
                break;

            case 'DELETE':
                if (!$memberUserId) {
                    ApiResponse::error('Member user ID is required', 400);
                }
                $this->removeMember($groupId, $memberUserId);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ─── GET /api/v1/chat/groups/{id}/members ───────────────────────────

    /**
     * List members of a chat group.
     */
    private function listMembers($groupId) {
        $stmt = $this->conn->prepare(
            "SELECT cm.id, cm.user_id, cm.role, cm.is_muted, cm.joined_at,
                    u.name, u.avatar, u.phone
             FROM tbl_chat_member cm
             INNER JOIN tbl_user u ON u.id = cm.user_id
             WHERE cm.group_id = ?
             ORDER BY cm.role ASC, u.name ASC"
        );
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $result = $stmt->get_result();

        $members = [];
        while ($row = $result->fetch_assoc()) {
            $members[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'name' => $row['name'],
                'avatar' => $row['avatar'],
                'phone' => $row['phone'],
                'role' => $row['role'],
                'is_muted' => (bool)$row['is_muted'],
                'joined_at' => $row['joined_at'],
            ];
        }
        $stmt->close();

        ApiResponse::success($members, 'Group members retrieved successfully');
    }

    // ─── POST /api/v1/chat/groups/{id}/members ──────────────────────────

    /**
     * Add members to a custom group. Admin of group only.
     */
    private function addMembers($groupId) {
        $userId = $this->auth->getUserId();

        // Verify the group is custom type
        $groupStmt = $this->conn->prepare(
            "SELECT id, type FROM tbl_chat_group WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $groupStmt->bind_param('ii', $groupId, $this->societyId);
        $groupStmt->execute();
        $groupResult = $groupStmt->get_result();

        if ($groupResult->num_rows === 0) {
            ApiResponse::notFound('Chat group not found');
        }

        $group = $groupResult->fetch_assoc();
        $groupStmt->close();

        if ($group['type'] !== 'custom') {
            ApiResponse::error('Members can only be added to custom groups', 400);
        }

        // Verify the user is an admin of this group
        $this->requireGroupAdmin($groupId);

        $memberIds = $this->input['user_ids'] ?? [];
        if (!is_array($memberIds) || empty($memberIds)) {
            ApiResponse::error('At least one user_id is required', 400);
        }

        $addStmt = $this->conn->prepare(
            "INSERT IGNORE INTO tbl_chat_member (group_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())"
        );

        $added = 0;
        foreach ($memberIds as $memberId) {
            $memberId = (int)$memberId;
            $addStmt->bind_param('ii', $groupId, $memberId);
            $addStmt->execute();
            if ($addStmt->affected_rows > 0) {
                $added++;
            }
        }
        $addStmt->close();

        ApiResponse::success([
            'members_added' => $added,
        ], 'Members added successfully');
    }

    // ─── DELETE /api/v1/chat/groups/{id}/members/{user_id} ──────────────

    /**
     * Remove a member from a custom group. Admin of group only.
     */
    private function removeMember($groupId, $memberUserId) {
        // Verify the group is custom type
        $groupStmt = $this->conn->prepare(
            "SELECT id, type FROM tbl_chat_group WHERE id = ? AND society_id = ? AND is_active = 1"
        );
        $groupStmt->bind_param('ii', $groupId, $this->societyId);
        $groupStmt->execute();
        $groupResult = $groupStmt->get_result();

        if ($groupResult->num_rows === 0) {
            ApiResponse::notFound('Chat group not found');
        }

        $group = $groupResult->fetch_assoc();
        $groupStmt->close();

        if ($group['type'] !== 'custom') {
            ApiResponse::error('Members can only be removed from custom groups', 400);
        }

        // Verify the user is an admin of this group
        $this->requireGroupAdmin($groupId);

        // Cannot remove yourself if you are the only admin
        $userId = $this->auth->getUserId();
        if ($memberUserId === $userId) {
            ApiResponse::error('You cannot remove yourself from the group', 400);
        }

        $stmt = $this->conn->prepare(
            "DELETE FROM tbl_chat_member WHERE group_id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $groupId, $memberUserId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to remove member', 500);
        }

        if ($stmt->affected_rows === 0) {
            ApiResponse::notFound('Member not found in group');
        }
        $stmt->close();

        ApiResponse::success(null, 'Member removed successfully');
    }

    // ─── PUT /api/v1/chat/groups/{id}/mute ──────────────────────────────

    /**
     * Toggle mute for the current user in a group.
     */
    private function toggleMute($groupId) {
        $userId = $this->auth->getUserId();

        // Verify membership
        $memberStmt = $this->conn->prepare(
            "SELECT id, is_muted FROM tbl_chat_member WHERE group_id = ? AND user_id = ?"
        );
        $memberStmt->bind_param('ii', $groupId, $userId);
        $memberStmt->execute();
        $memberResult = $memberStmt->get_result();

        if ($memberResult->num_rows === 0) {
            ApiResponse::forbidden('You are not a member of this group');
        }

        $member = $memberResult->fetch_assoc();
        $memberStmt->close();

        $newMuted = $member['is_muted'] ? 0 : 1;

        $stmt = $this->conn->prepare(
            "UPDATE tbl_chat_member SET is_muted = ? WHERE group_id = ? AND user_id = ?"
        );
        $stmt->bind_param('iii', $newMuted, $groupId, $userId);

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to update mute setting', 500);
        }
        $stmt->close();

        ApiResponse::success([
            'is_muted' => (bool)$newMuted,
        ], $newMuted ? 'Group muted successfully' : 'Group unmuted successfully');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Verify the current user is a member of the specified group. Exits 403 if not.
     */
    private function requireGroupMember($groupId) {
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT cm.id FROM tbl_chat_member cm
             INNER JOIN tbl_chat_group g ON g.id = cm.group_id
             WHERE cm.group_id = ? AND cm.user_id = ? AND g.society_id = ? AND g.is_active = 1"
        );
        $stmt->bind_param('iii', $groupId, $userId, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::forbidden('You are not a member of this group');
        }
        $stmt->close();
    }

    /**
     * Verify the current user is an admin of the specified group. Exits 403 if not.
     */
    private function requireGroupAdmin($groupId) {
        $userId = $this->auth->getUserId();

        $stmt = $this->conn->prepare(
            "SELECT id FROM tbl_chat_member WHERE group_id = ? AND user_id = ? AND role = 'admin'"
        );
        $stmt->bind_param('ii', $groupId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::forbidden('Only group admins can perform this action');
        }
        $stmt->close();
    }
}
