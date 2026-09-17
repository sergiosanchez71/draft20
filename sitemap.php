<?php
/**
 * Draft 20 — sitemap.xml dinámico.
 * Incluye: home, páginas de soporte y las 72 fichas de temática.
 * Se sirve en https://draft20.es/sitemap.xml (rewrite en .htaccess).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

$hoy = date('Y-m-d');

/** lastmod real: fecha de modificación del archivo que sirve la página. */
$mtime = static function (string $archivo) use ($hoy): string {
    $ruta = __DIR__ . '/' . $archivo;
    return is_file($ruta) ? date('Y-m-d', (int) filemtime($ruta)) : $hoy;
};
$mtimeMax = static function (array $archivos) use ($hoy): string {
    $ts = 0;
    foreach ($archivos as $a) {
        $ruta = __DIR__ . '/' . $a;
        if (is_file($ruta)) {
            $ts = max($ts, (int) filemtime($ruta));
        }
    }
    return $ts > 0 ? date('Y-m-d', $ts) : $hoy;
};

$urls = [
    ['loc' => SITE_URL . '/', 'lastmod' => $hoy, 'changefreq' => 'weekly', 'priority' => '1.0'],
    ['loc' => SITE_URL . '/como-jugar', 'lastmod' => $mtime('como_jugar.php'), 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => SITE_URL . '/guias', 'lastmod' => $mtimeMax(['guias.php', 'contenido_seo.php', 'contenido_guias_1.php', 'contenido_guias_2.php', 'contenido_guias_3.php']), 'changefreq' => 'weekly', 'priority' => '0.8'],
    ['loc' => SITE_URL . '/acerca', 'lastmod' => $mtime('acerca.php'), 'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => SITE_URL . '/contacto', 'lastmod' => $mtime('contacto.php'), 'changefreq' => 'yearly', 'priority' => '0.4'],
    ['loc' => SITE_URL . '/privacidad', 'lastmod' => $mtime('privacidad.php'), 'changefreq' => 'yearly', 'priority' => '0.3'],
    ['loc' => SITE_URL . '/aviso-legal', 'lastmod' => $mtime('aviso_legal.php'), 'changefreq' => 'yearly', 'priority' => '0.3'],
    ['loc' => SITE_URL . '/glosario', 'lastmod' => $mtime('glosario.php'), 'changefreq' => 'monthly', 'priority' => '0.6'],
    ['loc' => SITE_URL . '/juegos-de-subasta', 'lastmod' => $mtimeMax(['juegos_de_subasta.php', 'contenido_hub_subasta.php']), 'changefreq' => 'monthly', 'priority' => '0.9'],
];

foreach (categorias() as $cat) {
    // Frescura real del hub: la fecha del JSON más reciente de la categoría.
    $mtimeCat = 0;
    foreach ($cat['tematicas'] as $tm) {
        $fileTm = __DIR__ . '/tematicas/' . $tm['id'] . '.json';
        if (is_file($fileTm)) {
            $mtimeCat = max($mtimeCat, (int) filemtime($fileTm));
        }
    }
    $urls[] = [
        'loc' => SITE_URL . '/categoria/' . $cat['id'],
        'lastmod' => $mtimeCat > 0 ? date('Y-m-d', $mtimeCat) : $hoy,
        'changefreq' => 'monthly',
        'priority' => '0.8',
    ];
}

foreach (guias_ordenadas() as $slug => $g) {
    $urls[] = [
        'loc' => SITE_URL . '/guia/' . $slug,
        'lastmod' => (string) ($g['fecha'] ?? $hoy),
        'changefreq' => 'monthly',
        'priority' => '0.7',
    ];
}

foreach (mapa_tematicas() as $id => $tm) {
    $file = __DIR__ . '/tematicas/' . $id . '.json';
    $urls[] = [
        'loc' => SITE_URL . '/tematica/' . $id,
        'lastmod' => is_file($file) ? date('Y-m-d', (int) filemtime($file)) : $hoy,
        'changefreq' => 'monthly',
        'priority' => '0.7',
    ];
}

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
    <url>
        <loc><?= e($u['loc']) ?></loc>
        <lastmod><?= e($u['lastmod']) ?></lastmod>
        <changefreq><?= e($u['changefreq']) ?></changefreq>
        <priority><?= e($u['priority']) ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
