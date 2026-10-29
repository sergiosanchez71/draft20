<?php
/**
 * Draft 20 — i18n de servidor.
 *
 * El idioma se decide SOLO por ?lang (es|en), nunca por cookie: así la caché
 * del CDN puede servir la misma URL sin variar por visitante. La cookie
 * d20_lang solo la usan el selector y la detección inicial del navegador.
 */
declare(strict_types=1);

const D20_IDIOMAS = ['es' => 'Español', 'en' => 'English'];
const D20_IDIOMA_DEFECTO = 'es';
const D20_COOKIE_IDIOMA = 'd20_lang';

function idioma_es_valido(string $lang): bool
{
    return isset(D20_IDIOMAS[$lang]);
}

/** Idioma de la petición actual (solo ?lang). */
function idioma_actual(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    $raw = $_GET['lang'] ?? null;
    if (is_string($raw)) {
        $raw = strtolower(trim($raw));
        if (idioma_es_valido($raw)) {
            guardar_cookie_idioma($raw);
            if ($raw === 'es' && PHP_SAPI !== 'cli' && !headers_sent() && !i18n_es_crawler()) {
                // La respuesta lleva Set-Cookie: no debe quedar en cachés compartidas.
                header('Cache-Control: no-store, max-age=0');
                header('Location: ' . url_sin_lang(), true, 302);
                exit;
            }
            return $lang = $raw;
        }
    }
    return $lang = D20_IDIOMA_DEFECTO;
}

/** Guarda la preferencia para la próxima visita (solo cliente). */
function guardar_cookie_idioma(string $lang): void
{
    if (!idioma_es_valido($lang) || PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    if (isset($_COOKIE[D20_COOKIE_IDIOMA]) && $_COOKIE[D20_COOKIE_IDIOMA] === $lang) {
        return;
    }
    setcookie(D20_COOKIE_IDIOMA, $lang, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/** Crawlers: no redirigir ?lang=es para no romper la exploración. */
function i18n_es_crawler(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match('/bot|crawl|spider|slurp|bing|google|yandex|baidu|duckduck|facebook|ahrefs|semrush|uptime|pingdom|monitor/', $ua);
}

/** Carga el diccionario del idioma (con fallback a español). */
function cargar_lang(?string $lang = null): array
{
    static $cache = [];
    $lang = $lang ?? idioma_actual();
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }

    $file = __DIR__ . '/../lang/' . $lang . '.json';
    $raw = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $data = is_array($raw) ? $raw : [];

    if ($lang !== D20_IDIOMA_DEFECTO) {
        $base = cargar_lang(D20_IDIOMA_DEFECTO);
        $data = array_replace_recursive($base, $data);
    }
    return $cache[$lang] = $data;
}

/** Texto traducido por clave punteada: t('ui.lobby.btn_crear'). */
function t(string $clave, array $params = [], ?string $lang = null): string
{
    $data = cargar_lang($lang);
    $parts = explode('.', $clave);
    $v = $data;
    foreach ($parts as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) {
            $v = null;
            break;
        }
        $v = $v[$part];
    }
    if (!is_string($v)) {
        return $clave;
    }
    if ($params !== []) {
        $v = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($params): string {
            return array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0];
        }, $v) ?? $v;
    }
    return $v;
}

/** Quita el parámetro lang de una URL (ruta + query + hash). */
function url_sin_lang(string $url = ''): string
{
    if ($url === '') {
        $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }
    $hash = '';
    $posHash = strpos($url, '#');
    if ($posHash !== false) {
        $hash = substr($url, $posHash);
        $url = substr($url, 0, $posHash);
    }
    $pos = strpos($url, '?');
    if ($pos === false) {
        return $url . $hash;
    }
    $ruta = substr($url, 0, $pos);
    $query = substr($url, $pos + 1);
    $pares = [];
    foreach (explode('&', $query) as $par) {
        if ($par === '' || preg_match('/^lang=/i', $par)) {
            continue;
        }
        $pares[] = $par;
    }
    return $ruta . ($pares !== [] ? '?' . implode('&', $pares) : '') . $hash;
}

/** URL de la misma página en el idioma indicado. */
function url_lang(string $url, ?string $lang = null): string
{
    $lang = $lang ?? idioma_actual();
    $limpia = url_sin_lang($url);
    if ($lang === D20_IDIOMA_DEFECTO) {
        return $limpia;
    }
    $hash = '';
    $posHash = strpos($limpia, '#');
    if ($posHash !== false) {
        $hash = substr($limpia, $posHash);
        $limpia = substr($limpia, 0, $posHash);
    }
    $sep = strpos($limpia, '?') === false ? '?' : '&';
    return $limpia . $sep . 'lang=' . rawurlencode($lang) . $hash;
}

/** URL actual en otro idioma, conservando query y hash. */
function url_actual(?string $lang = null): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    return url_lang($uri, $lang);
}

/**
 * URL del selector de idioma: lleva SIEMPRE ?lang, también para español.
 *
 * Así el servidor guarda la cookie y redirige a la URL limpia; si el enlace
 * fuera solo "/", la detección temprana leería la cookie anterior (en) y
 * devolvería al visitante a inglés.
 */
function url_selector_idioma(string $lang): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $limpia = url_sin_lang($uri);
    $hash = '';
    $posHash = strpos($limpia, '#');
    if ($posHash !== false) {
        $hash = substr($limpia, $posHash);
        $limpia = substr($limpia, 0, $posHash);
    }
    $sep = strpos($limpia, '?') === false ? '?' : '&';
    return $limpia . $sep . 'lang=' . rawurlencode($lang) . $hash;
}

/** Canonical absoluto: limpio en español y con ?lang=en en inglés. */
function canonical_absoluto(string $ruta): string
{
    return SITE_URL . url_lang($ruta, idioma_actual());
}

/** Etiquetas hreflang de una ruta canónica (sin query). */
function hreflang_tags(string $ruta): string
{
    $es = SITE_URL . url_lang($ruta, 'es');
    $en = SITE_URL . url_lang($ruta, 'en');
    $out = '<link rel="alternate" hreflang="es" href="' . e($es) . '">' . "\n    "
        . '<link rel="alternate" hreflang="en" href="' . e($en) . '">' . "\n    "
        . '<link rel="alternate" hreflang="x-default" href="' . e($es) . '">';
    return $out;
}

/** Locale de Open Graph. */
function og_locale(): string
{
    return idioma_actual() === 'en' ? 'en_US' : 'es_ES';
}

/** Locale alternativo de Open Graph. */
function og_locale_alternativo(): string
{
    return idioma_actual() === 'en' ? 'es_ES' : 'en_US';
}

/** Código BCP-47 para <html lang> y JSON-LD. */
function idioma_bcp47(): string
{
    return idioma_actual() === 'en' ? 'en' : 'es';
}

/** Fecha corta localizada. */
function fecha_localizada(string $iso): string
{
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }
    return idioma_actual() === 'en' ? date('M j, Y', $ts) : date('d/m/Y', $ts);
}

/** Mes y año localizados a partir de «YYYY-MM». */
function mes_anio(string $ym): string
{
    [$y, $m] = array_pad(explode('-', $ym), 2, '');
    $meses = meses_idioma();
    return ($meses[$m] ?? $ym) . ' ' . $y;
}

/** Nombre de mes por número «01»..«12». */
function nombre_mes(string $m): string
{
    return meses_idioma()[$m] ?? $m;
}

/** @return array<string,string> */
function meses_idioma(): array
{
    static $meses = null;
    if ($meses !== null) {
        return $meses;
    }
    if (idioma_actual() === 'en') {
        return $meses = [
            '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
            '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
            '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December',
        ];
    }
    return $meses = [
        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
        '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
        '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre',
    ];
}

/** Imagen OG inglesa si existe (og/en/**); si no, la española. */
function og_imagen_idioma(string $url): string
{
    if (idioma_actual() !== 'en' || str_contains($url, '/og/en/')) {
        return $url;
    }
    if (str_ends_with($url, '/og-image.png')) {
        $en = substr($url, 0, -strlen('/og-image.png')) . '/og/en/og-image.png';
        $path = parse_url($en, PHP_URL_PATH);
        if (is_string($path) && is_file(__DIR__ . '/../' . ltrim($path, '/'))) {
            return $en;
        }
        return $url;
    }
    if (!str_contains($url, '/og/')) {
        return $url;
    }
    $en = str_replace('/og/', '/og/en/', $url);
    $path = parse_url($en, PHP_URL_PATH);
    if (is_string($path) && is_file(__DIR__ . '/../' . ltrim($path, '/'))) {
        return $en;
    }
    return $url;
}

/** Ruta de un recurso específico del idioma, con fallback al español. */
function recurso_idioma(string $ruta): string
{
    if (idioma_actual() === D20_IDIOMA_DEFECTO) {
        return $ruta;
    }
    $info = pathinfo($ruta);
    $candidato = ($info['dirname'] ?? '.') . '/' . ($info['filename'] ?? '') . '.' . ($info['extension'] ?? '');
    $en = ($info['dirname'] ?? '.') . '/' . ($info['filename'] ?? '') . '.en.' . ($info['extension'] ?? '');
    $abs = __DIR__ . '/../' . ltrim($en, '/');
    return is_file($abs) ? $en : $ruta;
}

/** Script de detección inicial: navegador no español -> versión inglesa. */
function deteccion_idioma_script(): string
{
    $nonce = e(csp_nonce());
    return '<script nonce="' . $nonce . '">(function(){try{'
        . 'var m=location.search.match(/[?&]lang=(es|en)(?:&|$)/);'
        . 'if(m){document.cookie="' . D20_COOKIE_IDIOMA . '="+m[1]+";path=/;max-age=31536000;samesite=lax";return;}'
        . 'if(/bot|crawl|spider|slurp|bingbot|googlebot/i.test(navigator.userAgent||""))return;'
        . 'var c=document.cookie.match(/(?:^|;\\s*)' . D20_COOKIE_IDIOMA . '=(es|en)(?:;|$)/);'
        . 'var l=c?c[1]:"";'
        . 'if(l!=="es"&&l!=="en"){var n=(navigator.language||navigator.userLanguage||"es").toLowerCase();'
        . 'l=n.indexOf("es")===0?"es":"en";document.cookie="' . D20_COOKIE_IDIOMA . '="+l+";path=/;max-age=31536000;samesite=lax";}'
        . 'if(l==="en"&&!m){var u=new URL(location.href);u.searchParams.set("lang","en");location.replace(u.toString());}'
        . '}catch(e){}})();</script>';
}

/** Sustituye marcadores [[clave]] por HTML ya construido (el resto se escapa). */
function texto_con_enlaces(string $texto, array $mapa): string
{
    $partes = preg_split('/\[\[(\w+)\]\]/', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($partes === false) {
        return e($texto);
    }
    $out = '';
    foreach ($partes as $i => $parte) {
        if ($i % 2 === 1) {
            $out .= $mapa[$parte] ?? '';
        } else {
            $out .= e($parte);
        }
    }
    return (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $out);
}

/** Selector de idioma (SSR, sin dependencia de JS). */
function selector_idioma(string $clase = ''): string
{
    $actual = idioma_actual();
    $html = '<nav class="d20-lang ' . e($clase) . '" aria-label="' . e(t('ui.comun.seleccionar_idioma')) . '">';
    foreach (D20_IDIOMAS as $codigo => $etiqueta) {
        $activo = $codigo === $actual;
        $html .= '<a class="d20-lang__link' . ($activo ? ' is-active' : '') . '"'
            . ' href="' . e(url_selector_idioma($codigo)) . '"'
            . ' hreflang="' . e($codigo) . '"'
            . ' lang="' . e($codigo) . '"'
            . ($activo ? ' aria-current="true"' : '')
            . '>' . e($etiqueta) . '</a>';
    }
    return $html . '</nav>';
}
