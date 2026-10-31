<?php
/**
 * Draft 20 — Helpers compartidos de las páginas públicas (SEO).
 *
 * Centraliza: constantes del sitio, i18n de servidor, catálogo de temáticas,
 * <head> con metas/canonical/OG/JSON-LD, cabecera, pie y assets versionados.
 */
declare(strict_types=1);

require_once __DIR__ . '/i18n.php';

const SITE_URL   = 'https://draft20.es';
const SITE_NOMBRE = 'Draft 20';
const SITE_EMAIL = 'contacto@draft20.es';
// Versión visible de la app (mantener en sync con package.json).
const APP_VERSION = '1.4.1';
const ADSENSE_CLIENT = 'ca-pub-9504493922636861';
// Google Analytics 4 directo (gtag.js). Vacío = no se pinta nada.
const GA4_ID = 'G-JR150JQ65Z';
const ADSENSE_SLOT_JUEGO = '6658157047';
const ADSENSE_SLOT_FINAL = '4631951476';
const ADSENSE_SLOT_LOBBY = '1410891766';
const ADSENSE_SLOT_ARTICULO = '1818472919';
// Red publicitaria activa: 'ninguna' deja los huecos en modo dormant
// (colapsados, con hooks data-ad-* y sin llamadas a terceros) listos para
// enchufar AdinPlay u otra red sin tocar plantillas.
const ADS_ACTIVOS = false;
// Mapa de huecos: posición => tamaño reservado al activar la red.
const AD_HUECOS = [
    'lobby' => '320x100',
    'juego' => '320x50',
    'final' => '300x250',
    'articulo' => '300x250',
    'torre' => '300x250',
];

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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

/** IDs de ⭐ Destacados en orden de presentación (fuente única SSR + JS vía window.__DESTACADOS). */
function destacados_ids(): array
{
    return ['mundial_2026', 'gta_vi', 'la_velada', 'pizza', 'hamburguesa', 'anime', 'kpop', 'padel', 'tacos', 'marvel_dc', 'harry_potter', 'star_wars', 'pokemon', 'futbol', 'videojuegos', 'anime_moderno', 'freidora_aire', 'pokemon_legendarios', 'sandwich', 'acampada', 'helado', 'futbol_historicos', 'freestylers', 'raperos'];
}

/** Entradas de ⭐ Destacados (solo las que existen en el catálogo). @return array<int, array{id:string,emoji:string}> */
function destacados(): array
{
    $map = mapa_tematicas();
    $out = [];
    foreach (destacados_ids() as $id) {
        if (isset($map[$id])) {
            $out[] = ['id' => $id, 'emoji' => $map[$id]['emoji']];
        }
    }
    return $out;
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
    $limpio = ltrim($rel, '/');
    $abs = __DIR__ . '/../' . $limpio;
    $v = is_file($abs) ? filemtime($abs) : null;
    // Ruta absoluta: las URLs bonitas (/tematica/<id>, /guia/<slug>…) no están
    // en la raíz, así que una ruta relativa se resolvería mal.
    return '/' . $limpio . ($v !== null ? '?v=' . $v : '');
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
    header('Content-Security-Policy: ' . csp_policy());
}

/** Política CSP (sin frame-ancestors: va en meta y ahí se ignora; cubre XFO). */
function csp_policy(): string
{
    $n = csp_nonce();
    // Medición (GA4 gtag.js): gtag/js sale del host de Tag Manager y los
    // beacons van a google-analytics. Sin iframes: frame-src sin gtm.
    // Al activar red, re-añadir sus dominios aquí (ver README Publicidad).
    return "default-src 'self'; " .
        "script-src 'self' 'nonce-" . $n . "' " .
        "https://www.googletagmanager.com https://www.google.com https://www.gstatic.com https://fundingchoicesmessages.google.com; " .
        "style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data: https://www.google.com https://www.gstatic.com https://www.google-analytics.com https://*.google-analytics.com; " .
        "connect-src 'self' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://www.google.com https://fundingchoicesmessages.google.com; " .
        "frame-src https://www.google.com https://www.gstatic.com https://fundingchoicesmessages.google.com; " .
        "font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";
}

/** Meta CSP para el <head>: el hosting puede reescribir la cabecera HTTP. */
function csp_meta(): string
{
    return '<meta http-equiv="Content-Security-Policy" content="' . e(csp_policy()) . '">';
}

/** Sello de build: mtime más reciente de los bundles (app vs web). */
function app_build(): string
{
    $max = 0;
    foreach (['js/app.core.min.js', 'js/app.game.min.js', 'js/bot_policy.min.js', 'js/torre.min.js', 'js/app.core.js', 'js/app.game.js', 'js/torre.js'] as $rel) {
        $f = __DIR__ . '/../' . $rel;
        if (is_file($f)) {
            $max = max($max, (int) filemtime($f));
        }
    }
    return $max > 0 ? (string) $max : 'dev';
}

/** Google Analytics 4 directo (gtag.js) + Consent Mode por defecto denegado.
 *  Las etiquetas de Google respetan la señal TCF del CMP (Funding Choices);
 *  sin consentimiento no miden. Vacío si GA4_ID está sin configurar.
 */
function ga_head(): string
{
    if (GA4_ID === '') {
        return '';
    }
    $n = e(csp_nonce());
    $id = e(GA4_ID);
    return '<script nonce="' . $n . '">window.dataLayer=window.dataLayer||[];'
        . 'function gtag(){window.dataLayer.push(arguments);}'
        . 'gtag("consent","default",{"ad_storage":"denied","analytics_storage":"denied","ad_user_data":"denied","ad_personalization":"denied","wait_for_update":500});'
        . 'gtag("js",new Date());'
        // Si el visitante ya aceptó en una visita anterior, aplicar la preferencia
        // antes del config para que el primer hit salga con consentimiento.
        . 'try{if(localStorage.getItem("d20_consent_v1")==="granted"){gtag("consent","update",{"ad_storage":"denied","analytics_storage":"granted","ad_user_data":"denied","ad_personalization":"denied"});}}catch(e){}'
        . 'gtag("config","' . $id . '");</script>' . "\n"
        . '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '" nonce="' . $n . '"></script>';
}

/** GA4 directo no necesita noscript: sin JS no hay medición. */
function ga_body(): string
{
    return '';
}

/**
 * Banner de consentimiento de GA4. Si no hay ID configurado no se pinta.
 * Guarda la elección en localStorage y actualiza Consent Mode al momento.
 */
function consent_banner(): void
{
    static $pintado = false;
    if ($pintado || GA4_ID === '') {
        return;
    }
    $pintado = true;

    $lang = cargar_lang();
    $c = is_array($lang['ui']['consent'] ?? null) ? $lang['ui']['consent'] : [];
    $titulo = (string) ($c['titulo'] ?? 'Cookies y medición');
    $texto = (string) ($c['texto'] ?? 'Usamos Google Analytics 4 solo si aceptas.');
    $aceptar = (string) ($c['aceptar'] ?? 'Aceptar');
    $rechazar = (string) ($c['rechazar'] ?? 'Rechazar');
    $masInfo = (string) ($c['mas_info'] ?? 'Más información');
    $n = e(csp_nonce());
    ?>
    <section id="d20-consent" class="d20-consent" role="dialog" aria-modal="false"
             aria-labelledby="d20-consent-title" hidden>
        <h2 id="d20-consent-title" class="d20-consent__titulo"><?= e($titulo) ?></h2>
        <p class="d20-consent__texto"><?= e($texto) ?>
            <a href="<?= e(url_lang('/privacidad#cookies')) ?>"><?= e($masInfo) ?></a>.
        </p>
        <div class="d20-consent__acciones">
            <button type="button" class="d20-consent__btn btn-tap" data-consent="denied"><?= e($rechazar) ?></button>
            <button type="button" class="d20-consent__btn d20-consent__btn--ok btn-tap" data-consent="granted"><?= e($aceptar) ?></button>
        </div>
    </section>
    <script nonce="<?= $n ?>">
    (function () {
        var KEY = 'd20_consent_v1';
        var box = document.getElementById('d20-consent');
        if (!box) return;

        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch (e) {}
        if (saved !== 'granted' && saved !== 'denied') box.hidden = false;

        function clearGaCookies() {
            var host = location.hostname.replace(/^www\./, '');
            document.cookie.split(';').forEach(function (c) {
                var name = c.split('=')[0].trim();
                if (name.indexOf('_ga') !== 0) return;
                document.cookie = name + '=; Max-Age=0; Path=/; SameSite=Lax';
                document.cookie = name + '=; Max-Age=0; Path=/; Domain=.' + host + '; SameSite=Lax';
            });
        }

        function update(value) {
            if (typeof window.gtag !== 'function') return;
            window.gtag('consent', 'update', {
                analytics_storage: value === 'granted' ? 'granted' : 'denied',
                ad_storage: 'denied',
                ad_user_data: 'denied',
                ad_personalization: 'denied'
            });
            if (value !== 'granted') clearGaCookies();
        }

        function choose(value) {
            try { localStorage.setItem(KEY, value); } catch (e) {}
            update(value);
            box.hidden = true;
        }

        box.addEventListener('click', function (ev) {
            var el = ev.target;
            var btn = el && el.closest ? el.closest('[data-consent]') : null;
            if (btn) choose(btn.getAttribute('data-consent'));
        });

        document.addEventListener('click', function (ev) {
            var el = ev.target;
            var opener = el && el.closest ? el.closest('[data-consent-open]') : null;
            if (!opener) return;
            ev.preventDefault();
            box.hidden = false;
            var focusBtn = box.querySelector('[data-consent="granted"]');
            if (focusBtn) focusBtn.focus();
        });
    })();
    </script>
    <?php
}

/** Bloque manual de anuncio para contenido.
 * Con red inactiva devuelve el hueco dormant: colapsado (cero impacto
 * visual), con hooks data-ad-* y tamaño documentado para la red futura.
 * Con red activa, el <ins> de tamaño fijo (cero CLS) + push único en el pie.
 */
function ads_slot(string $clase = 'ad-articulo', string $pos = 'articulo'): string
{
    $tam = AD_HUECOS[$pos] ?? '300x250';
    [$ancho, $alto] = array_map('intval', explode('x', $tam) + [300, 250]);
    if (!ADS_ACTIVOS || ADSENSE_SLOT_ARTICULO === '') {
        return '<!-- hueco-ad:' . e($pos) . ' ' . e($tam) . ' -->'
            . '<div class="ad-slot ' . e($clase) . '" data-ad-pos="' . e($pos) . '" data-ad-size="' . e($tam) . '"></div>';
    }
    $GLOBALS['__ads_pendiente'] = true;
    return '<div class="ad-slot ' . e($clase) . '" data-ad-pos="' . e($pos) . '" data-ad-size="' . e($tam) . '">'
        . '<ins class="adsbygoogle" style="display:inline-block;width:' . $ancho . 'px;height:' . $alto . 'px" '
        . 'data-ad-client="' . e(ADSENSE_CLIENT) . '" data-ad-slot="' . e(ADSENSE_SLOT_ARTICULO) . '"></ins>'
        . '</div>';
}

/** Evento de página para el contador anónimo (según el script que la sirve). */
function evento_pagina_actual(): string
{
    $mapa = [
        'index.php' => 'page:home',
        'tematica.php' => 'page:tematica',
        'categoria.php' => 'page:categoria',
        'guia.php' => 'page:guia',
        'guias.php' => 'page:guias',
        'glosario.php' => 'page:glosario',
        'como_jugar.php' => 'page:como-jugar',
        'estrategia.php' => 'page:estrategia',
        'preguntas-frecuentes.php' => 'page:faq',
        'terminos.php' => 'page:terminos',
        'ranking.php' => 'page:ranking',
        'juegos_de_subasta.php' => 'page:subasta',
        'acerca.php' => 'page:acerca',
        'contacto.php' => 'page:contacto',
        'privacidad.php' => 'page:privacidad',
        'aviso_legal.php' => 'page:aviso',
    ];
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return $mapa[$script] ?? '';
}

/** Heurística de UA: los crawlers no cuentan en las métricas. */
function es_crawler(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match('/bot|crawl|spider|slurp|bing|google|yandex|baidu|duckduck|facebook|ahrefs|semrush|uptime|pingdom|monitor/', $ua);
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
        ['href' => '/como-jugar', 'texto' => t('ui.nav.como_jugar')],
        ['href' => '/como-jugar-y-estrategia', 'texto' => t('ui.nav.estrategia')],
        ['href' => '/preguntas-frecuentes', 'texto' => t('ui.nav.faq')],
        ['href' => '/guias', 'texto' => t('ui.nav.guias')],
        ['href' => '/glosario', 'texto' => t('ui.nav.glosario')],
        ['href' => '/ranking', 'texto' => t('ui.nav.ranking')],
        ['href' => '/juegos-de-subasta', 'texto' => t('ui.nav.subasta')],
        ['href' => '/acerca', 'texto' => t('ui.nav.acerca')],
        ['href' => '/contacto', 'texto' => t('ui.nav.contacto')],
        ['href' => '/privacidad', 'texto' => t('ui.nav.privacidad')],
        ['href' => '/aviso-legal', 'texto' => t('ui.nav.aviso')],
        ['href' => '/terminos', 'texto' => t('ui.nav.terminos')],
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

/** Entidad de sitio (Organization + WebSite) que acompaña a todas las páginas. */
function site_json_ld(): array
{
    return [
        [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => SITE_URL . '/#organizacion',
            'name' => SITE_NOMBRE,
            'url' => SITE_URL . url_lang('/'),
            'email' => SITE_EMAIL,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => SITE_URL . '/icons/icon-512.png',
                'width' => 512,
                'height' => 512,
            ],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => SITE_URL . '/#web',
            'name' => SITE_NOMBRE,
            'url' => SITE_URL . url_lang('/'),
            'inLanguage' => idioma_bcp47(),
            'publisher' => ['@id' => SITE_URL . '/#organizacion'],
        ],
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
    $ruta = (string) ($opts['canonical'] ?? '/');
    $canonical = canonical_absoluto($ruta);
    $robots = $opts['robots'] ?? 'index, follow';
    $ogImage = $opts['og_image'] ?? (idioma_actual() === 'en' && is_file(__DIR__ . '/../og/en/og-image.png')
        ? SITE_URL . '/og/en/og-image.png'
        : SITE_URL . '/og-image.png');
    $ogImage = og_imagen_idioma((string) $ogImage);
    $bodyClass = $opts['body_class'] ?? 'bg-slate-900 text-slate-100 min-h-screen flex flex-col';
    $jsonLd = array_merge(site_json_ld(), $opts['json_ld'] ?? []);
    csp_headers();
    ?><!DOCTYPE html>
<html lang="<?= e(idioma_bcp47()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?= deteccion_idioma_script() ?>
    <?= csp_meta() ?>
    <?= ga_head() ?>
    <meta name="theme-color" content="#0f172a">
    <meta name="app-version" content="<?= e(APP_VERSION) ?>">
    <meta name="app-build" content="<?= e(app_build()) ?>">
    <title><?= e($titulo) ?></title>
    <meta name="description" content="<?= e($descripcion) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <?= hreflang_tags($ruta) ?>
    <meta name="robots" content="<?= e($robots) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(SITE_NOMBRE) ?>">
    <meta property="og:locale" content="<?= e(og_locale()) ?>">
    <meta property="og:locale:alternate" content="<?= e(og_locale_alternativo()) ?>">
    <meta property="og:title" content="<?= e($titulo) ?>">
    <meta property="og:description" content="<?= e($descripcion) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($titulo) ?>">
    <meta name="twitter:description" content="<?= e($descripcion) ?>">
    <meta name="twitter:image" content="<?= e($ogImage) ?>">
    <link rel="icon" href="<?= e(asset('icons/favicon.ico')) ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= e(asset('icons/icon-48.png')) ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="<?= e(asset('icons/icon-96.png')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(asset('icons/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/icon-180.png')) ?>">
    <link rel="manifest" href="<?= e(idioma_actual() === 'en' ? '/manifest.en.webmanifest' : '/manifest.webmanifest') ?>">
    <link rel="alternate" type="application/rss+xml" title="<?= e(idioma_actual() === 'en' ? 'Draft 20 — Guides' : 'Draft 20 — Guías') ?>" href="<?= e(url_lang('/feed.xml')) ?>">
<?php foreach (($opts['prefetch'] ?? []) as $pf): ?>
    <link rel="prefetch" href="<?= e($pf) ?>">
<?php endforeach; ?>
<?php if (ADS_ACTIVOS && ADSENSE_CLIENT !== ''): ?>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?= e(ADSENSE_CLIENT) ?>" crossorigin="anonymous"></script>
<?php endif; ?>
    <?= css_tags($opts['css_inline'] ?? true) ?>
<?php foreach (($opts['preload_scripts'] ?? []) as $src): ?>
    <link rel="preload" as="script" href="<?= e(asset_js($src)) ?>">
<?php endforeach; ?>
<?php foreach ($jsonLd as $schema): ?>
    <script type="application/ld+json" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?= ga_body() ?>
<?php
    consent_banner();
    site_header();
}

/**
 * Barra de navegación principal (SSR en todas las páginas con pagina_head).
 * Enlaces reales para crawler y usuarios; responsive con wrap.
 */
function site_header(): void
{
    $enlaces = [
        ['href' => '/como-jugar', 'texto' => t('ui.nav.como_jugar')],
        ['href' => '/como-jugar-y-estrategia', 'texto' => t('ui.nav.estrategia')],
        ['href' => '/preguntas-frecuentes', 'texto' => t('ui.nav.faq')],
        ['href' => '/guias', 'texto' => t('ui.nav.guias')],
        ['href' => '/ranking', 'texto' => t('ui.nav.ranking')],
    ];
    ?>
    <nav class="w-full bg-slate-900/95 border-b border-slate-800 px-4 py-2 flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-sm" aria-label="<?= e(t('ui.comun.nav_principal')) ?>">
        <?php foreach ($enlaces as $l): ?>
        <a class="text-slate-300 hover:text-amber-400 font-semibold" href="<?= e(url_lang($l['href'])) ?>"><?= e($l['texto']) ?></a>
        <?php endforeach; ?>
        <?= selector_idioma('d20-lang--header') ?>
    </nav>
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
    // Bloque manual de AdSense pintado en el cuerpo: se encola una sola vez.
    if (!empty($GLOBALS['__ads_pendiente'])) {
        echo '    <script nonce="' . e(csp_nonce()) . '">(window.adsbygoogle = window.adsbygoogle || []).push({});</script>' . "\n";
    }
    // Contador anónimo de páginas (sin cookies ni identificadores).
    $eventoPagina = evento_pagina_actual();
    if ($eventoPagina !== '' && !es_crawler()) {
        echo '    <script nonce="' . e(csp_nonce()) . '">'
            . 'if(!window.__ev){window.__ev=1;try{navigator.sendBeacon("/api/evento.php",JSON.stringify({evento:"' . $eventoPagina . '"}))}catch(e){}}'
            . '</script>' . "\n";
    }
    ?>
</body>
</html>
<?php
}

function site_footer(): void
{
    $year = date('Y');
    $textoCookies = t('ui.consent.abrir');
    ?>
    <footer class="mt-10 border-t border-slate-800 px-4 py-8 text-center text-sm text-slate-400">
        <nav class="flex flex-wrap justify-center gap-x-5 gap-y-2 mb-4" aria-label="<?= e(t('ui.comun.enlaces_pie')) ?>">
            <a class="hover:text-amber-400" href="<?= e(url_lang('/')) ?>"><?= e(t('ui.nav.inicio')) ?></a>
<?php foreach (nav_links() as $l): ?>
            <a class="hover:text-amber-400" href="<?= e(url_lang($l['href'])) ?>"><?= e($l['texto']) ?></a>
<?php endforeach; ?>
<?php if (GA4_ID !== ''): ?>
            <button type="button" data-consent-open class="hover:text-amber-400"><?= e($textoCookies) ?></button>
<?php endif; ?>
        </nav>
        <p class="mb-1"><?= e(SITE_NOMBRE) ?> · <?= e(t('ui.footer.subtitulo')) ?></p>
        <p><a class="hover:text-amber-400" href="mailto:<?= e(SITE_EMAIL) ?>"><?= e(SITE_EMAIL) ?></a> · © <?= $year ?></p>
        <div class="mt-4 flex justify-center"><?= selector_idioma('d20-lang--footer') ?></div>
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
        <a href="<?= e(url_lang('/')) ?>" class="bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap"><?= e(t('ui.comun.volver_inicio')) ?></a>
    </main>
<?php
    pagina_foot();
}
