<?php
/**
 * Genera el ZIP del wrapper de GameDistribution.
 *
 * Uso: php tools/build_gd_zip.php
 * Salida: dist/draft20-gd.zip con index.html en la raíz (el juego se sirve
 * desde https://draft20.es/?gd=1&lang=en dentro del iframe).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$src = $root . '/gd/index.html';
if (!is_file($src)) {
    fwrite(STDERR, "No existe gd/index.html\n");
    exit(1);
}

$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true) && !is_dir($dist)) {
    fwrite(STDERR, "No se pudo crear dist/\n");
    exit(1);
}

$zipPath = $dist . '/draft20-gd.zip';
if (is_file($zipPath)) {
    unlink($zipPath);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "La extensión zip de PHP no está disponible\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el ZIP\n");
    exit(1);
}

$zip->addFile($src, 'index.html');
$zip->addFromString('LEEME.txt', "Draft 20 - wrapper para GameDistribution\n\n"
    . "1. Sube este ZIP en el panel de GameDistribution.\n"
    . "2. El wrapper carga https://draft20.es/?gd=1&lang=en en un iframe.\n"
    . "3. El SDK, el pre-roll y los display ads se ejecutan dentro del juego.\n");
$zip->close();

echo 'ZIP generado: ' . $zipPath . ' (' . filesize($zipPath) . " bytes)\n";
