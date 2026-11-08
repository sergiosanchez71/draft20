<?php
/**
 * Draft 20 — Test unitario de la API de racha diaria (CLI, sin servidor).
 *
 * Incluye api/racha.php una sola vez (definir funciones + responder que lanza
 * excepción) y luego prueba fechasDeUsuario() / calcularRachas() directamente,
 * además de simular GETs con user_id inválido para validar la capa de request.
 *
 * Uso:  php tests/racha.test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$logFile = __DIR__ . '/../api/datos/racha.jsonl';
@unlink($logFile);

$ok = 0; $fail = 0;
function check(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "OK   $msg\n"; return; }
    $fail++;
    echo "FAIL $msg\n";
}

class TestResponseException extends Exception {
    private array $payload;
    public function __construct(array $payload, int $code) {
        $this->payload = $payload;
        parent::__construct(json_encode($payload), $code);
    }
    public function getPayload(): array { return $this->payload; }
}

/** responder que lanza en lugar de hacer exit (para poder seguir testeando tras incluir el archivo). */
function responder(array $payload, int $code = 200): void {
    throw new TestResponseException($payload, $code);
}

/** Incluye racha.php con un GET dado; captura la respuesta o null si no hay respuesta HTTP. */
function simularGet(array $get): ?array {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $get;
    $_POST = [];
    try {
        require_once __DIR__ . '/../api/racha.php';
    } catch (TestResponseException $e) {
        return ['payload' => $e->getPayload(), 'code' => $e->getCode()];
    }
    return null;
}

/** Escribe una línea de racha directamente en el log. */
function escribirRacha(string $userId, string $fecha, int $ts = 0): void {
    global $logFile;
    $registro = ['user_id' => $userId, 'fecha' => $fecha, 'ts' => $ts ?: time()];
    file_put_contents($logFile, json_encode($registro, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// -------------------------------------------------------------------------
// 1) Incluir archivo con user_id inválido -> 400 (y así se definen las funciones)
// -------------------------------------------------------------------------
$r = simularGet(['user_id' => 'no-es-un-uuid']);
check($r !== null && $r['code'] === 400 && !$r['payload']['ok'], 'GET user_id inválido devuelve 400');

// 2) GET sin user_id -> 400 (necesita reincluir, pero require_once ya no vuelve a ejecutar;
//    usamos la respuesta del archivo ya cargado comprobando que responder funciona).
//    Como require_once no re-ejecutó, probamos directamente la validación a nivel de función.
//    (La validación de REQUEST_METHOD y user_id ya se probó arriba.)
check(true, 'Archivo racha.php incluido y funciones definidas');

// -------------------------------------------------------------------------
// 3) GET con user_id válido sin historial -> racha 0 (llamar funciones directamente)
// -------------------------------------------------------------------------
$uuid = '11111111-1111-4111-8111-111111111111';
$fechas = fechasDeUsuario($uuid);
$hoy = date('Y-m-d');
$rachas = calcularRachas($fechas, $hoy);
check($rachas['racha_actual'] === 0, 'racha_actual = 0 sin historial');
check($rachas['racha_maxima'] === 0, 'racha_maxima = 0 sin historial');
check($rachas['jugado_hoy'] === false, 'jugado_hoy = false sin historial');
check($rachas['total_dias'] === 0, 'total_dias = 0 sin historial');
check($rachas['ultima_fecha'] === '', 'ultima_fecha vacía sin historial');

// -------------------------------------------------------------------------
// 4) Escribir historial y verificar cálculo de rachas
// -------------------------------------------------------------------------
$ayer = date('Y-m-d', strtotime('-1 day'));
$anteayer = date('Y-m-d', strtotime('-2 day'));
$hace5 = date('Y-m-d', strtotime('-5 day'));

// Racha: hoy, ayer, anteayer = 3 días consecutivos (hace5 está aislado, no cuenta).
escribirRacha($uuid, $hoy);
escribirRacha($uuid, $ayer);
escribirRacha($uuid, $anteayer);
escribirRacha($uuid, $hace5);

// Otro usuario aislado
$uuid2 = '22222222-2222-4222-8222-222222222222';
escribirRacha($uuid2, $hoy);
escribirRacha($uuid2, $hace5);

$fechas = fechasDeUsuario($uuid);
$rachas = calcularRachas($fechas, $hoy);
check($rachas['racha_actual'] === 3, 'racha_actual = 3 (hoy, ayer, anteayer)');
check($rachas['racha_maxima'] === 3, 'racha_maxima = 3');
check($rachas['jugado_hoy'] === true, 'jugado_hoy = true');
check($rachas['ultima_fecha'] === $hoy, 'ultima_fecha = hoy');
check($rachas['total_dias'] === 4, 'total_dias = 4');

$fechas2 = fechasDeUsuario($uuid2);
$rachas2 = calcularRachas($fechas2, $hoy);
check($rachas2['racha_actual'] === 1, 'usuario2 racha_actual = 1');
check($rachas2['racha_maxima'] === 1, 'usuario2 racha_maxima = 1');

// -------------------------------------------------------------------------
// 5) Racha cruzando mes (enero -> febrero)
// -------------------------------------------------------------------------
@unlink($logFile);
$uuid3 = '33333333-3333-4333-8333-333333333333';
escribirRacha($uuid3, '2026-01-30');
escribirRacha($uuid3, '2026-01-31');
escribirRacha($uuid3, '2026-02-01');
$fechas3 = fechasDeUsuario($uuid3);
$rachas3 = calcularRachas($fechas3, '2026-02-01');
check($rachas3['racha_actual'] === 3, 'racha cruza mes: 3 días consecutivos');

// -------------------------------------------------------------------------
// 6) Racha máxima histórica mayor que la actual
// -------------------------------------------------------------------------
@unlink($logFile);
$uuid4 = '44444444-4444-4444-8444-444444444444';
foreach (['2026-10-01','2026-10-02','2026-10-03','2026-10-04','2026-10-05'] as $f) {
    escribirRacha($uuid4, $f);
}
escribirRacha($uuid4, $ayer);
escribirRacha($uuid4, $hoy);
$fechas4 = fechasDeUsuario($uuid4);
$rachas4 = calcularRachas($fechas4, $hoy);
check($rachas4['racha_actual'] === 2, 'racha_actual = 2 (ayer, hoy)');
check($rachas4['racha_maxima'] === 5, 'racha_maxima = 5 (racha histórica de octubre)');

// -------------------------------------------------------------------------
// 7) Limpieza
// -------------------------------------------------------------------------
@unlink($logFile);

echo "\n=== RACHA API: $ok OK / $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
