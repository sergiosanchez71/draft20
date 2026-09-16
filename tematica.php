<?php
/**
 * Draft 20 — Ficha de temática (/tematica/<id>).
 *
 * SEO: H1, intro, lista de los 20 ítems (sin valores), migas, relacionadas
 * y JSON-LD BreadcrumbList + ItemList. CTA para jugar esa temática.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$id = $_GET['id'] ?? null;
$mapa = mapa_tematicas();
if (!is_string($id) || !isset($mapa[$id])) {
    pagina_404();
    exit;
}

$tm = $mapa[$id];
$nombre = nombre_tematica($id);
$categoria = nombre_categoria($tm['categoria_id']);
$items = items_tematica($id);

// Descripción con los primeros ítems (sin revelar valores).
$nombres = [];
foreach (array_slice($items, 0, 4) as $it) {
    $n = (string) ($LANG['items'][$it['id']] ?? '');
    if ($n !== '') {
        $nombres[] = $n;
    }
}
$descripcion = 'Los 20 ítems de ' . $nombre . ' en Draft 20: ' . implode(', ', $nombres)
    . ' y más. Subasta por turnos gratis para 2 jugadores, sin registro.';

$titulo = $nombre . ' — 20 ítems para jugar a Draft 20';

$itemList = [];
foreach ($items as $i => $it) {
    $itemList[] = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => (string) ($LANG['items'][$it['id']] ?? $it['id']),
    ];
}

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => (string) ($SEO['migas_inicio'] ?? 'Inicio'), 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($SEO['migas_tematicas'] ?? 'Temáticas'), 'item' => SITE_URL . '/#tematicas'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $nombre],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => (string) ($SEO['tematica_items_titulo'] ?? 'Ítems de {t}'),
        'numberOfItems' => count($items),
        'itemListElement' => $itemList,
    ],
];

pagina_head([
    'titulo' => $titulo,
    'descripcion' => $descripcion,
    'canonical' => '/tematica/' . $id,
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/"><?= e((string) ($SEO['migas_inicio'] ?? 'Inicio')) ?></a>
            <span class="mx-1">/</span>
            <a class="hover:text-amber-400" href="/#tematicas"><?= e((string) ($SEO['migas_tematicas'] ?? 'Temáticas')) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e($nombre) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-2"><?= e($tm['emoji'] . ' ' . $nombre) ?></h1>
        <p class="text-slate-400 text-sm mb-1"><?= e($tm['categoria_emoji'] . ' ' . $categoria) ?></p>
        <p class="text-slate-300 text-sm leading-relaxed mt-4 mb-6"><?= e(str_replace('{t}', $nombre, (string) ($SEO['tematica_intro'] ?? ''))) ?></p>

        <a href="/?tematica=<?= e($id) ?>#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-8"><?= e((string) ($SEO['tematica_cta'] ?? 'Jugar')) ?></a>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(str_replace('{t}', $nombre, (string) ($SEO['tematica_items_titulo'] ?? 'Ítems de {t}'))) ?></h2>
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-10">
            <?php foreach ($items as $it): ?>
            <li class="flex items-center gap-3 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2">
                <span class="text-2xl w-8 text-center flex-shrink-0"><?= e($it['emoji']) ?></span>
                <span class="text-sm text-slate-100"><?= e((string) ($LANG['items'][$it['id']] ?? $it['id'])) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e((string) ($SEO['tematica_relacionadas'] ?? 'Temáticas parecidas')) ?></h2>
        <ul class="flex flex-wrap gap-2 mb-6">
            <?php
            $relacionadas = 0;
            foreach (categorias() as $cat) {
                if ($cat['id'] !== $tm['categoria_id']) {
                    continue;
                }
                foreach ($cat['tematicas'] as $otra) {
                    if ($otra['id'] === $id) {
                        continue;
                    }
                    $relacionadas++;
                    if ($relacionadas > 8) {
                        break 2;
                    }
                    ?>
            <li>
                <a href="/tematica/<?= e($otra['id']) ?>" class="inline-block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-full px-3 py-1.5 text-sm text-slate-200"><?= e($otra['emoji'] . ' ' . nombre_tematica($otra['id'])) ?></a>
            </li>
                    <?php
                }
            }
            ?>
        </ul>
    </main>
<?php
pagina_foot();
