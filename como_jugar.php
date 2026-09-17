<?php
/**
 * Draft 20 — Guía: cómo se juega (/como-jugar).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];
$UI   = $LANG['ui']['reglas'] ?? [];

$titulo = (string) ($SEO['como_jugar_titulo'] ?? 'Cómo se juega') . ' — ' . SITE_NOMBRE;

$secciones = [
    [(string) ($UI['obj_titulo'] ?? ''), (string) ($UI['obj_texto'] ?? '')],
    [(string) ($SEO['prep_titulo'] ?? ''), (string) ($SEO['prep_texto'] ?? '')],
    [(string) ($UI['sub_titulo'] ?? ''), (string) ($UI['sub_texto'] ?? '')],
    [(string) ($UI['cap_titulo'] ?? ''), (string) ($UI['cap_texto'] ?? '')],
    [(string) ($UI['dead_titulo'] ?? ''), (string) ($UI['dead_texto'] ?? '')],
    [(string) ($UI['fin_titulo'] ?? ''), (string) ($UI['fin_texto'] ?? '')],
    [(string) ($SEO['bot_titulo'] ?? ''), (string) ($SEO['bot_texto'] ?? '')],
    [(string) ($SEO['revancha_titulo'] ?? ''), (string) ($SEO['revancha_texto'] ?? '')],
];

$consejos = is_array($SEO['consejos'] ?? null) ? $SEO['consejos'] : [];

$pasosHowTo = [];
foreach ($secciones as $i => $s) {
    $pasosHowTo[] = [
        '@type' => 'HowToStep',
        'position' => $i + 1,
        'name' => (string) $s[0],
        'text' => (string) $s[1],
    ];
}

pagina_head([
    'titulo' => $titulo,
    'descripcion' => (string) ($SEO['como_jugar_desc'] ?? ''),
    'canonical' => '/como-jugar',
    'og_image' => SITE_URL . '/og/seccion/como-jugar.png',
    'json_ld' => [[
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $titulo,
        'description' => (string) ($SEO['como_jugar_desc'] ?? ''),
        'inLanguage' => 'es',
        'mainEntityOfPage' => SITE_URL . '/como-jugar',
    ], [
        '@context' => 'https://schema.org',
        '@type' => 'HowTo',
        'name' => $titulo,
        'description' => (string) ($SEO['como_jugar_desc'] ?? ''),
        'inLanguage' => 'es',
        'totalTime' => 'PT2M',
        'step' => $pasosHowTo,
    ]],
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-3"><?= e((string) ($SEO['como_jugar_titulo'] ?? '')) ?></h1>
        <p class="text-slate-300 text-sm leading-relaxed mb-8"><?= e((string) ($SEO['como_jugar_intro'] ?? '')) ?></p>

        <?php foreach ($secciones as $s): ?>
        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2"><?= e($s[0]) ?></h2>
            <p class="text-sm text-slate-300 leading-relaxed"><?= e($s[1]) ?></p>
        </section>
        <?php endforeach; ?>

        <?php if ($consejos !== []): ?>
        <section class="mb-8">
            <h2 class="text-lg font-bold text-amber-300 mb-2"><?= e((string) ($SEO['consejos_titulo'] ?? '')) ?></h2>
            <ul class="list-disc list-inside space-y-2 text-sm text-slate-300 leading-relaxed">
                <?php foreach ($consejos as $c): ?>
                <li><?= e((string) $c) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6"><?= e((string) ($SEO['hero_cta'] ?? 'Jugar')) ?></a>
    </main>
<?php
pagina_foot();
