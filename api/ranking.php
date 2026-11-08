<?php
/**
 * Draft 20 — API: ranking de partidas (solo lectura, con clave).
 *
 * Lee api/datos/partidas_humanas.jsonl y api/datos/partidas.jsonl.
 * Acceso con ?clave=<secreto>, donde el secreto vive en
 * api/datos/clave_ranking.txt (fuera de git; se crea a mano en el
 * servidor). Sin clave válida → 403.
 *
 * Devuelve siempre JSON. Para la vista interna HTML usa admin/ranking.html.
 *
 * --- Petición ---
 *   GET api/ranking.php?clave=SECRETO
 *   Sin dias = todo el histórico sin filtro, con desglose por meses.
 *   dias=N (1-180) = solo los últimos N días; dias=0 o dias=todo = histórico.
 *
 * --- Respuesta JSON 200 ---
 *   { "ok": true, "dias": 0, "total": 12, "porTematica": {...},
 *     "porModo": {...}, "porTipo": {...}, "porResultado": {...}, "porFranja": {...},
 *     "porMes": {"2026-09": {"total": 12, "topTematica": "pizza",
 *       "top": {"pizza": 5, ...}, "porTipo": {...}, "porIdiomas": {...}}} }
 *   top = mapa ordenado temática → partidas del top 10 mensual (uso interno).
 */
declare(strict_types=1);

require_once __DIR__ . '/ranking_lib.php';

if (!function_exists('leerFechasPorUsuario')) {
    /**
     * Lee todas las fechas únicas por user_id del log de racha.
     * Devuelve un mapa [user_id => [fecha1, fecha2, ...]].
     */
    function leerFechasPorUsuario(): array {
        $porUsuario = [];
        $ruta = __DIR__ . '/datos/racha.jsonl';
        if (!is_file($ruta)) {
            return $porUsuario;
        }
        $fp = @fopen($ruta, 'rb');
        if ($fp === false) {
            return $porUsuario;
        }
        if (flock($fp, LOCK_SH)) {
            while (($linea = fgets($fp)) !== false) {
                $r = json_decode(trim($linea), true);
                if (is_array($r) && isset($r['user_id'], $r['fecha'])) {
                    $uid = (string) $r['user_id'];
                    $f   = (string) $r['fecha'];
                    if ($uid !== '' && $f !== '' && !in_array($f, $porUsuario[$uid] ?? [], true)) {
                        $porUsuario[$uid][] = $f;
                    }
                }
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        foreach ($porUsuario as $uid => $fechas) {
            sort($porUsuario[$uid]);
        }
        return $porUsuario;
    }
}

if (!function_exists('rachaActual')) {
    /**
     * Calcula la racha actual (días consecutivos hacia atrás desde hoy).
     */
    function rachaActual(array $fechas, string $hoy): int {
        $ayer = date('Y-m-d', strtotime($hoy . ' -1 day'));
        $jugadoHoy = in_array($hoy, $fechas, true);
        if (!$jugadoHoy && !in_array($ayer, $fechas, true)) {
            return 0;
        }
        $racha = 1;
        $cursor = $jugadoHoy ? $hoy : $ayer;
        while (true) {
            $siguiente = date('Y-m-d', strtotime($cursor . ' -1 day'));
            if (in_array($siguiente, $fechas, true)) {
                $racha++;
                $cursor = $siguiente;
            } else {
                break;
            }
        }
        return $racha;
    }
}

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

$diasRaw = isset($_GET['dias']) && is_string($_GET['dias']) ? $_GET['dias'] : '0';
$dias = ($diasRaw === '0' || strtolower($diasRaw) === 'todo') ? 0 : max(1, min(180, (int) $diasRaw));

$agg = ranking_humanas([
    __DIR__ . '/datos/partidas_humanas.jsonl',
    __DIR__ . '/datos/partidas.jsonl',
], $dias);

// Distribución de rachas actuales: { "1": 5, "2": 3, "50+": 1 }.
$porUsuario = leerFechasPorUsuario();
$hoy = date('Y-m-d');
$distribucion = [];
foreach ($porUsuario as $fechas) {
    $racha = rachaActual($fechas, $hoy);
    if ($racha > 0) {
        $clave = $racha >= 50 ? '50+' : (string) $racha;
        $distribucion[$clave] = ($distribucion[$clave] ?? 0) + 1;
    }
}
ksort($distribucion, SORT_STRING);

responder(['ok' => true, 'dias' => $dias] + $agg + ['rachaDiasXUsuarios' => $distribucion], 200);