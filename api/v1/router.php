<?php
/**
 * Securis Smart Society Platform — API Router
 * All API requests route through this single entry point.
 * Pattern: /api/v1/{module}/{action_or_id}/{sub_action}
 */

header('Content-Type: application/json');

// CORS — allow mobile apps (no Origin) and whitelisted origins
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    $allowedOrigins = ['https://securis.iwatechnology.in'];
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Origin not allowed']);
        exit;
    }
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../include/dbconfig.php';
require_once __DIR__ . '/../../include/api_config.php';
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/ApiAuth.php';

// Validate API Key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($apiKey) || !password_verify($apiKey, API_KEY_HASH)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing API key',
        'timestamp' => date('c')
    ]);
    exit;
}

// Parse URI segments
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/api/v1/';
$path = parse_url($requestUri, PHP_URL_PATH);

// Find the base path position
$basePos = strpos($path, $basePath);
if ($basePos === false) {
    ApiResponse::notFound('Invalid API path');
}

$path = substr($path, $basePos + strlen($basePath));
$path = rtrim($path, '/');
$segments = $path ? explode('/', $path) : [];
$method = $_SERVER['REQUEST_METHOD'];

// Get request body (JSON or form data)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}
// Merge query params
$input = array_merge($input, $_GET);

$auth = new ApiAuth($conn);

// Extract route components
$module = $segments[0] ?? '';
$segment1 = $segments[1] ?? '';
$segment2 = $segments[2] ?? '';
$segment3 = $segments[3] ?? '';

// Determine if segment1 is an ID or action
$id = null;
$action = '';
$subAction = '';

if (is_numeric($segment1)) {
    $id = (int)$segment1;
    $action = $segment2;
    $subAction = $segment3;
} else {
    $action = $segment1;
    if (is_numeric($segment2)) {
        $id = (int)$segment2;
        $subAction = $segment3;
    } else {
        $subAction = $segment2;
    }
}

// Rate limit auth endpoints
if ($module === 'auth' && in_array($action, ['send-otp', 'verify-otp'])) {
    require_once __DIR__ . '/RateLimiter.php';
    $rateLimiter = new RateLimiter($conn);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!$rateLimiter->check($clientIp, $action, API_RATE_LIMIT_AUTH, API_RATE_LIMIT_WINDOW)) {
        ApiResponse::error('Too many requests. Please try again later.', 429);
    }
}

try {
    switch ($module) {
        case 'auth':
            require_once __DIR__ . '/handlers/auth/AuthHandler.php';
            $handler = new AuthHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'society':
            require_once __DIR__ . '/handlers/society/SocietyHandler.php';
            $handler = new SocietyHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'resident':
            require_once __DIR__ . '/handlers/resident/ResidentHandler.php';
            $handler = new ResidentHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'maintenance':
            require_once __DIR__ . '/handlers/maintenance/MaintenanceHandler.php';
            $handler = new MaintenanceHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'notices':
            require_once __DIR__ . '/handlers/communication/NoticeHandler.php';
            $handler = new NoticeHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'polls':
            require_once __DIR__ . '/handlers/communication/PollHandler.php';
            $handler = new PollHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'complaints':
            require_once __DIR__ . '/handlers/complaint/ComplaintHandler.php';
            $handler = new ComplaintHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'facilities':
            require_once __DIR__ . '/handlers/facility/FacilityHandler.php';
            $handler = new FacilityHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'access':
            require_once __DIR__ . '/handlers/access/AccessHandler.php';
            $handler = new AccessHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'tuya':
            require_once __DIR__ . '/handlers/tuya/TuyaHandler.php';
            $handler = new TuyaHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'visitors':
            require_once __DIR__ . '/handlers/visitor/VisitorHandler.php';
            $handler = new VisitorHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'notifications':
            require_once __DIR__ . '/handlers/notification/NotificationHandler.php';
            $handler = new NotificationHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'guard':
            require_once __DIR__ . '/handlers/guard/GuardHandler.php';
            $handler = new GuardHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'intercom':
            require_once __DIR__ . '/handlers/intercom/IntercomHandler.php';
            $handler = new IntercomHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        // =============================================
        // V2 ROUTES
        // =============================================

        case 'payment':
            require_once __DIR__ . '/handlers/payment/PaymentGatewayHandler.php';
            $handler = new PaymentGatewayHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'accounting':
            require_once __DIR__ . '/handlers/accounting/AccountingHandler.php';
            $handler = new AccountingHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'staff':
            require_once __DIR__ . '/handlers/staff/StaffHandler.php';
            $handler = new StaffHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'vendors':
            require_once __DIR__ . '/handlers/vendor/VendorHandler.php';
            $handler = new VendorHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'parking':
            require_once __DIR__ . '/handlers/parking/ParkingHandler.php';
            $handler = new ParkingHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'emergency':
            require_once __DIR__ . '/handlers/emergency/EmergencyHandler.php';
            $handler = new EmergencyHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'chat':
            require_once __DIR__ . '/handlers/chat/ChatHandler.php';
            $handler = new ChatHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'marketplace':
            require_once __DIR__ . '/handlers/marketplace/MarketplaceHandler.php';
            $handler = new MarketplaceHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'events':
            require_once __DIR__ . '/handlers/event/EventHandler.php';
            $handler = new EventHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'packages':
            require_once __DIR__ . '/handlers/package/PackageHandler.php';
            $handler = new PackageHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'pets':
            require_once __DIR__ . '/handlers/pet/PetHandler.php';
            $handler = new PetHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        case 'committee':
            require_once __DIR__ . '/handlers/committee/CommitteeHandler.php';
            $handler = new CommitteeHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'assets':
            require_once __DIR__ . '/handlers/asset/AssetHandler.php';
            $handler = new AssetHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'tenant':
            require_once __DIR__ . '/handlers/tenant/TenantHandler.php';
            $handler = new TenantHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'move':
            require_once __DIR__ . '/handlers/move/MoveHandler.php';
            $handler = new MoveHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id, $subAction);
            break;

        case 'builder':
            require_once __DIR__ . '/handlers/builder/BuilderHandler.php';
            $handler = new BuilderHandler($conn, $auth, $input);
            $handler->handle($method, $action, $id);
            break;

        default:
            ApiResponse::notFound('Endpoint not found: ' . $module);
    }
} catch (Exception $e) {
    ApiResponse::error('Internal server error', 500);
}
