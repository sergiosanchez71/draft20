<?php
/**
 * Draft 20 — API: contador de eventos agregado y anónimo.
 *
 * Solo incrementa contadores diarios por evento (lista blanca cerrada). No
 * guarda cookies, identificadores, rutas con parámetros ni direcciones IP.
 * El fichero diario vive en api/datos/stats/ (bloqueado por HTTP).
 *
 * --- Petición (POST, application/json) ---
 *   { "evento": "page:home" }
 *
 * --- Respuesta ---
 *   204 → contado (sin cuerpo)
 *   400 → evento inválido
 *   405 → método no permitido
 *   429 → rate limit
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/rate_limit.php';

const STATS_DIR   = __DIR__ . '/datos/stats/';
const CUERPO_MAX  = 300;
const RETENCION_D = 180; // días de contadores que se conservan
const EVENTOS = [
    'page:home', 'page:tematica', 'page:categoria', 'page:guia', 'page:guias',
    'page:glosario', 'page:como-jugar',
    'game:creada', 'game:rapida', 'game:bot', 'game:fin', 'pwa:install',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

api_guard_origen();
rl_guard('evento', 60, 60);

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > CUERPO_MAX) {
    http_response_code(400);
    exit;
}

$in = json_decode($raw, true);
$evento = is_array($in) && isset($in['evento']) && is_string($in['evento']) ? $in['evento'] : '';
if (!in_array($evento, EVENTOS, true)) {
    http_response_code(400);
    exit;
}

if (!is_dir(STATS_DIR) && !@mkdir(STATS_DIR, 0755, true) && !is_dir(STATS_DIR)) {
    http_response_code(204); // sin almacenamiento no rompemos la página
    exit;
}

$file = STATS_DIR . date('Y-m-d') . '.json';
$fp = @fopen($file, 'c+b');
if ($fp !== false && flock($fp, LOCK_EX)) {
    $data = json_decode((string) stream_get_contents($fp), true);
    if (!is_array($data)) {
        $data = [];
    }
    $data[$evento] = (int) ($data[$evento] ?? 0) + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string) json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // GC oportunista (1% de las llamadas): borra contadores muy antiguos.
    if (random_int(1, 100) === 1) {
        $limite = time() - RETENCION_D * 86400;
        foreach ((array) @glob(STATS_DIR . '*.json') as $viejo) {
            if (is_file($viejo) && (int) @filemtime($viejo) < $limite) {
                @unlink($viejo);
            }
        }
    }
}

http_response_code(204);
