<?php
/**
 * Draft 20 — API: estadísticas agregadas de partidas de práctica.
 *
 * Lee api/datos/partidas.jsonl (registros anónimos de partidas contra el bot)
 * y devuelve agregados por nivel, modo y versión del bot, además de la
 * distribución de agresividad del humano (precio pagado / valor del ítem).
 * El resultado se cachea en api/datos/agregado.json (se invalida por
 * mtime+tamaño del log).
 *
 * --- Petición ---
 *   GET api/estadisticas.php
 *
 * --- Respuesta 200 ---
 * {
 *   "ok": true,
 *   "partidas": 123,
 *   "porNivel": { "extremo": { "partidas": 40, "bot": 33, "humano": 5, "empate": 2, "wrBot": 0.825 } },
 *   "porModo": { "oculto": {...}, "visible": {...} },
 *   "porVersion": { "1730000000": { "partidas": 60, "wrBot": 0.783, "hasta": 1730000123 } },
 *   "humano": { "alphaMedia": 1.18, "alphaMuestras": 400, "gastoMedio": 9.4,
 *               "valorMedioHumano": 14.2, "valorMedioBot": 17.8 }
 * }
 */

declare(strict_types=1);

const DATOS_DIR    = __DIR__ . '/datos/';
const PARTIDAS_LOG = DATOS_DIR . 'partidas.jsonl';
const CACHE_PATH   = DATOS_DIR . 'agregado.json';
const MAX_LINEAS   = 20000;
const MAX_VERSIONES = 6;

if (!function_exists('responder')) {
    function responder(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('celda_vacia')) {
    function celda_vacia(): array {
        return ['partidas' => 0, 'bot' => 0, 'humano' => 0, 'empate' => 0];
    }
}

if (!function_exists('celda_sumar')) {
    function celda_sumar(array &$celda, string $resultado): void {
        $celda['partidas']++;
        if (isset($celda[$resultado])) $celda[$resultado]++;
    }
}

if (!function_exists('celda_cerrar')) {
    function celda_cerrar(array $celda): array {
        $celda['wrBot'] = $celda['partidas'] > 0 ? round($celda['bot'] / $celda['partidas'], 4) : 0.0;
        return $celda;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
}

$mtime = is_file(PARTIDAS_LOG) ? (int) filemtime(PARTIDAS_LOG) : 0;
$size  = is_file(PARTIDAS_LOG) ? (int) filesize(PARTIDAS_LOG) : 0;
$clave = $mtime . '-' . $size;

if (is_file(CACHE_PATH)) {
    $cache = json_decode((string) @file_get_contents(CACHE_PATH), true);
    if (is_array($cache) && ($cache['_clave'] ?? '') === $clave) {
        unset($cache['_clave']);
        responder(['ok' => true] + $cache, 200);
    }
}

$stats = [
    'partidas'    => 0,
    'porNivel'    => [],
    'porModo'     => ['oculto' => celda_vacia(), 'visible' => celda_vacia()],
    'porVersion'  => [],
    'humano'      => ['alphaSuma' => 0.0, 'alphaMuestras' => 0, 'gastoSuma' => 0, 'valorSuma' => 0, 'valorBotSuma' => 0],
];

$fp = is_file(PARTIDAS_LOG) ? @fopen(PARTIDAS_LOG, 'rb') : false;
if ($fp !== false) {
    if (flock($fp, LOCK_SH)) {
        $lineas = [];
        while (($linea = fgets($fp)) !== false) {
            $lineas[] = $linea;
            if (count($lineas) > MAX_LINEAS) array_shift($lineas);
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

foreach (($lineas ?? []) as $linea) {
    $r = json_decode(trim($linea), true);
    if (!is_array($r)) continue;
    $dificultad = (string) ($r['dificultad'] ?? '');
    if (!in_array($dificultad, ['facil', 'normal', 'dificil', 'extremo'], true)) continue;
    $resultado = (string) ($r['resultado'] ?? '');
    if (!in_array($resultado, ['bot', 'humano', 'empate'], true)) continue;

    $stats['partidas']++;

    if (!isset($stats['porNivel'][$dificultad])) $stats['porNivel'][$dificultad] = celda_vacia();
    celda_sumar($stats['porNivel'][$dificultad], $resultado);

    $modo = !empty($r['visibles']) ? 'visible' : 'oculto';
    celda_sumar($stats['porModo'][$modo], $resultado);

    $version = (string) ($r['version'] ?? '0');
    if ($version !== '0' && $version !== '') {
        if (!isset($stats['porVersion'][$version])) {
            $stats['porVersion'][$version] = celda_vacia() + ['hasta' => 0];
        }
        celda_sumar($stats['porVersion'][$version], $resultado);
        $ts = (int) ($r['ts'] ?? 0);
        if ($ts > $stats['porVersion'][$version]['hasta']) $stats['porVersion'][$version]['hasta'] = $ts;
    }

    $gasto = 0;
    $items = is_array($r['items'] ?? null) ? $r['items'] : [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $valor  = (int) ($it['v'] ?? 0);
        $precio = (int) ($it['c'] ?? 0);
        $por    = (string) ($it['p'] ?? '');
        if ($por === 'humano') {
            $gasto += $precio;
            if ($valor > 0 && $precio > 0) {
                $stats['humano']['alphaSuma'] += $precio / $valor;
                $stats['humano']['alphaMuestras']++;
            }
        }
    }
    $stats['humano']['gastoSuma'] += $gasto;
    $stats['humano']['valorSuma'] += (int) ($r['valorHumano'] ?? 0);
    $stats['humano']['valorBotSuma'] += (int) ($r['valorBot'] ?? 0);
}

// Cierra celdas por nivel/modo.
foreach ($stats['porNivel'] as $nivel => $celda) {
    $stats['porNivel'][$nivel] = celda_cerrar($celda);
}
foreach ($stats['porModo'] as $modo => $celda) {
    $stats['porModo'][$modo] = celda_cerrar($celda);
}

// Versiones: cierra, ordena por fecha y conserva las MAX_VERSIONES más recientes.
$versiones = [];
foreach ($stats['porVersion'] as $version => $celda) {
    $versiones[$version] = celda_cerrar($celda);
}
uasort($versiones, static fn(array $a, array $b): int => ($b['hasta'] ?? 0) <=> ($a['hasta'] ?? 0));
$stats['porVersion'] = array_slice($versiones, 0, MAX_VERSIONES, true);

$n = $stats['partidas'];
$stats['humano'] = [
    'alphaMedia'        => $stats['humano']['alphaMuestras'] > 0
        ? round($stats['humano']['alphaSuma'] / $stats['humano']['alphaMuestras'], 3) : null,
    'alphaMuestras'     => $stats['humano']['alphaMuestras'],
    'gastoMedio'        => $n > 0 ? round($stats['humano']['gastoSuma'] / $n, 2) : 0,
    'valorMedioHumano'  => $n > 0 ? round($stats['humano']['valorSuma'] / $n, 2) : 0,
    'valorMedioBot'     => $n > 0 ? round($stats['humano']['valorBotSuma'] / $n, 2) : 0,
];

// Cache best-effort (no bloquea la respuesta si falla).
try {
    if (!is_dir(DATOS_DIR)) @mkdir(DATOS_DIR, 0755, true);
    @file_put_contents(CACHE_PATH, json_encode(['_clave' => $clave] + $stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
} catch (Throwable $e) { /* ignore */ }

responder(['ok' => true] + $stats, 200);
