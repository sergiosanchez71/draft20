<?php
/**
 * Draft 20 — Informe de métricas (CLI).
 *
 * Uso:  php tools/stats.php [días]
 *
 * Combina dos fuentes, todo local y ya existente:
 *   - api/datos/stats/*.json   → contadores anónimos por evento y día
 *   - api/salas/*.json         → partidas (creadas, terminadas, abandonadas)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
$dias = isset($argv[1]) ? max(1, (int) $argv[1]) : 14;

// ---------- Contadores de eventos ----------
echo "=== Eventos (últimos $dias días) ===\n";
$statsDir = $root . '/api/datos/stats/';
$totales = [];
$porDia = [];
for ($i = 0; $i < $dias; $i++) {
    $fecha = date('Y-m-d', time() - $i * 86400);
    $file = $statsDir . $fecha . '.json';
    if (!is_file($file)) {
        continue;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        continue;
    }
    ksort($data);
    $porDia[$fecha] = $data;
    foreach ($data as $ev => $n) {
        $totales[$ev] = (int) ($totales[$ev] ?? 0) + (int) $n;
    }
}

if ($totales === []) {
    echo "  (sin datos todavía)\n";
} else {
    arsort($totales);
    foreach ($totales as $ev => $n) {
        echo sprintf("  %-16s %6d\n", $ev, $n);
    }
    echo "\n  Detalle por día:\n";
    foreach ($porDia as $fecha => $data) {
        $pares = [];
        foreach ($data as $ev => $n) {
            $pares[] = $ev . '=' . $n;
        }
        echo '  ' . $fecha . '  ' . implode('  ', $pares) . "\n";
    }
}

// ---------- Partidas (salas) ----------
echo "\n=== Partidas en api/salas (creadas en los últimos $dias días) ===\n";
$salasDir = $root . '/api/salas/';
$desde = time() - $dias * 86400;
$creadas = 0;
$terminadas = 0;
$abandonadas = 0;
$conBot = 0;
$humanas = 0;
$duraciones = [];

foreach ((array) @glob($salasDir . '*.json') as $file) {
    $s = json_decode((string) @file_get_contents($file), true);
    if (!is_array($s) || !isset($s['creado_en']) || (int) $s['creado_en'] < $desde) {
        continue;
    }
    $creadas++;
    $esBot = isset($s['bot_slot']) && $s['bot_slot'] !== null;
    if ($esBot) {
        $conBot++;
    } else {
        $humanas++;
    }
    if (($s['estado'] ?? '') === 'finalizada') {
        $terminadas++;
        $dur = (int) ($s['actualizado_en'] ?? 0) - (int) $s['creado_en'];
        if ($dur > 0 && $dur < 7200) {
            $duraciones[] = $dur;
        }
    } elseif (($s['estado'] ?? '') === 'abandonada') {
        $abandonadas++;
    }
}

echo '  creadas: ' . $creadas . "  (humanas: $humanas · bot: $conBot)\n";
echo '  terminadas: ' . $terminadas . '  abandonadas: ' . $abandonadas . "\n";
if ($duraciones !== []) {
    sort($duraciones);
    $mediana = $duraciones[intdiv(count($duraciones), 2)];
    echo '  duración mediana (terminadas): ' . round($mediana / 60, 1) . " min\n";
}
echo "\n";
