<?php
/**
 * Draft 20 — Landing + lobby.
 *
 * La parte SEO (H1, texto, cómo se juega, temáticas y FAQ) se renderiza en
 * servidor; el lobby interactivo (crear/unirse/practicar) lo pinta app.core.js
 * dentro de <main id="app">.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$salaFromLink = $_GET['sala'] ?? null;
if (!is_string($salaFromLink) || !preg_match('/^[A-Z0-9]{5}$/', $salaFromLink)) {
    $salaFromLink = null;
}

$tematicaPre = $_GET['tematica'] ?? null;
if (!is_string($tematicaPre) || !isset(mapa_tematicas()[$tematicaPre])) {
    $tematicaPre = null;
}

$categorias = categorias();
$numTematicas = count(mapa_tematicas());
$num = static fn(string $s): string => str_replace('{n}', (string) $numTematicas, $s);

$faq = is_array($SEO['faq'] ?? null) ? $SEO['faq'] : [];
$faq = array_map(static function (array $f) use ($num): array {
    $f['q'] = $num((string) ($f['q'] ?? ''));
    $f['a'] = $num((string) ($f['a'] ?? ''));
    return $f;
}, $faq);
$descripcion = $num((string) ($SEO['home_desc'] ?? ''));

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => SITE_NOMBRE,
        'url' => SITE_URL . '/',
        'description' => $descripcion,
        'applicationCategory' => 'GameApplication',
        'operatingSystem' => 'Web',
        'inLanguage' => 'es',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
    ],
];
if ($faq !== []) {
    $jsonLd[] = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(static function (array $f): array {
            return [
                '@type' => 'Question',
                'name' => (string) ($f['q'] ?? ''),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) ($f['a'] ?? '')],
            ];
        }, $faq),
    ];
}

pagina_head([
    'titulo' => (string) ($SEO['home_titulo'] ?? SITE_NOMBRE),
    'descripcion' => $descripcion,
    'canonical' => '/',
    // La vista de unirse por enlace (?sala=CODE) no debe indexarse.
    'robots' => $salaFromLink ? 'noindex, follow' : 'index, follow',
    'json_ld' => $jsonLd,
    // El bundle del lobby se descubre en el <head> y llega antes (SI/LCP).
    'preload_scripts' => ['js/app.core.js'],
]);
?>
    <div class="min-h-screen flex flex-col">
        <header class="px-6 pt-8 pb-2 text-center safe-pt">
            <h1 class="text-4xl font-bold text-amber-400"><?= e(SITE_NOMBRE) ?></h1>
            <p class="text-slate-400 text-sm mt-2"><?= e((string) ($LANG['ui']['app']['subtitulo_lobby'] ?? '')) ?></p>
            <p class="text-slate-300 text-sm mt-4 max-w-xl mx-auto leading-relaxed"><?= e((string) ($SEO['hero_texto'] ?? '')) ?></p>
            <a href="#app" class="inline-block mt-5 bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap"><?= e((string) ($SEO['hero_cta'] ?? 'Jugar')) ?></a>
        </header>

        <main class="flex-1 flex flex-col">
            <div id="app" class="flex flex-col"></div>

            <section class="max-w-3xl mx-auto w-full px-4 mt-10">
                <details class="acordeon bg-slate-800 border border-slate-700 rounded-lg">
                    <summary class="flex items-center justify-between gap-2 px-4 py-3 cursor-pointer font-bold text-xl text-slate-100">
                        <span><?= e((string) ($SEO['como_titulo'] ?? '')) ?></span>
                        <span class="chev text-base text-slate-400" aria-hidden="true">▾</span>
                    </summary>
                    <div class="px-4 pb-4">
                        <ol class="space-y-2 text-slate-300 text-sm list-decimal list-inside leading-relaxed">
                            <?php foreach (['como_paso1', 'como_paso2', 'como_paso3', 'como_paso4'] as $clave): ?>
                            <li><?= e($num((string) ($SEO[$clave] ?? ''))) ?></li>
                            <?php endforeach; ?>
                        </ol>
                        <a href="/como-jugar" class="inline-block mt-4 text-amber-400 hover:text-amber-300 text-sm font-semibold"><?= e((string) ($SEO['como_mas'] ?? '')) ?> →</a>
                    </div>
                </details>
            </section>

            <section id="tematicas" class="max-w-5xl mx-auto w-full px-4 mt-10">
                <h2 class="text-2xl font-bold text-slate-100 mb-2"><?= e((string) ($SEO['tematicas_titulo'] ?? '')) ?></h2>
                <p class="text-slate-400 text-sm mb-6"><?= e($num((string) ($SEO['tematicas_sub'] ?? ''))) ?></p>
                <?php foreach ($categorias as $cat): ?>
                <details class="acordeon bg-slate-800 border border-slate-700 rounded-lg mb-2">
                    <summary class="flex items-center justify-between gap-2 px-4 py-3 cursor-pointer font-bold text-amber-300">
                        <span><?= e($cat['emoji'] . ' ' . nombre_categoria($cat['id'])) ?></span>
                        <span class="flex items-center gap-2 text-xs font-normal text-slate-400">
                            <?= e(str_replace('{n}', (string) count($cat['tematicas']), (string) ($SEO['tematicas_contador'] ?? ''))) ?>
                            <span class="chev" aria-hidden="true">▾</span>
                        </span>
                    </summary>
                    <ul class="flex flex-wrap gap-2 px-4 pb-4">
                        <?php foreach ($cat['tematicas'] as $tm): ?>
                        <li>
                            <a href="/tematica/<?= e($tm['id']) ?>" class="inline-block bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-full px-3 py-1.5 text-sm text-slate-200"><?= e($tm['emoji'] . ' ' . nombre_tematica($tm['id'])) ?></a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php endforeach; ?>
            </section>

            <?php if ($faq !== []): ?>
            <section class="max-w-3xl mx-auto w-full px-4 mt-10">
                <h2 class="text-2xl font-bold text-slate-100 mb-4"><?= e((string) ($SEO['faq_titulo'] ?? '')) ?></h2>
                <?php foreach ($faq as $f): ?>
                <details class="bg-slate-800 border border-slate-700 rounded-lg p-4 mb-2">
                    <summary class="font-semibold text-slate-100 cursor-pointer"><?= e((string) ($f['q'] ?? '')) ?></summary>
                    <p class="text-sm text-slate-300 mt-2 leading-relaxed"><?= e((string) ($f['a'] ?? '')) ?></p>
                </details>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <section class="text-center mt-10 px-4">
                <a href="#app" class="inline-block bg-emerald-500 text-white font-bold py-3 px-6 rounded-lg btn-tap"><?= e((string) ($SEO['cta_final'] ?? '')) ?></a>
            </section>
        </main>
    </div>
<?php

// i18n del cliente: el lobby solo necesita ui + nombres de temáticas/categorías.
// Los nombres de los ítems (pesados) se quedan fuera de la landing.
$langCliente = [
    'ui' => $LANG['ui'] ?? [],
    'tematicas' => $LANG['tematicas'] ?? [],
    'tematicas_categorias' => $LANG['tematicas_categorias'] ?? [],
];

$inlineFirst = 'window.LANG = ' . json_encode($langCliente, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__CATEGORIAS = ' . json_encode($categorias, JSON_UNESCAPED_UNICODE) . ';'
    . ($tematicaPre !== null ? 'window.__tematicaPre = ' . json_encode($tematicaPre) . ';' : '');

$inline = '(function () {'
    . ' const linkSala = ' . json_encode($salaFromLink, JSON_UNESCAPED_UNICODE) . ';'
    // app.core.min.js va con defer: esperamos a que el bundle esté ejecutado.
    . ' document.addEventListener("DOMContentLoaded", function () { window.__init(linkSala); });'
    . ' if ("serviceWorker" in navigator) { window.addEventListener("load", function () {'
    . ' navigator.serviceWorker.register("/sw.js?v=' . (is_file(__DIR__ . '/sw.js') ? filemtime(__DIR__ . '/sw.js') : '1') . '").catch(function () {});'
    . ' }); }'
    . '})();';

pagina_foot([
    'inline_first' => $inlineFirst,
    'scripts' => ['js/app.core.js'],
    'inline' => $inline,
    'defer' => true,
]);
