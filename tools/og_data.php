<?php
/**
 * Draft 20 - Datos para generar las imágenes OG de categorías, guías y secciones.
 *
 * Uso (CLI):  php tools/og_data.php [--lang=en]
 * Escribe el JSON en el temp del sistema y muestra la ruta; tools/gen_og.ps1 lo lee.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
$langCode = 'es';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--lang=(es|en)$/', $arg, $m)) {
        $langCode = $m[1];
    }
}
$es = $langCode === 'es';
$lang = json_decode((string) file_get_contents($root . '/lang/' . $langCode . '.json'), true);
if (!is_array($lang)) {
    fwrite(STDERR, "No se pudo leer lang/{$langCode}.json\n");
    exit(1);
}
$contenido = [];
if (!$es) {
    $raw = is_file($root . '/lang/contenido_en.json')
        ? json_decode((string) file_get_contents($root . '/lang/contenido_en.json'), true)
        : null;
    $contenido = is_array($raw) ? $raw : [];
}

$out = [];

// Temáticas (con el nombre de su categoría), desde el catálogo compartido.
$catalogo = require $root . '/tematicas_catalogo.php';
foreach ($catalogo as $cat) {
    $catLabel = (string) ($lang['tematicas_categorias'][$cat['id']] ?? $cat['id']);
    foreach (($cat['tematicas'] ?? []) as $tm) {
        $nombre = (string) ($lang['tematicas'][$tm['id']] ?? $tm['id']);
        $out[] = ['tipo' => 'tematica', 'id' => $tm['id'], 'nombre' => $nombre, 'categoria' => $catLabel];
    }
}

// Categorías: título corto desde lang/tematicas_categorias.
$cats = $es
    ? require $root . '/contenido_categorias.php'
    : (is_array($contenido['categorias'] ?? null) ? $contenido['categorias'] : []);
foreach (array_keys($cats) as $id) {
    $label = (string) ($lang['tematicas_categorias'][$id] ?? $id);
    $out[] = [
        'tipo' => 'categoria',
        'id' => $id,
        'nombre' => $label,
        'categoria' => $es ? 'Categoría de temáticas' : 'Theme category',
    ];
}

// Guías: nombre corto a partir del h1 (sin paréntesis, recortado).
$guias = [];
if ($es) {
    $guiaFiles = ['contenido_guias_1.php', 'contenido_guias_2.php', 'contenido_guias_3.php', 'contenido_guias_4.php'];
    foreach ($guiaFiles as $f) {
        $part = is_file($root . '/' . $f) ? require $root . '/' . $f : null;
        if (is_array($part)) {
            $guias += $part;
        }
    }
} else {
    $guias = is_array($contenido['guias'] ?? null) ? $contenido['guias'] : [];
}
foreach ($guias as $slug => $g) {
    $nombre = (string) ($g['h1'] ?? $g['titulo'] ?? $slug);
    $nombre = trim((string) preg_replace('/\s*\(.*?\)\s*/', ' ', $nombre));
    if (function_exists('mb_strlen') && mb_strlen($nombre) > 58) {
        $nombre = rtrim(mb_substr($nombre, 0, 55)) . '…';
    } elseif (!function_exists('mb_strlen') && strlen($nombre) > 58) {
        $nombre = rtrim(substr($nombre, 0, 55)) . '…';
    }
    $out[] = [
        'tipo' => 'guia',
        'id' => $slug,
        'nombre' => $nombre,
        'categoria' => $es ? 'Guía de Draft 20' : 'Draft 20 guide',
    ];
}

// Secciones fijas.
if ($es) {
    $out[] = ['tipo' => 'seccion', 'id' => 'como-jugar', 'nombre' => 'Cómo jugar a Draft 20', 'categoria' => 'Reglas en 2 minutos'];
    $out[] = ['tipo' => 'seccion', 'id' => 'guias', 'nombre' => 'Guías de Draft 20', 'categoria' => 'Trucos, formatos y variantes'];
    $out[] = ['tipo' => 'seccion', 'id' => 'glosario', 'nombre' => 'Glosario de Draft 20', 'categoria' => 'Todos los términos del juego'];
    $out[] = ['tipo' => 'seccion', 'id' => 'juegos-de-subasta', 'nombre' => 'Juegos de subasta', 'categoria' => 'Qué son y cómo elegir uno online'];
} else {
    $out[] = ['tipo' => 'seccion', 'id' => 'como-jugar', 'nombre' => 'How to play Draft 20', 'categoria' => 'Rules in 2 minutes'];
    $out[] = ['tipo' => 'seccion', 'id' => 'guias', 'nombre' => 'Draft 20 Guides', 'categoria' => 'Tips, formats and variants'];
    $out[] = ['tipo' => 'seccion', 'id' => 'glosario', 'nombre' => 'Draft 20 Glossary', 'categoria' => 'Every term in the game'];
    $out[] = ['tipo' => 'seccion', 'id' => 'juegos-de-subasta', 'nombre' => 'Auction games', 'categoria' => 'What they are and how to pick one online'];
}

$file = $es ? 'og_data.json' : 'og_data_en.json';
$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $file;
file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo $path . PHP_EOL;
echo 'Entradas: ' . count($out) . PHP_EOL;
