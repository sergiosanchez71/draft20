<?php
/**
 * Draft 20 - Datos para generar las imágenes OG de categorías, guías y secciones.
 *
 * Uso (CLI):  php tools/og_data.php
 * Escribe el JSON en el temp del sistema y muestra la ruta; tools/gen_og.ps1 lo lee.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
$lang = json_decode((string) file_get_contents($root . '/lang/es.json'), true);

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
$cats = require $root . '/contenido_categorias.php';
foreach (array_keys($cats) as $id) {
    $label = (string) ($lang['tematicas_categorias'][$id] ?? $id);
    $out[] = ['tipo' => 'categoria', 'id' => $id, 'nombre' => $label, 'categoria' => 'Categoría de temáticas'];
}

// Guías: nombre corto a partir del h1 (sin paréntesis, recortado).
$guiaFiles = ['contenido_guias_1.php', 'contenido_guias_2.php', 'contenido_guias_3.php'];
$guias = [];
foreach ($guiaFiles as $f) {
    $part = is_file($root . '/' . $f) ? require $root . '/' . $f : null;
    if (is_array($part)) {
        $guias += $part;
    }
}
foreach ($guias as $slug => $g) {
    $nombre = (string) ($g['h1'] ?? $g['titulo'] ?? $slug);
    $nombre = trim((string) preg_replace('/\s*\(.*?\)\s*/', ' ', $nombre));
    if (function_exists('mb_strlen') && mb_strlen($nombre) > 58) {
        $nombre = rtrim(mb_substr($nombre, 0, 55)) . '…';
    } elseif (!function_exists('mb_strlen') && strlen($nombre) > 58) {
        $nombre = rtrim(substr($nombre, 0, 55)) . '…';
    }
    $out[] = ['tipo' => 'guia', 'id' => $slug, 'nombre' => $nombre, 'categoria' => 'Guía de Draft 20'];
}

// Secciones fijas.
$out[] = ['tipo' => 'seccion', 'id' => 'como-jugar', 'nombre' => 'Cómo jugar a Draft 20', 'categoria' => 'Reglas en 2 minutos'];
$out[] = ['tipo' => 'seccion', 'id' => 'guias', 'nombre' => 'Guías de Draft 20', 'categoria' => 'Trucos, formatos y variantes'];
$out[] = ['tipo' => 'seccion', 'id' => 'glosario', 'nombre' => 'Glosario de Draft 20', 'categoria' => 'Todos los términos del juego'];

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'og_data.json';
file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo $path . PHP_EOL;
echo 'Entradas: ' . count($out) . PHP_EOL;
