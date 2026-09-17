<?php
/**
 * Draft 20 — Helpers compartidos de las páginas públicas (SEO).
 *
 * Centraliza: constantes del sitio, i18n de servidor, catálogo de temáticas,
 * <head> con metas/canonical/OG/JSON-LD, cabecera, pie y assets versionados.
 */
declare(strict_types=1);

const SITE_URL   = 'https://draft20.es';
const SITE_NOMBRE = 'Draft 20';
const SITE_EMAIL = 'contacto@draft20.es';

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function cargar_lang(): array
{
    static $lang = null;
    if ($lang === null) {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/../lang/es.json'), true);
        $lang = is_array($raw) ? $raw : [];
    }
    return $lang;
}

/** @return array<int, array{id:string, emoji:string, tematicas:array<int, array{id:string, emoji:string}>}> */
function categorias(): array
{
    static $cats = null;
    if ($cats === null) {
        $cats = require __DIR__ . '/../tematicas_catalogo.php';
    }
    return $cats;
}

/** Mapa id de temática → datos de su categoría. @return array<string, array{id:string,emoji:string,categoria_id:string,categoria_emoji:string}> */
function mapa_tematicas(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (categorias() as $cat) {
        foreach ($cat['tematicas'] as $tm) {
            $map[$tm['id']] = [
                'id' => $tm['id'],
                'emoji' => $tm['emoji'],
                'categoria_id' => $cat['id'],
                'categoria_emoji' => $cat['emoji'],
            ];
        }
    }
    return $map;
}

function nombre_tematica(string $id): string
{
    $lang = cargar_lang();
    return (string) ($lang['tematicas'][$id] ?? $id);
}

function nombre_categoria(string $id): string
{
    $lang = cargar_lang();
    return (string) ($lang['tematicas_categorias'][$id] ?? $id);
}

/** @return array<int, array{id:string,emoji:string,valor:int}> */
function items_tematica(string $id): array
{
    if (!isset(mapa_tematicas()[$id])) {
        return [];
    }
    $file = __DIR__ . '/../tematicas/' . $id . '.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data['items'] ?? null) ? $data['items'] : [];
}

/** Slug del icono (codepoints hex sin FE0F, unidos por '-'). */
function emoji_slug(string $emoji): string
{
    $cps = [];
    $chars = preg_split('//u', $emoji, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $ch) {
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === 0xFE0F) {
            continue;
        }
        $cps[] = dechex($cp);
    }
    return implode('-', $cps);
}

/** Icono Fluent Emoji (SVG, MIT) con fallback al emoji de texto si falta. */
function emoji_icono(string $emoji, int $size = 32, string $alt = ''): string
{
    $slug = emoji_slug($emoji);
    if ($slug === '' || !is_file(__DIR__ . '/../img/emoji/' . $slug . '.svg')) {
        return '<span>' . e($emoji) . '</span>';
    }
    return '<img src="/img/emoji/' . e($slug) . '.svg" alt="' . e($alt) . '" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async" class="inline-block align-middle">';
}

/** Ruta relativa con ?v=filemtime para esquivar la caché del CDN. */
function asset(string $rel): string
{
    $abs = __DIR__ . '/../' . ltrim($rel, '/');
    $v = is_file($abs) ? filemtime($abs) : null;
    return $rel . ($v !== null ? '?v=' . $v : '');
}

/** JS: usa la versión minificada si existe (npm run build). */
function asset_js(string $rel): string
{
    $min = preg_replace('/\.js$/', '.min.js', $rel);
    if ($min !== null && is_file(__DIR__ . '/../' . ltrim($min, '/'))) {
        return asset($min);
    }
    return asset($rel);
}

/**
 * Nonce CSP por petición: firma los scripts inline (JSON-LD, datos de arranque).
 * El header va en enforcing desde PHP porque el nonce no puede vivir en .htaccess.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

function csp_headers(): void
{
    if (headers_sent()) {
        return;
    }
    $n = csp_nonce();
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $n . "'; " .
        "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; " .
        "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    );
}

/** Tailwind: usa el CSS compilado si existe; si no, cae al CDN (desarrollo). */
function tailwind_tag(): string
{
    if (is_file(__DIR__ . '/../css/tailwind.css')) {
        return '<link rel="stylesheet" href="' . e(asset('css/tailwind.css')) . '">' . "\n    ";
    }
    return '<script src="https://cdn.tailwindcss.com"></script>' . "\n    ";
}

/**
 * CSS de la página: inline por defecto en las páginas SEO (elimina requests
 * render-blocking) o enlazado en la app (juego).
 */
function css_tags(bool $inline = true): string
{
    $tail = __DIR__ . '/../css/tailwind.css';
    $estilo = __DIR__ . '/../css/style.css';
    if ($inline && is_file($tail)) {
        $css = (string) file_get_contents($tail);
        if (is_file($estilo)) {
            $css .= "\n" . (string) file_get_contents($estilo);
        }
        return '<style>' . $css . '</style>';
    }
    return tailwind_tag() . '<link rel="stylesheet" href="' . e(asset('css/style.css')) . '">';
}

function nav_links(): array
{
    return [
        ['href' => '/como-jugar', 'texto' => 'Cómo se juega'],
        ['href' => '/guias', 'texto' => 'Guías'],
        ['href' => '/glosario', 'texto' => 'Glosario'],
        ['href' => '/acerca', 'texto' => 'Acerca de'],
        ['href' => '/contacto', 'texto' => 'Contacto'],
        ['href' => '/privacidad', 'texto' => 'Privacidad'],
        ['href' => '/aviso-legal', 'texto' => 'Aviso legal'],
    ];
}

/** Schema FAQPage a partir de una lista de {q,a}; null si no hay preguntas. */
function json_ld_faq(array $faq): ?array
{
    if ($faq === []) {
        return null;
    }
    return [
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

/**
 * Cabecera HTML común.
 * $opts: titulo, descripcion, canonical (ruta), robots, og_image, json_ld (array),
 *        body_class, css_inline, preload_scripts (rutas relativas)
 */
function pagina_head(array $opts): void
{
    $titulo = $opts['titulo'] ?? SITE_NOMBRE;
    $descripcion = $opts['descripcion'] ?? '';
    $canonical = SITE_URL . ($opts['canonical'] ?? '/');
    $robots = $opts['robots'] ?? 'index, follow';
    $ogImage = $opts['og_image'] ?? SITE_URL . '/og-image.png';
    $bodyClass = $opts['body_class'] ?? 'bg-slate-900 text-slate-100 min-h-screen flex flex-col';
    csp_headers();
    ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title><?= e($titulo) ?></title>
    <meta name="description" content="<?= e($descripcion) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta name="robots" content="<?= e($robots) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(SITE_NOMBRE) ?>">
    <meta property="og:locale" content="es_ES">
    <meta property="og:title" content="<?= e($titulo) ?>">
    <meta property="og:description" content="<?= e($descripcion) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($titulo) ?>">
    <meta name="twitter:description" content="<?= e($descripcion) ?>">
    <meta name="twitter:image" content="<?= e($ogImage) ?>">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="<?= e(asset('icons/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/icon-180.png')) ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="alternate" type="application/rss+xml" title="Draft 20 — Guías" href="/feed.xml">
<?php foreach (($opts['prefetch'] ?? []) as $pf): ?>
    <link rel="prefetch" href="<?= e($pf) ?>">
<?php endforeach; ?>
    <?= css_tags($opts['css_inline'] ?? true) ?>
<?php foreach (($opts['preload_scripts'] ?? []) as $src): ?>
    <link rel="preload" as="script" href="<?= e(asset_js($src)) ?>">
<?php endforeach; ?>
<?php foreach (($opts['json_ld'] ?? []) as $schema): ?>
    <script type="application/ld+json" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php
}

/**
 * Pie + scripts de cierre.
 * $opts: inline_first (HTML crudo antes de los scripts), scripts (rutas relativas), inline (HTML crudo), defer (bool)
 */
function pagina_foot(array $opts = []): void
{
    site_footer();
    if (!empty($opts['inline_first'])) {
        echo '    <script nonce="' . e(csp_nonce()) . '">' . $opts['inline_first'] . '</script>' . "\n";
    }
    $defer = !empty($opts['defer']) ? ' defer' : '';
    foreach (($opts['scripts'] ?? []) as $src) {
        echo '    <script src="' . e(asset_js($src)) . '"' . $defer . '></script>' . "\n";
    }
    if (!empty($opts['inline'])) {
        echo '    <script nonce="' . e(csp_nonce()) . '">' . $opts['inline'] . '</script>' . "\n";
    }
    ?>
</body>
</html>
<?php
}

function site_footer(): void
{
    $year = date('Y');
    ?>
    <footer class="mt-10 border-t border-slate-800 px-4 py-8 text-center text-sm text-slate-400">
        <nav class="flex flex-wrap justify-center gap-x-5 gap-y-2 mb-4" aria-label="Enlaces del sitio">
            <a class="hover:text-amber-400" href="/">Inicio</a>
<?php foreach (nav_links() as $l): ?>
            <a class="hover:text-amber-400" href="<?= e($l['href']) ?>"><?= e($l['texto']) ?></a>
<?php endforeach; ?>
        </nav>
        <p class="mb-1"><?= e(SITE_NOMBRE) ?> · Subasta por turnos para 2 jugadores</p>
        <p><a class="hover:text-amber-400" href="mailto:<?= e(SITE_EMAIL) ?>"><?= e(SITE_EMAIL) ?></a> · © <?= $year ?></p>
    </footer>
<?php
}

/** Página 404 (la usan 404.php y las rutas con slug inválido). */
function pagina_404(): void
{
    $SEO = is_array(cargar_lang()['seo'] ?? null) ? cargar_lang()['seo'] : [];
    http_response_code(404);
    pagina_head([
        'titulo' => (string) ($SEO['p404_titulo'] ?? 'Página no encontrada') . ' — ' . SITE_NOMBRE,
        'descripcion' => (string) ($SEO['p404_texto'] ?? ''),
        'canonical' => '/404',
        'robots' => 'noindex, follow',
    ]);
    ?>
    <main class="flex-1 flex flex-col items-center justify-center px-6 py-16 text-center">
        <div class="text-6xl mb-4">🎲</div>
        <h1 class="text-3xl font-bold text-amber-400 mb-3"><?= e((string) ($SEO['p404_titulo'] ?? 'Página no encontrada')) ?></h1>
        <p class="text-slate-300 text-sm max-w-md leading-relaxed mb-6"><?= e((string) ($SEO['p404_texto'] ?? '')) ?></p>
        <a href="/" class="bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap">Volver al inicio</a>
    </main>
<?php
    pagina_foot();
}
