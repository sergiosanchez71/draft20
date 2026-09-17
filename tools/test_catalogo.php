<?php
/**
 * Test de catálogo (sin servidor): valida TODAS las temáticas de /tematicas/.
 *
 * - >= 20 items, IDs únicos con formato slug, valor 1-10, emoji no vacío
 * - tiers: premium (>=8) >= 2, malos (<=3) >= 1; medios (4-7) se avisa si faltan
 * - traducción de cada item en lang/es.json::items
 * - label de cada temática en lang/es.json::tematicas
 * - sin colisión de prefijos entre temáticas (prefijo = parte antes del 1er "_")
 * - cada temática aparece referenciada en index.php ($categorias)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$totalOk = 0; $totalFail = 0; $totalWarn = 0;

function ass($esperado, $actual, string $msg) {
    global $totalOk, $totalFail;
    if ($esperado === $actual) { $totalOk++; return; }
    echo "  FAIL $msg: esperado=" . json_encode($esperado) . " actual=" . json_encode($actual) . "\n";
    $totalFail++;
}
function warn(string $msg) {
    global $totalWarn;
    echo "  WARN $msg\n";
    $totalWarn++;
}

$tematicasFiles = glob($root . '/tematicas/*.json');
sort($tematicasFiles);
$lang = json_decode((string) file_get_contents($root . '/lang/es.json'), true);
$indexSrc = (string) file_get_contents($root . '/tematicas_catalogo.php');

$prefijoUso = [];
$idsGlobales = [];

foreach ($tematicasFiles as $path) {
    $fileId = basename($path, '.json');
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) { ass(true, false, "$fileId: JSON válido"); continue; }

    ass($fileId, $data['id'] ?? null, "$fileId: id de archivo coincide");
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];

    // >= 20 items
    ass(true, count($items) >= 20, "$fileId: >= 20 items (" . count($items) . ")");

    $ids = []; $emojis = []; $premium = 0; $medios = 0; $malos = 0;
    foreach ($items as $it) {
        $id = (string) ($it['id'] ?? '');
        $emoji = (string) ($it['emoji'] ?? '');
        $valor = $it['valor'] ?? null;

        if (!preg_match('/^[a-z0-9_]+$/', $id)) { ass(true, false, "$fileId: id inválido '$id'"); }
        if ($emoji === '') { ass(true, false, "$fileId: emoji vacío en '$id'"); }
        if (!is_int($valor) || $valor < 1 || $valor > 10) { ass(true, false, "$fileId: valor inválido en '$id'"); }

        if (isset($idsGlobales[$id])) { ass(true, false, "$fileId: id duplicado global '$id' (ya en {$idsGlobales[$id]})"); }
        $idsGlobales[$id] = $fileId;
        $ids[$id] = true;

        if ($valor >= 8) $premium++;
        elseif ($valor >= 4) $medios++;
        else $malos++;

        if (!isset($lang['items'][$id])) { ass(true, false, "$fileId: sin traducción para '$id'"); }

        $prefijo = explode('_', $id)[0];
        $prefijoUso[$prefijo][$fileId] = true;
    }

    ass(count($items), count($ids), "$fileId: ids únicos");
    ass(true, $premium >= 2, "$fileId: premium >= 2 ($premium)");
    ass(true, $malos >= 1, "$fileId: malos >= 1 ($malos)");
    if ($medios === 0) { warn("$fileId: 0 items medios (sorteo sin tier medio)"); }

    if (!isset($lang['tematicas'][$fileId])) { ass(true, false, "$fileId: sin label en lang.tematicas"); }
    if (strpos($indexSrc, "'" . $fileId . "'") === false) { ass(true, false, "$fileId: no referenciada en index.php"); }
}

// Colisión de prefijos entre temáticas distintas
foreach ($prefijoUso as $prefijo => $archivos) {
    ass(1, count($archivos), "prefijo '$prefijo' no compartido entre temáticas (" . implode(', ', array_keys($archivos)) . ")");
}

// Resumen del catálogo
$totalTematicas = count($tematicasFiles);
$totalItems = count($idsGlobales);
$totalTraducciones = count($lang['items'] ?? []);
$totalLabels = count($lang['tematicas'] ?? []);

// Coherencia con tematicas_catalogo.php (fuente única del menú).
$catalogo = require $root . '/tematicas_catalogo.php';
$catalogoIds = [];
foreach ($catalogo as $cat) {
    foreach ($cat['tematicas'] as $tm) $catalogoIds[] = $tm['id'];
}
$catalogoIds = array_values(array_unique($catalogoIds));

echo "\n=== CATÁLOGO: $totalTematicas temáticas, $totalItems items, $totalTraducciones traducciones, $totalLabels labels ===\n";
ass(true, $totalTematicas >= 60, 'catálogo con >= 60 temáticas');
ass($totalTematicas, count($catalogoIds), 'temáticas de tematicas_catalogo.php == archivos');
foreach ($catalogoIds as $cid) {
    if (!in_array($cid, array_map(static fn($p) => basename($p, '.json'), $tematicasFiles), true)) {
        ass(true, false, "catálogo referencia '$cid' sin archivo");
    }
}
ass(true, $totalLabels >= $totalTematicas, 'lang.tematicas cubre el catálogo');

echo "\n=== RESUMEN CATÁLOGO: $totalOk OK / $totalFail FAIL / $totalWarn WARN ===\n";
exit($totalFail > 0 ? 1 : 0);
