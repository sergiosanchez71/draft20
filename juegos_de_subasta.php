<?php
/**
 * Draft 20 — Hub SEO: juegos de subasta (/juegos-de-subasta).
 *
 * Página de intención amplia que enlaza a fichas, categorías, guías y glosario.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$hub = require __DIR__ . '/contenido_hub_subasta.php';

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Juegos de subasta'],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => (string) $hub['titulo'],
        'description' => (string) $hub['desc'],
        'inLanguage' => 'es',
        'isPartOf' => ['@type' => 'WebSite', 'name' => SITE_NOMBRE, 'url' => SITE_URL . '/'],
    ],
];
$faqLd = json_ld_faq((array) ($hub['faq'] ?? []));
if ($faqLd !== null) {
    $jsonLd[] = $faqLd;
}

pagina_head([
    'titulo' => (string) $hub['titulo'] . ' - ' . SITE_NOMBRE,
    'descripcion' => (string) $hub['desc'],
    'canonical' => '/juegos-de-subasta',
    'og_image' => SITE_URL . '/og/seccion/juegos-de-subasta.png',
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/">Inicio</a>
            <span class="mx-1">/</span>
            <span class="text-slate-300">Juegos de subasta</span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-3"><?= e((string) $hub['h1']) ?></h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-8"><?= e((string) $hub['intro']) ?></p>

        <?php foreach ((array) $hub['secciones'] as $s): ?>
        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2"><?= e((string) $s['h']) ?></h2>
            <?php foreach ((array) $s['p'] as $parrafo): ?>
            <p class="text-sm text-slate-300 leading-relaxed mb-2"><?= e((string) $parrafo) ?></p>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <?= ads_slot() ?>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-8">Jugar a Draft 20 gratis</a>

        <?php if (!empty($hub['faq'])): ?>
        <section class="mb-8">
            <h2 class="text-lg font-bold text-amber-300 mb-3">Preguntas frecuentes</h2>
            <dl class="space-y-3">
                <?php foreach ((array) $hub['faq'] as $f): ?>
                <div class="bg-slate-800 border border-slate-700 rounded-lg p-4">
                    <dt class="font-bold text-slate-100 text-sm"><?= e((string) $f['q']) ?></dt>
                    <dd class="text-sm text-slate-300 leading-relaxed mt-1"><?= e((string) $f['a']) ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
        </section>
        <?php endif; ?>

        <?php if (!empty($hub['enlaces'])): ?>
        <section class="mb-10">
            <h2 class="text-lg font-bold text-amber-300 mb-3">Sigue leyendo</h2>
            <ul class="flex flex-wrap gap-2">
                <?php foreach ((array) $hub['enlaces'] as $l): ?>
                <li><a class="inline-block bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-full px-3 py-1.5 text-sm text-slate-200" href="<?= e((string) $l['href']) ?>"><?= e((string) $l['texto']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </main>
<?php
pagina_foot();
