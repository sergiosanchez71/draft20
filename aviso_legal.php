<?php
/**
 * Draft 20 — Aviso legal (/aviso-legal).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$A = is_array($LANG['ui']['paginas']['aviso'] ?? null) ? $LANG['ui']['paginas']['aviso'] : [];
$secciones = is_array($A['secciones'] ?? null) ? $A['secciones'] : [];
$enlaces = [
    'email' => '<a class="hover:text-amber-400" href="mailto:' . e(SITE_EMAIL) . '">' . e(SITE_EMAIL) . '</a>',
    'privacidad' => '<a class="hover:text-amber-400" href="' . e(url_lang('/privacidad')) . '">' . e(t('ui.nav.privacidad')) . '</a>',
    'fluent' => '<a class="hover:text-amber-400" href="https://github.com/microsoft/fluentui-emoji" rel="noopener nofollow">github.com/microsoft/fluentui-emoji</a>',
];

pagina_head([
    'titulo' => (string) ($A['meta_titulo'] ?? 'Aviso legal') . ' — ' . SITE_NOMBRE,
    'descripcion' => (string) ($A['meta_desc'] ?? ''),
    'canonical' => '/aviso-legal',
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-3"><?= e((string) ($A['h1'] ?? 'Aviso legal')) ?></h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-6"><?= e((string) ($A['intro'] ?? '')) ?></p>

        <?php foreach ($secciones as $s): ?>
        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2"><?= e((string) ($s['h'] ?? '')) ?></h2>
            <?php foreach ((array) ($s['p'] ?? []) as $p): ?>
            <p class="text-sm text-slate-300 leading-relaxed mt-2"><?= texto_con_enlaces((string) $p, $enlaces) ?></p>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <?php if (idioma_actual() === 'en'): ?>
        <p class="text-xs text-slate-400 leading-relaxed mb-2"><?= e((string) ($A['disclaimer'] ?? 'In case of discrepancy, the Spanish version prevails.')) ?></p>
        <?php endif; ?>
        <p class="text-xs text-slate-400 mt-8 mb-6"><?= e(t('ui.comun.ultima_actualizacion')) ?>: <?= e(mes_anio(date('Y-m'))) ?></p>
    </main>
<?php
pagina_foot();
