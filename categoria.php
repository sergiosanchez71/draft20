<?php
/**
 * Draft 20 — Página de categoría (/categoria/<id>).
 *
 * Hub de las temáticas de una categoría con intro editorial, tarjetas con
 * descripción y enlazado interno (ItemList + BreadcrumbList).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$id = $_GET['id'] ?? null;
$categorias = categorias();
$cat = null;
foreach ($categorias as $c) {
    if ($c['id'] === $id) {
        $cat = $c;
        break;
    }
}
$cont = is_string($id) ? categoria_contenido($id) : null;
if ($cat === null || $cont === null) {
    pagina_404();
    exit;
}

$nombreCat = nombre_categoria($cat['id']);
$titulo = (string) $cont['titulo'];
$descripcion = recortar(implode(' ', array_map('strval', $cont['intro'])), 155);

// Frescura real: la fecha del JSON más reciente de la categoría.
$mtimeCat = 0;
foreach ($cat['tematicas'] as $tmx) {
    $fileTmx = __DIR__ . '/tematicas/' . $tmx['id'] . '.json';
    if (is_file($fileTmx)) {
        $mtimeCat = max($mtimeCat, (int) filemtime($fileTmx));
    }
}

$itemList = [];
$pos = 1;
foreach ($cat['tematicas'] as $tm) {
    $itemList[] = [
        '@type' => 'ListItem',
        'position' => $pos++,
        'name' => nombre_tematica($tm['id']),
        'url' => SITE_URL . '/tematica/' . $tm['id'],
    ];
}

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => (string) ($SEO['migas_inicio'] ?? 'Inicio'), 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($SEO['migas_tematicas'] ?? 'Temáticas'), 'item' => SITE_URL . '/#tematicas'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $nombreCat],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $titulo,
        'inLanguage' => 'es',
        'mainEntity' => [
            '@type' => 'ItemList',
            'numberOfItems' => count($itemList),
            'itemListElement' => $itemList,
        ],
    ],
];

pagina_head([
    'titulo' => $titulo . ' — ' . SITE_NOMBRE,
    'descripcion' => $descripcion,
    'canonical' => '/categoria/' . $cat['id'],
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/"><?= e((string) ($SEO['migas_inicio'] ?? 'Inicio')) ?></a>
            <span class="mx-1">/</span>
            <a class="hover:text-amber-400" href="/#tematicas"><?= e((string) ($SEO['migas_tematicas'] ?? 'Temáticas')) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e($nombreCat) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-4"><?= e($cat['emoji'] . ' ' . $titulo) ?></h1>
        <?php foreach ($cont['intro'] as $parrafo): ?>
        <p class="text-sm text-slate-300 leading-relaxed mb-4"><?= e((string) $parrafo) ?></p>
        <?php endforeach; ?>
        <?php if ($mtimeCat > 0): ?>
        <p class="text-xs text-slate-500 mb-6"><?= e(seo_ui('guia_actualizado')) ?>: <?= e(date('m/Y', $mtimeCat)) ?></p>
        <?php endif; ?>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-8"><?= e((string) ($SEO['hero_cta'] ?? 'Jugar')) ?></a>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(seo_ui('categoria_tematicas_titulo')) ?></h2>
        <ul class="space-y-3 mb-10">
            <?php foreach ($cat['tematicas'] as $tm):
                $tCont = tematica_contenido($tm['id']);
                ?>
            <li class="bg-slate-800 border border-slate-700 rounded-lg p-4">
                <a class="font-bold text-amber-300 hover:text-amber-200" href="/tematica/<?= e($tm['id']) ?>"><?= e($tm['emoji'] . ' ' . nombre_tematica($tm['id'])) ?></a>
                <?php if ($tCont !== null): ?>
                <p class="text-sm text-slate-300 leading-relaxed mt-2"><?= e(recortar((string) $tCont['descripcion'], 170)) ?></p>
                <?php endif; ?>
                <a class="inline-block mt-2 text-xs font-semibold text-slate-400 hover:text-amber-400" href="/tematica/<?= e($tm['id']) ?>"><?= e(seo_ui('ver_tematica')) ?> →</a>
            </li>
            <?php endforeach; ?>
        </ul>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(seo_ui('categoria_otras_titulo')) ?></h2>
        <ul class="flex flex-wrap gap-2 mb-6">
            <?php foreach ($categorias as $otra): if ($otra['id'] === $cat['id']) continue; ?>
            <li>
                <a href="/categoria/<?= e($otra['id']) ?>" class="inline-block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-full px-3 py-1.5 text-sm text-slate-200"><?= e($otra['emoji'] . ' ' . nombre_categoria($otra['id'])) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>

        <h2 class="text-xl font-bold text-slate-100 mb-4">Guías relacionadas</h2>
        <ul class="space-y-2 mb-6">
            <li><a class="text-sm text-slate-200 hover:text-amber-400" href="/guia/mejores-tematicas">Las 10 temáticas más divertidas de Draft 20 →</a></li>
            <li><a class="text-sm text-slate-200 hover:text-amber-400" href="/guia/como-ganar-draft-20">Cómo ganar en Draft 20: tácticas de subasta →</a></li>
        </ul>

        <a href="/guias" class="inline-block text-amber-400 hover:text-amber-300 text-sm font-semibold mb-6"><?= e(seo_ui('guias_ver_todas')) ?> →</a>
    </main>
<?php
pagina_foot();
