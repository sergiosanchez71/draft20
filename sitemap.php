<?php
/**
 * Draft 20 — sitemap.xml dinámico.
 * Incluye: home, páginas de soporte y las 72 fichas de temática.
 * Se sirve en https://draft20.es/sitemap.xml (rewrite en .htaccess).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$hoy = date('Y-m-d');

$urls = [
    ['loc' => SITE_URL . '/', 'lastmod' => $hoy, 'changefreq' => 'weekly', 'priority' => '1.0'],
    ['loc' => SITE_URL . '/como-jugar', 'lastmod' => $hoy, 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => SITE_URL . '/acerca', 'lastmod' => $hoy, 'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => SITE_URL . '/contacto', 'lastmod' => $hoy, 'changefreq' => 'yearly', 'priority' => '0.4'],
    ['loc' => SITE_URL . '/privacidad', 'lastmod' => $hoy, 'changefreq' => 'yearly', 'priority' => '0.3'],
];

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
