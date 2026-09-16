<?php
/**
 * Draft 20 — Contacto (/contacto).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

pagina_head([
    'titulo' => (string) ($SEO['contacto_titulo'] ?? 'Contacto') . ' — ' . SITE_NOMBRE,
    'descripcion' => (string) ($SEO['contacto_desc'] ?? ''),
    'canonical' => '/contacto',
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-6"><?= e((string) ($SEO['contacto_titulo'] ?? '')) ?></h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-6"><?= e((string) ($SEO['contacto_texto'] ?? '')) ?></p>
        <a href="mailto:<?= e(SITE_EMAIL) ?>" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-2"><?= e((string) ($SEO['contacto_btn'] ?? 'Enviar correo')) ?></a>
        <p class="text-sm text-slate-400 font-mono mt-3 mb-6"><?= e(SITE_EMAIL) ?></p>
    </main>
<?php
pagina_foot();
