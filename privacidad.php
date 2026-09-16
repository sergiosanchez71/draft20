<?php
/**
 * Draft 20 — Privacidad (/privacidad).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$secciones = [
    ['priv_datos_titulo', 'priv_datos_texto'],
    ['priv_local_titulo', 'priv_local_texto'],
    ['priv_terceros_titulo', 'priv_terceros_texto'],
    ['priv_contacto_titulo', 'priv_contacto_texto'],
];

pagina_head([
    'titulo' => (string) ($SEO['privacidad_titulo'] ?? 'Privacidad') . ' — ' . SITE_NOMBRE,
    'descripcion' => (string) ($SEO['privacidad_desc'] ?? ''),
    'canonical' => '/privacidad',
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-3"><?= e((string) ($SEO['privacidad_titulo'] ?? '')) ?></h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-8"><?= e((string) ($SEO['privacidad_intro'] ?? '')) ?></p>
        <?php foreach ($secciones as $s): ?>
        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2"><?= e((string) ($SEO[$s[0]] ?? '')) ?></h2>
            <p class="text-sm text-slate-300 leading-relaxed"><?= e((string) ($SEO[$s[1]] ?? '')) ?></p>
        </section>
        <?php endforeach; ?>
        <p class="text-xs text-slate-500 mt-8 mb-6">Última actualización: <?= e(date('m/Y')) ?></p>
    </main>
<?php
pagina_foot();
