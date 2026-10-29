<?php
/**
 * Draft 20 — Términos de uso (/terminos).
 *
 * Condiciones del servicio: uso gratuito sin cuenta, conducta, disponibilidad
 * y límites. Complementa al aviso legal (datos del titular, LSSI-CE) y a la
 * política de privacidad (datos). Sin slots de publicidad, como el resto
 * de páginas legales (privacidad, aviso legal) y de servicio (contacto).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$T = is_array($LANG['ui']['paginas']['terminos'] ?? null) ? $LANG['ui']['paginas']['terminos'] : [];
$secciones = is_array($T['secciones'] ?? null) ? $T['secciones'] : [];

pagina_head([
    'titulo' => (string) ($T['meta_titulo'] ?? 'Términos de uso') . ' — ' . SITE_NOMBRE,
    'descripcion' => (string) ($T['meta_desc'] ?? ''),
    'canonical' => '/terminos',
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-3"><?= e((string) ($T['h1'] ?? 'Términos de uso')) ?></h1>
        <p class="text-slate-300 text-sm leading-relaxed mb-8"><?= e((string) ($T['intro'] ?? '')) ?></p>

        <?php foreach ($secciones as $s): ?>
        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2"><?= e((string) ($s['h'] ?? '')) ?></h2>
            <?php foreach ((array) ($s['p'] ?? []) as $p): ?>
            <p class="text-sm text-slate-300 leading-relaxed"><?= e((string) $p) ?></p>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <p class="text-sm text-slate-300 leading-relaxed mb-6">
            <?= texto_con_enlaces((string) ($T['enlaces'] ?? ''), [
                'privacidad' => '<a class="hover:text-amber-400" href="' . e(url_lang('/privacidad')) . '">' . e((string) ($T['privacidad'] ?? '')) . '</a>',
                'aviso' => '<a class="hover:text-amber-400" href="' . e(url_lang('/aviso-legal')) . '">' . e((string) ($T['aviso'] ?? '')) . '</a>',
            ]) ?>
        </p>

        <p class="text-xs text-slate-400 mt-8 mb-6"><?= e(t('ui.comun.ultima_actualizacion')) ?>: <?= e(mes_anio(date('Y-m'))) ?></p>
    </main>
<?php
pagina_foot();
