<?php
/**
 * Draft 20 — Preguntas frecuentes (/preguntas-frecuentes).
 *
 * Todo el texto se sirve en el HTML del servidor para que los crawlers
 * lo lean sin JavaScript. Acordeones <details> accesibles + JSON-LD FAQPage.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$pagina = require __DIR__ . '/contenido_faq.php';

$titulo = (string) $pagina['titulo'];
$descripcion = (string) ($pagina['desc'] ?? '');
$faq = is_array($pagina['faq'] ?? null) ? $pagina['faq'] : [];
$ogFaq = SITE_URL . '/og-image.png';

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $titulo,
        'description' => $descripcion,
        'inLanguage' => 'es',
        'datePublished' => (string) ($pagina['fecha'] ?? ''),
        'dateModified' => (string) ($pagina['fecha'] ?? ''),
        'image' => $ogFaq,
        'url' => SITE_URL . '/preguntas-frecuentes',
        'mainEntityOfPage' => SITE_URL . '/preguntas-frecuentes',
        'author' => ['@id' => SITE_URL . '/#organizacion'],
        'publisher' => ['@id' => SITE_URL . '/#organizacion'],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => (string) ($SEO['migas_inicio'] ?? 'Inicio'), 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($pagina['h1'] ?? $titulo)],
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
    'canonical' => '/preguntas-frecuentes',
    'og_image' => $ogFaq,
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/"><?= e((string) ($SEO['migas_inicio'] ?? 'Inicio')) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e((string) ($pagina['h1'] ?? $titulo)) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-2"><?= e((string) ($pagina['h1'] ?? $titulo)) ?></h1>
        <p class="text-xs text-slate-400 mb-8"><?= e(seo_ui('guia_actualizado')) ?>: <?= e(fecha_es((string) ($pagina['fecha'] ?? ''))) ?></p>

        <?php foreach ((array) ($pagina['intro'] ?? []) as $p): ?>
        <p class="text-sm text-slate-300 leading-relaxed mb-6"><?= e((string) $p) ?></p>
        <?php endforeach; ?>

        <?= ads_slot() ?>

        <div class="mb-8">
            <?php foreach ($faq as $f): ?>
            <details class="bg-slate-800 border border-slate-700 rounded-lg p-4 mb-2">
                <summary class="font-semibold text-slate-100 cursor-pointer"><?= e((string) ($f['q'] ?? '')) ?></summary>
                <p class="text-sm text-slate-300 mt-2 leading-relaxed"><?= e((string) ($f['a'] ?? '')) ?></p>
            </details>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($pagina['enlaces'])): ?>
        <h2 class="text-xl font-bold text-slate-100 mt-8 mb-3"><?= e(seo_ui('guia_enlaces_titulo')) ?></h2>
        <ul class="space-y-2 mb-8">
            <?php foreach ($pagina['enlaces'] as $l): ?>
            <li>
                <a class="text-sm text-slate-200 hover:text-amber-400" href="<?= e((string) $l['href']) ?>"><?= e((string) $l['texto']) ?> →</a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6"><?= e((string) ($SEO['hero_cta'] ?? 'Jugar')) ?></a>
    </main>
<?php
pagina_foot();
