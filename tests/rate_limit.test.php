<?php
/**
 * Draft 20 — Test unitario del rate limiter (CLI, sin servidor).
 *
 * Uso:  php tests/rate_limit.test.php
 *
 * Usa una IP de TEST-NET-3 (nunca loopback) para que el limiter no exima la
 * petición, y limpia al final el fichero del bucket de prueba.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

require_once __DIR__ . '/../inc/rate_limit.php';

$ok = 0; $fail = 0;
function check(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "OK   $msg\n"; return; }
    $fail++;
    echo "FAIL $msg\n";
}

$bucket = 'test' . getmypid();
$dir = __DIR__ . '/../api/datos/rl/';
$file = $dir . $bucket . '_' . substr(hash('sha256', '203.0.113.7'), 0, 24) . '.json';
@unlink($file);

// 1) Permite hasta el máximo y corta la siguiente.
for ($i = 1; $i <= 3; $i++) {
    check(rl_permitir($bucket, 3, 60) === true, "permite la petición $i de 3");
}
check(rl_permitir($bucket, 3, 60) === false, 'corta la 4ª con límite 3');

// 2) La ventana caducada se reinicia.
$data = json_decode((string) @file_get_contents($file), true);
$data['inicio'] = time() - 120;
file_put_contents($file, (string) json_encode($data));
check(rl_permitir($bucket, 3, 60) === true, 'ventana caducada: vuelve a permitir');

// 3) El loopback está exento (tests y desarrollo con php -S).
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$ultimo = false;
for ($i = 0; $i < 10; $i++) {
    $ultimo = rl_permitir($bucket, 1, 60);
}
check($ultimo === true, 'loopback exento del límite');

// 4) Distinta IP, mismo bucket: contador independiente.
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
check(rl_permitir($bucket, 2, 60) === true, 'otra IP no hereda el contador');
$file2 = $dir . $bucket . '_' . substr(hash('sha256', '203.0.113.8'), 0, 24) . '.json';
@unlink($file);
@unlink($file2);

echo "\n=== RATE LIMIT: $ok OK / $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
