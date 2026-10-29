<?php
/**
 * Draft 20 — Glosario (/glosario).
 *
 * Definiciones de los términos del juego con DefinedTermSet.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$G = is_array($LANG['ui']['paginas']['glosario'] ?? null) ? $LANG['ui']['paginas']['glosario'] : [];
$terminos = is_array($G['terminos'] ?? null) ? $G['terminos'] : [];

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => t('ui.nav.inicio'), 'item' => SITE_URL . url_lang('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => t('ui.nav.glosario')],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'DefinedTermSet',
        'name' => (string) ($G['h1'] ?? 'Glosario de Draft 20'),
        'inLanguage' => idioma_bcp47(),
        'hasDefinedTerm' => array_map(static function (array $t): array {
            return [
                '@type' => 'DefinedTerm',
                'name' => $t['nombre'],
                'description' => $t['def'],
                'url' => SITE_URL . url_lang('/glosario#' . $t['id']),
            ];
        }, $terminos),
    ],
];

pagina_head([
    'titulo' => (string) ($G['titulo'] ?? 'Glosario de Draft 20: draft, subasta, puja y más'),
    'descripcion' => (string) ($G['desc'] ?? ''),
    'canonical' => '/glosario',
    'og_image' => SITE_URL . '/og/seccion/glosario.png',
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="<?= e(t('ui.comun.migas')) ?>">
            <a class="hover:text-amber-400" href="<?= e(url_lang('/')) ?>"><?= e(t('ui.nav.inicio')) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e(t('ui.nav.glosario')) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-3"><?= e((string) ($G['h1'] ?? 'Glosario de Draft 20')) ?></h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-8">
            <?= e((string) ($G['intro_antes'] ?? '')) ?><a class="text-amber-400 hover:text-amber-300" href="<?= e(url_lang('/como-jugar')) ?>"><?= e((string) ($G['intro_enlace'] ?? '')) ?></a><?= e((string) ($G['intro_despues'] ?? '')) ?>
        </p>

        <dl class="space-y-3 mb-10">
            <?php foreach ($terminos as $t): ?>
            <div id="<?= e($t['id']) ?>" class="bg-slate-800 border border-slate-700 rounded-lg p-4">
                <dt class="font-bold text-amber-300"><?= e($t['nombre']) ?></dt>
                <dd class="text-sm text-slate-300 leading-relaxed mt-1"><?= e($t['def']) ?></dd>
                <?php if (!empty($t['enlace'])): ?>
                <dd class="mt-2"><a class="text-xs font-semibold text-slate-400 hover:text-amber-400" href="<?= e(url_lang((string) $t['enlace'])) ?>"><?= e((string) ($G['ver_mas'] ?? 'Ver más')) ?> →</a></dd>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </dl>

        <?= ads_slot() ?>

        <a href="<?= e(url_lang('/#app')) ?>" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6"><?= e((string) ($G['jugar'] ?? 'Jugar ahora')) ?></a>
    </main>
<?php
pagina_foot();
