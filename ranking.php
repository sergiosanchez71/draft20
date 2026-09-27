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

$MESES = ['01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'];
$nombreMes = static function (string $ym) use ($MESES): string {
    [$y, $m] = explode('-', $ym) + [null, null];
    return ($MESES[$m ?? ''] ?? $ym) . ' ' . ($y ?? '');
};

$agg = ranking_humanas(__DIR__ . '/api/datos/partidas_humanas.jsonl', 0);
$meses = array_keys($agg['porMes']);
rsort($meses);
$mapa = mapa_tematicas();
$medallas = ['🥇', '🥈', '🥉'];

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Ranking de temáticas'],
        ],
    ],
];

pagina_head([
    'titulo' => 'Ranking de temáticas más jugadas de Draft 20',
    'descripcion' => 'El top 10 de las temáticas más jugadas de Draft 20 cada mes, con medallas de oro, plata y bronce. Datos anónimos de partidas terminadas.',
    'canonical' => '/ranking',
    'og_image' => is_file(__DIR__ . '/og/seccion/ranking.png') ? SITE_URL . '/og/seccion/ranking.png' : SITE_URL . '/og-image.png',
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/">Inicio</a>
            <span class="mx-1">/</span>
            <span class="text-slate-300">Ranking</span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-3">Ranking de temáticas</h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-2">
            Lo más jugado cada mes, ordenado por partidas terminadas. Sin cifras: aquí mandan las medallas.
        </p>
        <p class="text-xs text-slate-400 leading-relaxed mb-8">
            Datos anónimos (temática, modo y hora; sin nombres ni identificadores) que se actualizan solos al terminar cada partida. Más detalle en la <a class="text-amber-400 hover:text-amber-300" href="/privacidad">privacidad</a>.
        </p>

        <?php if ($meses === []): ?>
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-6 text-center mb-6">
            <p class="text-slate-300 text-sm">Todavía no hay partidas registradas. ¡Sé la primera persona en entrar al ranking!</p>
            <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mt-4">Jugar ahora</a>
        </section>
        <?php endif; ?>

        <?php foreach ($meses as $mes): ?>
        <?php $top = array_slice($agg['porMes'][$mes]['top'] ?? [], 0, 10); ?>
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-4 sm:p-6 mb-4">
            <h2 class="text-xl font-bold text-amber-300 mb-4"><?= e($nombreMes($mes)) ?></h2>
            <ol class="space-y-2">
                <?php foreach ($top as $i => $id): ?>
                <?php $tm = $mapa[$id] ?? null; ?>
                <li class="flex items-center gap-3 bg-slate-700/60 rounded-lg px-3 py-2">
                    <span class="w-8 text-center text-lg" aria-hidden="true"><?= $i < 3 ? $medallas[$i] : ($i + 1) . '.' ?></span>
                    <?php if ($tm !== null): ?>
                    <a class="flex items-center gap-2 text-sm font-semibold text-slate-100 hover:text-amber-300" href="/tematica/<?= e($id) ?>"><?= emoji_icono((string) $tm['emoji'], 20, '') ?> <?= e(nombre_tematica($id)) ?></a>
                    <?php else: ?>
                    <span class="text-sm font-semibold text-slate-100"><?= e($id) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endforeach; ?>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6">Jugar ahora</a>
    </main>
<?php
pagina_foot();
