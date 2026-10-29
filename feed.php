<?php
/**
 * Draft 20 — Feed RSS 2.0 de las guías (/feed.xml).
 *
 * Se descubre con <link rel="alternate"> en todas las páginas; los feeds se
 * rastrean con frecuencia, así que acelera la indexación de contenido nuevo.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

header('Content-Type: application/rss+xml; charset=UTF-8');

$guias = guias_ordenadas();
$ultima = $hoy = date(DATE_RSS);
foreach ($guias as $g) {
    $ts = strtotime((string) ($g['fecha'] ?? ''));
    if ($ts !== false && $ts > strtotime($ultima)) {
        $ultima = date(DATE_RSS, $ts);
    }
}
$es = idioma_actual() === 'es';
$urlGuias = url_lang(SITE_URL . '/guias');
$urlFeed = url_lang(SITE_URL . '/feed.xml');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= $es ? 'Draft 20 — Guías' : 'Draft 20 — Guides' ?></title>
    <link><?= e($urlGuias) ?></link>
    <description><?= $es ? 'Reglas, estrategia y comparativas del juego de subasta Draft 20.' : 'Rules, strategy and comparisons for the Draft 20 auction game.' ?></description>
    <language><?= e(idioma_actual()) ?></language>
    <lastBuildDate><?= e($ultima) ?></lastBuildDate>
    <atom:link href="<?= e($urlFeed) ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($guias as $slug => $g):
    $ts = strtotime((string) ($g['fecha'] ?? ''));
    $urlGuia = url_lang(SITE_URL . '/guia/' . $slug);
    ?>
    <item>
        <title><?= e((string) ($g['titulo'] ?? $slug)) ?></title>
        <link><?= e($urlGuia) ?></link>
        <guid isPermaLink="true"><?= e($urlGuia) ?></guid>
        <description><?= e((string) ($g['desc'] ?? '')) ?></description>
        <?php if ($ts !== false): ?>
        <pubDate><?= e(date(DATE_RSS, $ts)) ?></pubDate>
        <?php endif; ?>
    </item>
<?php endforeach; ?>
</channel>
</rss>
