<?php
/**
 * Draft 20 — Ranking de temáticas (/ranking).
 *
 * Top 10 de lo más jugado por mes, generado solo con el registro anónimo
 * de partidas terminadas (sin cifras públicas: solo orden con medallas).
 * Sin slots de publicidad.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/api/ranking_lib.php';

$LANG = cargar_lang();
$R = is_array($LANG['ui']['paginas']['ranking'] ?? null) ? $LANG['ui']['paginas']['ranking'] : [];

$agg = ranking_humanas(__DIR__ . '/api/datos/partidas_humanas.jsonl', 0);
$meses = array_keys($agg['porMes']);
rsort($meses);
$mapa = mapa_tematicas();
$medallas = ['🥇', '🥈', '🥉'];

$faqRanking = is_array($R['faq'] ?? null) ? $R['faq'] : [];
$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => t('ui.nav.inicio'), 'item' => SITE_URL . url_lang('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($R['h1'] ?? 'Ranking de temáticas')],
        ],
    ],
];
$faqLdRanking = json_ld_faq($faqRanking);
if ($faqLdRanking !== null) {
    $jsonLd[] = $faqLdRanking;
}

pagina_head([
    'titulo' => (string) ($R['titulo'] ?? 'Ranking de temáticas más jugadas de Draft 20'),
    'descripcion' => (string) ($R['desc'] ?? ''),
    'canonical' => '/ranking',
    'og_image' => is_file(__DIR__ . '/og/seccion/ranking.png') ? SITE_URL . '/og/seccion/ranking.png' : SITE_URL . '/og-image.png',
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="<?= e(t('ui.comun.migas')) ?>">
            <a class="hover:text-amber-400" href="<?= e(url_lang('/')) ?>"><?= e(t('ui.nav.inicio')) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e((string) ($R['breadcrumb'] ?? t('ui.nav.ranking'))) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-3"><?= e((string) ($R['h1'] ?? 'Ranking de temáticas')) ?></h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-3"><?= e((string) ($R['intro1'] ?? '')) ?></p>
        <p class="text-sm text-slate-300 leading-relaxed mb-3"><?= e((string) ($R['intro2'] ?? '')) ?></p>
        <p class="text-sm text-slate-300 leading-relaxed mb-8"><?= e((string) ($R['intro3'] ?? '')) ?></p>

        <?php if ($meses === []): ?>
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-6 text-center mb-6">
            <p class="text-slate-300 text-sm"><?= e((string) ($R['vacio'] ?? '')) ?></p>
            <a href="<?= e(url_lang('/#app')) ?>" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mt-4"><?= e((string) ($R['jugar'] ?? 'Jugar ahora')) ?></a>
        </section>
        <?php endif; ?>

        <?php foreach ($meses as $mes): ?>
        <?php $top = array_slice(array_keys((array) ($agg['porMes'][$mes]['top'] ?? [])), 0, 10); ?>
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-4 sm:p-6 mb-4">
            <h2 class="text-xl font-bold text-amber-300 mb-4"><?= e(mes_anio($mes)) ?></h2>
            <ol class="space-y-2">
                <?php foreach ($top as $i => $id): ?>
                <?php $tm = $mapa[$id] ?? null; ?>
                <li class="flex items-center gap-3 bg-slate-700/60 rounded-lg px-3 py-2">
                    <span class="w-8 text-center text-lg" aria-hidden="true"><?= $i < 3 ? $medallas[$i] : ($i + 1) . '.' ?></span>
                    <?php if ($tm !== null): ?>
                    <a class="flex items-center gap-2 text-sm font-semibold text-slate-100 hover:text-amber-300" href="<?= e(url_lang('/tematica/' . $id)) ?>"><?= emoji_icono((string) $tm['emoji'], 20, '') ?> <?= e(nombre_tematica($id)) ?></a>
                    <?php else: ?>
                    <span class="text-sm font-semibold text-slate-100"><?= e($id) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endforeach; ?>

        <h2 class="text-xl font-bold text-slate-100 mt-8 mb-3"><?= e((string) ($R['faq_titulo'] ?? 'Preguntas sobre el ranking')) ?></h2>
        <div class="mb-8">
            <?php foreach ($faqRanking as $f): ?>
            <details class="bg-slate-800 border border-slate-700 rounded-lg p-4 mb-2">
                <summary class="font-semibold text-slate-100 cursor-pointer"><?= e((string) ($f['q'] ?? '')) ?></summary>
                <p class="text-sm text-slate-300 mt-2 leading-relaxed"><?= e((string) ($f['a'] ?? '')) ?></p>
            </details>
            <?php endforeach; ?>
        </div>

        <a href="<?= e(url_lang('/#app')) ?>" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6"><?= e((string) ($R['jugar'] ?? 'Jugar ahora')) ?></a>
    </main>
<?php
pagina_foot();
