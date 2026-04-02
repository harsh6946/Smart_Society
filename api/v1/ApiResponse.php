<?php

class ApiResponse {

    public static function success($data = null, $message = 'Success', $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        exit;
    }

    public static function error($message = 'Error', $code = 400, $errors = null) {
        http_response_code($code);
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('c')
        ];
        if ($errors) $response['errors'] = $errors;
        echo json_encode($response);
        exit;
    }

    public static function paginated($data, $total, $page, $perPage, $message = 'Success') {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'per_page' => (int)$perPage,
                'total_pages' => (int)ceil($total / $perPage)
            ],
            'timestamp' => date('c')
        ]);
        exit;
    }

    public static function created($data = null, $message = 'Created successfully') {
        self::success($data, $message, 201);
    }

    public static function notFound($message = 'Resource not found') {
        self::error($message, 404);
    }

    public static function unauthorized($message = 'Unauthorized') {
        self::error($message, 401);
    }

    public static function forbidden($message = 'Forbidden') {
        self::error($message, 403);
    }
}
