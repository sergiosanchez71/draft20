<?php
/**
 * Sonda temporal de diagnóstico (IP real tras el CDN).
 * Se elimina en cuanto se determine qué cabecera usar en inc/rate_limit.php.
 */
declare(strict_types=1);

if (($_GET['k'] ?? '') !== 'd20ip-7c4m9') {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$out = [
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'server_addr' => $_SERVER['SERVER_ADDR'] ?? null,
    'headers' => [],
];
foreach ($_SERVER as $k => $v) {
    if (strncmp($k, 'HTTP_', 5) === 0) {
        $out['headers'][strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
