<?php
/**
 * Draft 20 — Acerca de (/acerca).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

pagina_head([
    'titulo' => (string) ($SEO['acerca_titulo'] ?? 'Acerca de') . ' — ' . SITE_NOMBRE,
    'descripcion' => (string) ($SEO['acerca_desc'] ?? ''),
    'canonical' => '/acerca',
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-6"><?= e((string) ($SEO['acerca_titulo'] ?? '')) ?></h1>
        <?php foreach (['acerca_p1', 'acerca_p2', 'acerca_p3'] as $clave): ?>
        <p class="text-sm text-slate-300 leading-relaxed mb-4"><?= e((string) ($SEO[$clave] ?? '')) ?></p>
        <?php endforeach; ?>
        <a href="/#app" class="inline-block mt-4 bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6"><?= e((string) ($SEO['hero_cta'] ?? 'Jugar')) ?></a>
    </main>
<?php
pagina_foot();
