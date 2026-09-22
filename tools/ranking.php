<?php
/**
 * Draft 20 — Ranking de partidas humanas (CLI, solo fichero).
 *
 * Uso:  php tools/ranking.php [días]
 *
 * Lee api/datos/partidas_humanas.jsonl (una línea por partida humana
 * terminada: ts, tematica, visibles, tipo, rondas, resultado, durSeg)
 * e imprime ranking por temática, modo, tipo, resultado y franja horaria.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
$dias = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;
$desde = time() - $dias * 86400;

$log = $root . '/api/datos/partidas_humanas.jsonl';
if (!is_file($log)) {
    echo "Sin datos todavía (sin partidas humanas terminadas).\n";
    exit(0);
}

$filas = [];
$fp = @fopen($log, 'rb');
if ($fp !== false) {
    if (flock($fp, LOCK_SH)) {
        while (($l = fgets($fp)) !== false) {
            $r = json_decode(trim($l), true);
            if (!is_array($r) || (int) ($r['ts'] ?? 0) < $desde) continue;
            $filas[] = $r;
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

$n = count($filas);
echo "=== Ranking humanas (últimos $dias días, n=$n) ===\n";
if ($n === 0) {
    echo "  (sin partidas en el periodo)\n";
    exit(0);
}

$porTematica = [];
$porModo = ['visible' => 0, 'oculto' => 0];
$porTipo = ['privada' => 0, 'rapida' => 0, 'revancha' => 0];
$porResultado = ['j1' => 0, 'j2' => 0, 'empate' => 0];
$porFranja = ['00-06' => 0, '06-12' => 0, '12-18' => 0, '18-24' => 0];
foreach ($filas as $r) {
    $t = (string) ($r['tematica'] ?? 'desconocida');
    $porTematica[$t] = ($porTematica[$t] ?? 0) + 1;
    $porModo[!empty($r['visibles']) ? 'visible' : 'oculto']++;
    $tipo = (string) ($r['tipo'] ?? 'privada');
    if (!isset($porTipo[$tipo])) $porTipo[$tipo] = 0;
    $porTipo[$tipo]++;
    $res = (string) ($r['resultado'] ?? 'empate');
    if (!isset($porResultado[$res])) $porResultado[$res] = 0;
    $porResultado[$res]++;
    $h = (int) date('G', (int) $r['ts']);
    if ($h < 6) $porFranja['00-06']++;
    elseif ($h < 12) $porFranja['06-12']++;
    elseif ($h < 18) $porFranja['12-18']++;
    else $porFranja['18-24']++;
}

echo "-- Por temática --\n";
arsort($porTematica);
foreach ($porTematica as $t => $c) {
    echo sprintf("  %-18s %4d  (%5.1f%%)\n", $t, $c, 100 * $c / $n);
}
echo "-- Por modo --\n";
foreach ($porModo as $m => $c) {
    echo sprintf("  %-18s %4d  (%5.1f%%)\n", $m, $c, 100 * $c / $n);
}
echo "-- Por tipo --\n";
foreach ($porTipo as $t => $c) {
    echo sprintf("  %-18s %4d  (%5.1f%%)\n", $t, $c, 100 * $c / $n);
}
echo "-- Por resultado --\n";
foreach ($porResultado as $r => $c) {
    echo sprintf("  %-18s %4d  (%5.1f%%)\n", $r, $c, 100 * $c / $n);
}
echo "-- Por franja (hora local del servidor) --\n";
foreach ($porFranja as $f => $c) {
    echo sprintf("  %-18s %4d  (%5.1f%%)\n", $f, $c, 100 * $c / $n);
}
