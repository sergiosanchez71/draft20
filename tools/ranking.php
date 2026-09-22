<?php
/**
 * Draft 20 — Ranking de partidas humanas (CLI, solo fichero).
 *
 * Uso:  php tools/ranking.php [días]
 *
 * Lee api/datos/partidas_humanas.jsonl (una línea por partida terminada:
 * ts, tematica, visibles, tipo, rondas, resultado, durSeg) e imprime
 * ranking por temática, modo, tipo, resultado y franja horaria.
 * El mismo agregado sirve api/ranking.php (JSON con clave).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/api/ranking_lib.php';

$dias = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;
$agg = ranking_humanas($root . '/api/datos/partidas_humanas.jsonl', $dias);
$n = $agg['total'];

echo "=== Ranking humanas (últimos $dias días, n=$n) ===\n";
if ($n === 0) {
    echo "  (sin partidas en el periodo)\n";
    exit(0);
}

foreach (['porTematica' => '-- Por temática --', 'porModo' => '-- Por modo --', 'porTipo' => '-- Por tipo --', 'porResultado' => '-- Por resultado --', 'porFranja' => '-- Por franja (hora local del servidor) --'] as $k => $titulo) {
    echo "$titulo\n";
    foreach ($agg[$k] as $et => $c) {
        echo sprintf("  %-18s %4d  (%5.1f%%)\n", (string) $et, $c, 100 * $c / $n);
    }
}
