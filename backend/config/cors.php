<?php

declare(strict_types=1);

/**
 * CORS bootstrap. Include this as the first statement of every API entry point:
 *
 *   require_once __DIR__ . '/config/cors.php';
 *
 * Allowed origins default to the Angular dev server. Override in production with
 * CORS_ALLOWED_ORIGINS="https://app.example.ma,https://www.app.example.ma".
 */

$defaultOrigins = 'http://localhost:4200,http://127.0.0.1:4200';
$configured     = getenv('CORS_ALLOWED_ORIGINS');
$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', ($configured === false || $configured === '') ? $defaultOrigins : $configured)
)));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Echo the origin only when it is on the allow-list; never fall back to "*"
// together with credentials, which browsers reject anyway.
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin === '' && in_array('*', $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
header('Access-Control-Expose-Headers: Content-Type, Content-Length');
header('Access-Control-Max-Age: 86400');

// Every endpoint answers with strict JSON, including errors.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

// Answer the preflight immediately: no body, no framework work.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
