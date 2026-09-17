<?php
/**
 * Draft 20 — API: registrar partida de práctica (anónima).
 *
 * Guarda una línea JSON por partida terminada CONTRA EL BOT. No acepta
 * partidas entre humanos. Solo se almacenan movimientos y resultado:
 * temática, nivel, modo, versión del bot, valores y detalle por ítem.
 * Nunca nombres, IDs de jugador ni códigos de sala.
 *
 * --- Petición (POST, application/json) ---
 * {
 *   "version": "1730000000",              // mtime de bot_policy(.min).js
 *   "tematica": "hamburguesa",
 *   "dificultad": "extremo",              // facil | normal | dificil | extremo
 *   "visibles": false,                    // modo "⭐ Valores visibles"
 *   "resultado": "bot",                   // bot | humano | empate
 *   "valorHumano": 14,
 *   "valorBot": 19,
 *   "items": [ { "valor": 10, "por": "bot", "precio": 12 }, ... ]
 * }
 *
 * --- Respuesta 200 ---
 *   { "ok": true }
 *
 * --- Respuestas de error ---
 *   400 → datos inválidos
 *   405 → método no permitido
 *   500 → error interno
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/rate_limit.php';

const DATOS_DIR     = __DIR__ . '/datos/';
const PARTIDAS_LOG  = DATOS_DIR . 'partidas.jsonl';
const LOG_MAX_BYTES = 5 * 1024 * 1024; // 5 MB → al superarlo se recorta a la mitad
const CUERPO_MAX    = 20000;
const MAX_ITEMS     = 16;
const NIVELES       = ['facil', 'normal', 'dificil', 'extremo'];
const RESULTADOS    = ['bot', 'humano', 'empate'];
const POR_ITEM      = ['bot', 'humano'];

if (!function_exists('responder')) {
    function responder(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
}

api_guard_origen();
rl_guard('registrar', 60, 3600);

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > CUERPO_MAX) {
    responder(['ok' => false, 'error' => 'Cuerpo inválido.'], 400);
}

$in = json_decode($raw, true);
if (!is_array($in)) {
    responder(['ok' => false, 'error' => 'JSON inválido.'], 400);
}

$dificultad = (string) ($in['dificultad'] ?? '');
if (!in_array($dificultad, NIVELES, true)) {
    responder(['ok' => false, 'error' => 'Dificultad inválida. Solo partidas contra el bot.'], 400);
}

$resultado = (string) ($in['resultado'] ?? '');
if (!in_array($resultado, RESULTADOS, true)) {
    responder(['ok' => false, 'error' => 'Resultado inválido.'], 400);
}

$tematica = strtolower((string) ($in['tematica'] ?? ''));
$tematica = (string) preg_replace('/[^a-z0-9_]/', '', $tematica);
if ($tematica === '' || strlen($tematica) > 40) $tematica = 'desconocida';

$version = (string) preg_replace('/[^0-9]/', '', (string) ($in['version'] ?? '0'));
if ($version === '' || strlen($version) > 12) $version = '0';

$valorHumano = max(0, min(99, (int) ($in['valorHumano'] ?? 0)));
$valorBot    = max(0, min(99, (int) ($in['valorBot'] ?? 0)));

$items = [];
if (is_array($in['items'] ?? null)) {
    foreach (array_slice($in['items'], 0, MAX_ITEMS) as $it) {
        if (!is_array($it)) continue;
        $valor = (int) ($it['valor'] ?? 0);
        if ($valor < 1 || $valor > 10) continue;
        $por = (string) ($it['por'] ?? '');
        if (!in_array($por, POR_ITEM, true)) $por = '';
        $precio = max(0, min(99, (int) ($it['precio'] ?? 0)));
        $items[] = ['v' => $valor, 'p' => $por, 'c' => $precio];
    }
}
if (count($items) < 2) {
    responder(['ok' => false, 'error' => 'Faltan ítems.'], 400);
}

$registro = [
    'ts'          => time(),
    'version'     => $version,
    'tematica'    => $tematica,
    'dificultad'  => $dificultad,
    'visibles'    => !empty($in['visibles']) ? 1 : 0,
    'resultado'   => $resultado,
    'valorHumano' => $valorHumano,
    'valorBot'    => $valorBot,
    'items'       => $items,
];

if (!is_dir(DATOS_DIR)) {
    @mkdir(DATOS_DIR, 0755, true);
}

$fp = @fopen(PARTIDAS_LOG, 'c+b');
if ($fp === false) {
    responder(['ok' => false, 'error' => 'No se pudo abrir el registro.'], 500);
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    responder(['ok' => false, 'error' => 'No se pudo bloquear el registro.'], 500);
}

fseek($fp, 0, SEEK_END);
fwrite($fp, json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

// Rotación: si el log supera el tope, conserva la mitad más reciente.
clearstatcache(true, PARTIDAS_LOG);
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

responder(['ok' => true], 200);
