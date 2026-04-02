<?php
// General Helper Functions

function getPage($input) {
    return max(1, intval($input['page'] ?? 1));
}

function getPerPage($input, $default = 20, $max = 100) {
    return min($max, max(1, intval($input['per_page'] ?? $default)));
}

function getOffset($page, $perPage) {
    return ($page - 1) * $perPage;
}

function uploadFile($file, $directory, $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'webp']) {
    $uploadDir = __DIR__ . '/../uploads/' . $directory . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) {
        return ['error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedTypes)];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['error' => 'File too large (max 5MB)'];
    }

    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['path' => 'uploads/' . $directory . '/' . $filename];
    }

    return ['error' => 'Upload failed'];
}

function uploadMultipleFiles($files, $directory, $maxFiles = 5) {
    $uploaded = [];
    $fileCount = is_array($files['name']) ? count($files['name']) : 0;

    if ($fileCount > $maxFiles) {
        return ['error' => "Maximum $maxFiles files allowed"];
    }

    for ($i = 0; $i < $fileCount; $i++) {
        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];

        if ($file['error'] === UPLOAD_ERR_OK) {
            $result = uploadFile($file, $directory);
            if (isset($result['path'])) {
                $uploaded[] = $result['path'];
            }
        }
    }

    return ['paths' => $uploaded];
}

function sendFCMNotification($fcmToken, $title, $body, $data = []) {
    if (empty($fcmToken) || $fcmToken === 'null') return false;

    $serverKey = defined('FCM_SERVER_KEY') ? FCM_SERVER_KEY : '';
    if (empty($serverKey)) return false;

    $payload = [
        'to' => $fcmToken,
        'notification' => [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
        ],
        'data' => $data,
        'priority' => 'high',
    ];

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    return $result !== false;
}

function sendBulkFCMNotification($fcmTokens, $title, $body, $data = []) {
    foreach ($fcmTokens as $token) {
        sendFCMNotification($token, $title, $body, $data);
    }
    return true;
}

function storeNotification($conn, $societyId, $userId, $title, $body, $type = 'general', $refType = null, $refId = null) {
    $stmt = $conn->prepare(
        "INSERT INTO tbl_notification (society_id, user_id, title, body, type, reference_type, reference_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iissssi', $societyId, $userId, $title, $body, $type, $refType, $refId);
    $stmt->execute();
    return $stmt->insert_id;
}

function formatDate($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}
