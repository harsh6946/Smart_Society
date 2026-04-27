<?php
// Security Helper Functions

function generateToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}   

function generatePinCode($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

function generateQRCode() {
    return 'XRDA3-' . strtoupper(bin2hex(random_bytes(8)));
}

function generateInviteCode() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function validatePhone($phone) {
    return preg_match('/^[+]?[0-9]{10,15}$/', $phone);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
