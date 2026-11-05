<?php
/**
 * Draft 20 — Ranking de partidas por país e idioma (CLI).
 *
 * Uso: php tools/ranking_pais.php [días]
 *
 * Combina ambas fuentes de datos:
 *   - api/datos/partidas_humanas.jsonl  → partidas humanas (con pais/idioma si existen)
 *   - api/datos/partidas.jsonl          → partidas contra el bot (con pais/idioma si existen)
 *
 * Todo es anónimo: nunca se guardan IPs, solo códigos de país de 2 letras
 * (ej. ES, US, GB) y el idioma de la interfaz (es/en).
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

$logs = [
    $root . '/api/datos/partidas_humanas.jsonl' => 'humanas',
    $root . '/api/datos/partidas.jsonl'         => 'bot',
];

$porPais   = [];
$porIdioma = [];
$porPaisIdioma = [];
$total = 0;
$sinPais = 0;
$sinIdioma = 0;

foreach ($logs as $logPath => $origen) {
    if (!is_file($logPath)) {
        continue;
    }
    $fp = @fopen($logPath, 'rb');
    if ($fp === false) {
        continue;
    }
    if (flock($fp, LOCK_SH)) {
        while (($l = fgets($fp)) !== false) {
            $r = json_decode(trim($l), true);
            if (!is_array($r)) {
                continue;
            }
            $ts = (int) ($r['ts'] ?? 0);
            if ($ts < $desde) {
                continue;
            }
            $total++;

            $pais = (string) ($r['pais'] ?? '');
            $idioma = (string) ($r['idioma'] ?? '');

            if ($pais === '') {
                $sinPais++;
                $pais = '??';
            }
            if ($idioma === '') {
                $sinIdioma++;
                $idioma = '??';
            }

            $porPais[$pais] = ($porPais[$pais] ?? 0) + 1;
            $porIdioma[$idioma] = ($porIdioma[$idioma] ?? 0) + 1;
            $clave = $pais . '|' . $idioma;
            $porPaisIdioma[$clave] = ($porPaisIdioma[$clave] ?? 0) + 1;
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

echo "=== Ranking de partidas por país e idioma (últimos $dias días) ===\n\n";

echo "Total de partidas: $total\n";
echo "Sin país detectado: $sinPais\n";
echo "Sin idioma detectado: $sinIdioma\n\n";

// Función para ordenar y mostrar
$ordenar = static function (array $conteo): array {
    $pares = [];
    foreach ($conteo as $id => $c) {
        $pares[] = [$id, $c];
    }
    usort($pares, static fn(array $a, array $b): int => $b[1] <=> $a[1] ?: $a[0] <=> $b[0]);
    return $pares;
};

echo "--- Por país ---\n";
foreach ($ordenar($porPais) as [$p, $c]) {
    $pct = $total > 0 ? round(100 * $c / $total, 1) : 0;
    echo sprintf("  %-8s %6d  (%s%%)", $p, $c, $pct) . "\n";
}

echo "\n--- Por idioma ---\n";
foreach ($ordenar($porIdioma) as [$i, $c]) {
    $pct = $total > 0 ? round(100 * $c / $total, 1) : 0;
    echo sprintf("  %-8s %6d  (%s%%)", $i, $c, $pct) . "\n";
}

echo "\n--- Por país + idioma (top 20) ---\n";
$count = 0;
foreach ($ordenar($porPaisIdioma) as [$clave, $c]) {
    if ($count++ >= 20) {
        break;
    }
    [$p, $i] = explode('|', $clave, 2);
    $pct = $total > 0 ? round(100 * $c / $total, 1) : 0;
    echo sprintf("  %-8s | %-6s %6d  (%s%%)", $p, $i, $c, $pct) . "\n";
}

echo "\n--- Por temática y país (top 10 temáticas globales) ---\n";
$porTemaPais = [];
foreach ($logs as $logPath => $origen) {
    if (!is_file($logPath)) {
        continue;
    }
    $fp = @fopen($logPath, 'rb');
    if ($fp === false) {
        continue;
    }
    if (flock($fp, LOCK_SH)) {
        while (($l = fgets($fp)) !== false) {
            $r = json_decode(trim($l), true);
            if (!is_array($r)) {
                continue;
            }
            $ts = (int) ($r['ts'] ?? 0);
            if ($ts < $desde) {
                continue;
            }
            $tema = (string) ($r['tematica'] ?? 'desconocida');
            $pais = (string) ($r['pais'] ?? '??');
            $clave = $tema . '|' . $pais;
            $porTemaPais[$clave] = ($porTemaPais[$clave] ?? 0) + 1;
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
// Top 10 temáticas globales
$porTema = [];
foreach ($porTemaPais as $clave => $c) {
    [$t, $p] = explode('|', $clave, 2);
    $porTema[$t] = ($porTema[$t] ?? 0) + $c;
}
$ordenarTema = $ordenar($porTema);
$topTemas = array_slice($ordenarTema, 0, 10);

foreach ($ordenarTema as [$tema, $cTotal]) {
    if (!in_array([$tema, $cTotal], $topTemas, true)) {
        continue;
    }
    echo "\n  $tema ($cTotal partidas):\n";
    $sub = [];
    foreach ($porTemaPais as $clave => $c) {
        [$t, $p] = explode('|', $clave, 2);
        if ($t === $tema) {
            $sub[$p] = $c;
        }
    }
    foreach ($ordenar($sub) as [$p, $c]) {
        echo sprintf("    %-8s %6d", $p, $c) . "\n";
    }
}

echo "\n";
