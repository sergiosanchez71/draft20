<?php
/**
 * Draft 20 — Índice de guías (/guias).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$guias = guias_ordenadas();
$descripcion = seo_ui('guias_sub');

$itemList = [];
$pos = 1;
foreach ($guias as $slug => $g) {
    $itemList[] = [
        '@type' => 'ListItem',
        'position' => $pos++,
        'name' => (string) $g['titulo'],
        'url' => SITE_URL . '/guia/' . $slug,
    ];
}

pagina_head([
    'titulo' => seo_ui('guias_titulo') . ' - ' . SITE_NOMBRE,
    'descripcion' => $descripcion,
    'canonical' => '/guias',
    'og_image' => SITE_URL . '/og/seccion/guias.png',
    'json_ld' => [[
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => seo_ui('guias_titulo'),
        'inLanguage' => 'es',
        'mainEntity' => [
            '@type' => 'ItemList',
            'numberOfItems' => count($itemList),
            'itemListElement' => $itemList,
        ],
    ]],
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-2"><?= e(seo_ui('guias_titulo')) ?></h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-8"><?= e($descripcion) ?></p>

        <ul class="space-y-3 mb-8">
            <?php foreach ($guias as $slug => $g): ?>
            <li class="bg-slate-800 border border-slate-700 rounded-lg p-4">
                <a class="font-bold text-amber-300 hover:text-amber-200" href="/guia/<?= e($slug) ?>"><?= e((string) $g['titulo']) ?></a>
                <p class="text-sm text-slate-300 leading-relaxed mt-2"><?= e((string) ($g['desc'] ?? '')) ?></p>
                <p class="text-xs text-slate-400 mt-2"><?= e(seo_ui('guia_actualizado')) ?>: <?= e(fecha_es((string) ($g['fecha'] ?? ''))) ?></p>
            </li>
            <?php endforeach; ?>
        </ul>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6"><?= e((string) ($SEO['hero_cta'] ?? 'Jugar')) ?></a>
    </main>
<?php
pagina_foot();
