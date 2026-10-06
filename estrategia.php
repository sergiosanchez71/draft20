<?php
/**
 * Draft 20 — Pilar de estrategia (/como-jugar-y-estrategia).
 *
 * Manual completo: reglas de las 8 rondas + estrategia (banca, psicología
 * y temáticas). Todo el texto se sirve en el HTML del servidor para que
 * los crawlers lo lean sin JavaScript.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$guia = require __DIR__ . '/contenido_estrategia.php';

$titulo = (string) $guia['titulo'];
$descripcion = (string) ($guia['desc'] ?? '');
$ogEstrategia = SITE_URL . '/og-image.png';

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $titulo,
        'description' => $descripcion,
        'inLanguage' => 'es',
        'datePublished' => (string) ($guia['fecha'] ?? ''),
        'dateModified' => (string) ($guia['fecha'] ?? ''),
        'image' => $ogEstrategia,
        'url' => SITE_URL . '/como-jugar-y-estrategia',
        'mainEntityOfPage' => SITE_URL . '/como-jugar-y-estrategia',
        'author' => ['@id' => SITE_URL . '/#organizacion'],
        'publisher' => ['@id' => SITE_URL . '/#organizacion'],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => (string) ($SEO['migas_inicio'] ?? 'Inicio'), 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($guia['h1'] ?? $titulo)],
        ],
    ],
];

pagina_head([
    'titulo' => $titulo . ' - ' . SITE_NOMBRE,
    'descripcion' => $descripcion,
    'canonical' => '/como-jugar-y-estrategia',
    'og_image' => $ogEstrategia,
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/"><?= e((string) ($SEO['migas_inicio'] ?? 'Inicio')) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e((string) ($guia['h1'] ?? $titulo)) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-2"><?= e((string) ($guia['h1'] ?? $titulo)) ?></h1>
        <p class="text-xs text-slate-400 mb-8"><?= e(seo_ui('guia_actualizado')) ?>: <?= e(fecha_es((string) ($guia['fecha'] ?? ''))) ?> · Por el Equipo Draft 20</p>

        <?php foreach ((array) ($guia['intro'] ?? []) as $p): ?>
        <p class="text-sm text-slate-300 leading-relaxed mb-3"><?= e((string) $p) ?></p>
        <?php endforeach; ?>

        <?= ads_slot() ?>

        <?php foreach ((array) ($guia['bloques'] ?? []) as $b): ?>
            <?php if (isset($b['h2'])): ?>
        <h2 class="text-xl font-bold text-slate-100 mt-8 mb-3"><?= e((string) $b['h2']) ?></h2>
            <?php elseif (isset($b['h3'])): ?>
        <h3 class="text-lg font-bold text-amber-300 mt-6 mb-2"><?= e((string) $b['h3']) ?></h3>
            <?php elseif (isset($b['ul'])): ?>
        <ul class="list-disc list-inside space-y-2 text-sm text-slate-300 leading-relaxed mb-4">
                <?php foreach ((array) $b['ul'] as $li): ?>
            <li><?= e((string) $li) ?></li>
                <?php endforeach; ?>
        </ul>
            <?php elseif (isset($b['p'])): ?>
                <?php foreach ((array) $b['p'] as $p): ?>
        <p class="text-sm text-slate-300 leading-relaxed mb-3"><?= e((string) $p) ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!empty($guia['enlaces'])): ?>
        <h2 class="text-xl font-bold text-slate-100 mt-8 mb-3"><?= e(seo_ui('guia_enlaces_titulo')) ?></h2>
        <ul class="space-y-2 mb-8">
            <?php foreach ($guia['enlaces'] as $l): ?>
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
