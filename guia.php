<?php
/**
 * Draft 20 — Guía individual (/guia/<slug>).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$slug = $_GET['slug'] ?? null;
$guia = is_string($slug) ? guia_contenido($slug) : null;
if ($guia === null) {
    pagina_404();
    exit;
}

$titulo = (string) $guia['titulo'];
$descripcion = (string) ($guia['desc'] ?? '');
$faq = is_array($guia['faq'] ?? null) ? $guia['faq'] : [];

$ogFile = __DIR__ . '/og/guia/' . $slug . '.png';
$ogGuia = is_file($ogFile) ? SITE_URL . '/og/guia/' . $slug . '.png' : SITE_URL . '/og-image.png';

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $titulo,
        'description' => $descripcion,
        'inLanguage' => 'es',
        'datePublished' => (string) ($guia['fecha'] ?? ''),
        'dateModified' => (string) ($guia['fecha'] ?? ''),
        'image' => $ogGuia,
        'url' => SITE_URL . '/guia/' . $slug,
        'mainEntityOfPage' => SITE_URL . '/guia/' . $slug,
        'author' => ['@id' => SITE_URL . '/#organizacion'],
        'publisher' => ['@id' => SITE_URL . '/#organizacion'],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => (string) ($SEO['migas_inicio'] ?? 'Inicio'), 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => seo_ui('migas_guias'), 'item' => SITE_URL . '/guias'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => (string) ($guia['h1'] ?? $titulo)],
        ],
    ],
];
$faqLd = json_ld_faq($faq);
if ($faqLd !== null) {
    $jsonLd[] = $faqLd;
}

pagina_head([
    'titulo' => $titulo . ' - ' . SITE_NOMBRE,
    'descripcion' => $descripcion,
    'canonical' => '/guia/' . $slug,
    'og_image' => $ogGuia,
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/"><?= e((string) ($SEO['migas_inicio'] ?? 'Inicio')) ?></a>
            <span class="mx-1">/</span>
            <a class="hover:text-amber-400" href="/guias"><?= e(seo_ui('migas_guias')) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e((string) ($guia['h1'] ?? $titulo)) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-2"><?= e((string) ($guia['h1'] ?? $titulo)) ?></h1>
        <p class="text-xs text-slate-400 mb-8"><?= e(seo_ui('guia_actualizado')) ?>: <?= e(fecha_es((string) ($guia['fecha'] ?? ''))) ?></p>

        <?php foreach ($guia['secciones'] as $i => $sec): ?>
        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2"><?= e((string) $sec['h']) ?></h2>
            <?php foreach ($sec['p'] as $p): ?>
            <p class="text-sm text-slate-300 leading-relaxed mb-3"><?= e((string) $p) ?></p>
            <?php endforeach; ?>
        </section>
        <?php if ($i === 1) { echo ads_slot(); } ?>
        <?php endforeach; ?>

        <?php if ($faq !== []): ?>
        <h2 class="text-lg font-bold text-amber-300 mb-3"><?= e(seo_ui('tematica_faq_titulo')) ?></h2>
        <div class="mb-8">
            <?php foreach ($faq as $f): ?>
            <details class="bg-slate-800 border border-slate-700 rounded-lg p-4 mb-2">
                <summary class="font-semibold text-slate-100 cursor-pointer"><?= e((string) ($f['q'] ?? '')) ?></summary>
                <p class="text-sm text-slate-300 mt-2 leading-relaxed"><?= e((string) ($f['a'] ?? '')) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($guia['enlaces'])): ?>
        <h2 class="text-lg font-bold text-amber-300 mb-3"><?= e(seo_ui('guia_enlaces_titulo')) ?></h2>
        <ul class="space-y-2 mb-8">
            <?php foreach ($guia['enlaces'] as $l): ?>
            <li>
                <a class="text-sm text-slate-200 hover:text-amber-400" href="<?= e((string) $l['href']) ?>"><?= e((string) $l['texto']) ?> →</a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h2 class="text-lg font-bold text-amber-300 mb-3"><?= e(seo_ui('guias_titulo')) ?></h2>
        <ul class="space-y-2 mb-8">
            <?php foreach (guias_ordenadas() as $otroSlug => $otra): if ($otroSlug === $slug) continue; ?>
            <li><a class="text-sm text-slate-200 hover:text-amber-400" href="/guia/<?= e($otroSlug) ?>"><?= e((string) $otra['titulo']) ?> →</a></li>
            <?php endforeach; ?>
        </ul>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6"><?= e((string) ($SEO['hero_cta'] ?? 'Jugar')) ?></a>
    </main>
<?php
pagina_foot();
