<?php
/**
 * Draft 20 — API: racha diaria de partidas.
 *
 * Registra y consulta los días en que un usuario ha terminado al menos una
 * partida. No hay cuentas: el user_id es un identificador anónimo generado
 * en localStorage (navegador). Si el usuario borra datos o cambia de
 * dispositivo, se pierde el historial (por eso la UI avisa).
 *
 * --- Petición POST (application/json) ---
 *   { "user_id": "uuid-v4", "fecha": "2026-11-08" }
 *
 * --- Respuesta 200 (POST) ---
 *   { "ok": true, "registrado": true, "ya_existente": false }
 *
 * --- Petición GET ---
 *   ?user_id=uuid-v4
 *
 * --- Respuesta 200 (GET) ---
 *   {
 *     "ok": true,
 *     "user_id": "...",
 *     "racha_actual": 3,
 *     "racha_maxima": 5,
 *     "jugado_hoy": true,
 *     "ultima_fecha": "2026-11-08",
 *     "total_dias": 12
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/rate_limit.php';

const DATOS_DIR     = __DIR__ . '/datos/';
const RACHA_LOG     = DATOS_DIR . 'racha.jsonl';
const LOG_MAX_BYTES = 5 * 1024 * 1024; // 5 MB → al superarlo se recorta a la mitad
const CUERPO_MAX    = 20000;
const UUID_REGEX    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
const FECHA_REGEX   = '/^\d{4}-\d{2}-\d{2}$/';

if (!function_exists('responder')) {
    function responder(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa POST o GET.'], 405);
}

api_guard_origen();
rl_guard('racha', 30, 3600); // 30 peticiones por hora por IP (fail-open en localhost)

/**
 * Lee todas las fechas únicas registradas para un user_id.
 */
function fechasDeUsuario(string $userId): array {
    $fechas = [];
    if (!is_file(RACHA_LOG)) {
        return $fechas;
    }
    $fp = @fopen(RACHA_LOG, 'rb');
    if ($fp === false) {
        return $fechas;
    }
    if (flock($fp, LOCK_SH)) {
        while (($linea = fgets($fp)) !== false) {
            $r = json_decode(trim($linea), true);
            if (is_array($r) && (string) ($r['user_id'] ?? '') === $userId) {
                $f = (string) ($r['fecha'] ?? '');
                if ($f !== '' && !in_array($f, $fechas, true)) {
                    $fechas[] = $f;
                }
            }
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    sort($fechas);
    return $fechas;
}

/**
 * Calcula la racha actual (días consecutivos hacia atrás desde la referencia)
 * y la racha máxima histórica a partir de una lista de fechas ordenadas.
 */
function calcularRachas(array $fechas, string $hoy): array {
    $ayer = date('Y-m-d', strtotime($hoy . ' -1 day'));
    $jugadoHoy = in_array($hoy, $fechas, true);

    // Racha actual: días consecutivos hacia atrás desde hoy (o ayer si no jugó hoy).
    $rachaActual = 0;
    if ($jugadoHoy || in_array($ayer, $fechas, true)) {
        $rachaActual = 1;
        $referencia = $jugadoHoy ? $hoy : $ayer;
        $cursor = $referencia;
        while (true) {
            $siguiente = date('Y-m-d', strtotime($cursor . ' -1 day'));
            if (in_array($siguiente, $fechas, true)) {
                $rachaActual++;
                $cursor = $siguiente;
            } else {
                break;
            }
        }
    }

    // Racha máxima: racha más larga de días consecutivos en todo el histórico.
    $rachaMaxima = 0;
    $rachaTmp = 0;
    $fechaPrev = null;
    foreach ($fechas as $f) {
        if ($fechaPrev === null) {
            $rachaTmp = 1;
        } else {
            $esperada = date('Y-m-d', strtotime($fechaPrev . ' +1 day'));
            if ($f === $esperada) {
                $rachaTmp++;
            } else {
                $rachaTmp = 1;
            }
        }
        $fechaPrev = $f;
        if ($rachaTmp > $rachaMaxima) {
            $rachaMaxima = $rachaTmp;
        }
    }

    return [
        'racha_actual' => (int) $rachaActual,
        'racha_maxima' => (int) $rachaMaxima,
        'jugado_hoy' => $jugadoHoy,
        'ultima_fecha' => $fechas[count($fechas) - 1] ?? '',
        'total_dias' => count($fechas),
    ];
}

// ============================================================================
// GET: consultar racha de un usuario
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $userId = isset($_GET['user_id']) && is_string($_GET['user_id']) ? trim($_GET['user_id']) : '';
    if ($userId === '' || !preg_match(UUID_REGEX, $userId)) {
        responder(['ok' => false, 'error' => 'user_id inválido.'], 400);
    }

    $fechas = fechasDeUsuario($userId);
    $hoy = date('Y-m-d');
    $rachas = calcularRachas($fechas, $hoy);

    responder([
        'ok' => true,
        'user_id' => $userId,
        'racha_actual' => $rachas['racha_actual'],
        'racha_maxima' => $rachas['racha_maxima'],
        'jugado_hoy' => $rachas['jugado_hoy'],
        'ultima_fecha' => $rachas['ultima_fecha'],
        'total_dias' => $rachas['total_dias'],
    ], 200);
}

// ============================================================================
// POST: registrar que un usuario jugó una fecha dada
// ============================================================================
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > CUERPO_MAX) {
    responder(['ok' => false, 'error' => 'Cuerpo inválido.'], 400);
}

$in = json_decode($raw, true);
if (!is_array($in)) {
    responder(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$userId = (string) ($in['user_id'] ?? '');
if ($userId === '' || !preg_match(UUID_REGEX, $userId)) {
    responder(['ok' => false, 'error' => 'user_id inválido.'], 400);
}

$fecha = (string) ($in['fecha'] ?? '');
if ($fecha === '' || !preg_match(FECHA_REGEX, $fecha)) {
    responder(['ok' => false, 'error' => 'Fecha inválida. Usa YYYY-MM-DD.'], 400);
}

// No permitir fechas futuras (margen de 1 día por desfases de huso horario).
$tsFecha = strtotime($fecha);
if ($tsFecha === false || $tsFecha > time() + 86400) {
    responder(['ok' => false, 'error' => 'Fecha no permitida.'], 400);
}

// Idempotencia: si ya existe una entrada para ese user_id y fecha, no duplicar.
$yaExistente = false;
if (is_file(RACHA_LOG)) {
    $fp = @fopen(RACHA_LOG, 'rb');
    if ($fp !== false) {
        if (flock($fp, LOCK_SH)) {
            while (($linea = fgets($fp)) !== false) {
                $r = json_decode(trim($linea), true);
                if (is_array($r)
                    && (string) ($r['user_id'] ?? '') === $userId
                    && (string) ($r['fecha'] ?? '') === $fecha
                ) {
                    $yaExistente = true;
                    break;
                }
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

if ($yaExistente) {
    responder(['ok' => true, 'registrado' => true, 'ya_existente' => true], 200);
}

if (!is_dir(DATOS_DIR)) {
    @mkdir(DATOS_DIR, 0755, true);
}

$registro = [
    'user_id' => $userId,
    'fecha' => $fecha,
    'ts' => time(),
];

$fp = @fopen(RACHA_LOG, 'c+b');
if ($fp === false) {
    responder(['ok' => false, 'error' => 'No se pudo abrir el registro de racha.'], 500);
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    responder(['ok' => false, 'error' => 'No se pudo bloquear el registro de racha.'], 500);
}

fseek($fp, 0, SEEK_END);
fwrite($fp, json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

// Rotación: si el log supera el tope, conserva la mitad más reciente.
clearstatcache(true, RACHA_LOG);
$tam = ftell($fp);
if ($tam !== false && $tam > LOG_MAX_BYTES) {
    rewind($fp);
    $todo = (string) stream_get_contents($fp);
    $lineas = array_values(array_filter(explode("\n", trim($todo)), static fn(string $l): bool => $l !== ''));
    $mitad = array_slice($lineas, intdiv(count($lineas), 2));
    ftruncate($fp, 0);
    rewind($fp);
    if ($mitad !== []) {
        fwrite($fp, implode("\n", $mitad) . "\n");
    }
}

fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

responder(['ok' => true, 'registrado' => true, 'ya_existente' => false], 200);
