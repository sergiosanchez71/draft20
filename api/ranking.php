<?php
/**
 * Draft 20 — API: ranking de partidas (solo lectura, con clave).
 *
 * Lee api/datos/partidas_humanas.jsonl y devuelve el mismo agregado que
 * tools/ranking.php. Acceso con ?clave=<secreto>, donde el secreto vive
 * en api/datos/clave_ranking.txt (fuera de git; se crea a mano en el
 * servidor). Sin clave válida → 403. Sin cuerpo en POST: solo GET.
 *
 * --- Petición ---
 *   GET api/ranking.php?clave=SECRETO&dias=30
 *   dias=0 (o dias=todo) = todo el histórico sin filtro, con desglose porMeses.
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "dias": 30, "total": 12, "porTematica": {...},
 *     "porModo": {...}, "porTipo": {...}, "porResultado": {...}, "porFranja": {...},
 *     "porMes": {"2026-09": {"total": 12, "topTematica": "pizza", "porTipo": {...}}} }
 */
declare(strict_types=1);

require_once __DIR__ . '/ranking_lib.php';

if (!function_exists('responder')) {
    function responder(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
}

$secreto = trim((string) @file_get_contents(__DIR__ . '/datos/clave_ranking.txt'));
$clave = isset($_GET['clave']) && is_string($_GET['clave']) ? $_GET['clave'] : '';
if ($secreto === '' || !hash_equals($secreto, $clave)) {
    responder(['ok' => false, 'error' => 'No autorizado.'], 403);
}

$diasRaw = isset($_GET['dias']) && is_string($_GET['dias']) ? $_GET['dias'] : '30';
$dias = ($diasRaw === '0' || strtolower($diasRaw) === 'todo') ? 0 : max(1, min(180, (int) $diasRaw));
$agg = ranking_humanas(__DIR__ . '/datos/partidas_humanas.jsonl', $dias);
responder(['ok' => true, 'dias' => $dias] + $agg, 200);
