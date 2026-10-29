<?php
/**
 * Draft 20 — Ensambla los diccionarios ingleses desde la caché de traducción.
 *
 * Uso: php tools/i18n_build.php
 * Entrada: lang/_source_es.json + lang/_translated.json
 * Salida:  lang/en.json + lang/contenido_en.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
$source = json_decode((string) file_get_contents($root . '/lang/_source_es.json'), true);
$cache = json_decode((string) file_get_contents($root . '/lang/_translated.json'), true);
if (!is_array($source) || !is_array($cache)) {
    fwrite(STDERR, "Faltan lang/_source_es.json o lang/_translated.json\n");
    exit(1);
}

// Correcciones manuales sobre la traducción automática (clave = texto original).
$overridesFile = $root . '/lang/_overrides_en.json';
$overridesRaw = is_file($overridesFile) ? json_decode((string) file_get_contents($overridesFile), true) : null;
$overrides = is_array($overridesRaw) ? $overridesRaw : [];

$faltan = 0;
$traducir = static function ($v, string $key = '') use (&$traducir, &$faltan, $cache, $overrides) {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = $traducir($x, is_string($k) ? $k : $key);
        }
        return $out;
    }
    if (!is_string($v) || trim($v) === '') {
        return $v;
    }
    if (isset($overrides[$v]) && is_string($overrides[$v])) {
        return $overrides[$v];
    }
    if (array_key_exists($v, $cache) && is_string($cache[$v]) && $cache[$v] !== '') {
        $en = $cache[$v];
        // Google Translate renombra los marcadores ({mio} -> {mine}): se
        // restauran los nombres originales porque PHP/JS los pasan así.
        preg_match_all('/\{(\w+)\}/', $v, $ma);
        preg_match_all('/\{(\w+)\}/', $en, $mb);
        if ($ma[1] !== [] && count($ma[1]) === count($mb[1])) {
            $i = 0;
            $en = preg_replace_callback('/\{(\w+)\}/', static function () use (&$i, $ma): string {
                return '{' . $ma[1][$i++] . '}';
            }, $en) ?? $en;
        }
        return $en;
    }
    $faltan++;
    return $v;
};

$en = $traducir($source['lang'] ?? []);
$contenido = $traducir($source['contenido'] ?? []);

// Errores de API traducidos en cliente (no se traducen: ya vienen corregidos).
$apiErrorsFile = $root . '/lang/_api_errors_en.json';
$apiErrorsRaw = is_file($apiErrorsFile) ? json_decode((string) file_get_contents($apiErrorsFile), true) : null;
if (is_array($apiErrorsRaw)) {
    if (!isset($en['ui']) || !is_array($en['ui'])) {
        $en['ui'] = [];
    }
    $en['ui']['api_errors'] = $apiErrorsRaw;
}

// Google Translate inserta a veces espacios de ancho cero (U+200B) o BOM
// (U+FEFF) entre palabras. Se eliminan para no ensuciar el HTML ni el canvas.
$limpiar = static function ($v) use (&$limpiar) {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = $limpiar($x);
        }
        return $out;
    }
    return is_string($v) ? str_replace(["\u{200B}", "\u{FEFF}"], '', $v) : $v;
};
$en = $limpiar($en);
$contenido = $limpiar($contenido);

// Unificación de vocabulario en-US y corrección de calcos detectados en la
// revisión editorial. Se aplica solo al inglés generado.
$normalizarEnUs = static function ($v) use (&$normalizarEnUs) {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = $normalizarEnUs($x);
        }
        return $out;
    }
    if (!is_string($v) || $v === '') {
        return $v;
    }
    $v = preg_replace_callback('/\b(football|catalogue)\b/i', static function (array $m): string {
        $minuscula = strtolower($m[1]);
        $reemplazo = $minuscula === 'catalogue' ? 'catalog' : 'soccer';
        return preg_match('/^\p{Lu}/u', $m[1]) ? ucfirst($reemplazo) : $reemplazo;
    }, $v) ?? $v;
    $v = str_replace(
        ["doctor's queue", 'doctor’s queue'],
        ["doctor's waiting room", 'doctor’s waiting room'],
        $v
    );
    return str_replace(
        ['jewel of the letter', 'quick preview', 'well-pulled cane', 'malt amber', 'clear soda'],
        ['jewel of the menu', 'quick pre-game', 'well-poured draft beer', 'amber malt', 'shandy'],
        $v
    );
};
$en = $normalizarEnUs($en);
$contenido = $normalizarEnUs($contenido);

// En inglés no se empieza una cláusula con mayúscula después de un punto y
// coma salvo que sea un nombre propio, una sigla o un CamelCase.
$minusculasTrasPuntoYComa = static function ($v) use (&$minusculasTrasPuntoYComa) {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = $minusculasTrasPuntoYComa($x);
        }
        return $out;
    }
    if (!is_string($v) || $v === '') {
        return $v;
    }
    return preg_replace_callback('/;\s+([A-Za-zÁÉÍÓÚÑÜ][\w\'’\-\.]*)/u', static function (array $m): string {
        $palabra = $m[1];
        $permitidas = ['Game', 'Draft', 'Modern', 'Studio', 'I'];
        if (in_array($palabra, $permitidas, true)) {
            return $m[0];
        }
        if (mb_strlen($palabra, 'UTF-8') > 1 && mb_strtoupper($palabra, 'UTF-8') === $palabra) {
            return $m[0];
        }
        if (preg_match('/^\p{Lu}\p{Ll}*\p{Lu}/u', $palabra)) {
            return $m[0];
        }
        if (!preg_match('/^\p{Lu}/u', $palabra)) {
            return $m[0];
        }
        return '; ' . mb_strtolower(mb_substr($palabra, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($palabra, 1, null, 'UTF-8');
    }, $v) ?? $v;
};
$en = $minusculasTrasPuntoYComa($en);
$contenido = $minusculasTrasPuntoYComa($contenido);

// Capitaliza nombres visibles (ítems y temáticas) cuando la traducción perdió
// la mayúscula inicial. Respeta marcas que se escriben en minúscula.
$capitalizarNombres = static function (array $enMap, array $esMap): array {
    $estilizados = [
        'j-hope', 'aespa', 'xQc', 's1mple', 'ZywOo', 'eSports', 'eBay',
        'iPhone', 'iPad', 'iPod', 'iMac', 'macOS', 'iOS',
    ];
    foreach ($enMap as $k => $v) {
        if (!is_string($v) || $v === '') {
            continue;
        }
        $es = $esMap[$k] ?? null;
        if (!is_string($es) || !preg_match('/^\p{Lu}/u', $es)) {
            continue;
        }
        if (!preg_match('/^\p{Ll}/u', $v) || in_array($v, $estilizados, true)) {
            continue;
        }
        $enMap[$k] = mb_strtoupper(mb_substr($v, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($v, 1, null, 'UTF-8');
    }
    return $enMap;
};
if (isset($en['items']) && is_array($en['items']) && isset($source['lang']['items']) && is_array($source['lang']['items'])) {
    $en['items'] = $capitalizarNombres($en['items'], $source['lang']['items']);
}
if (isset($en['tematicas']) && is_array($en['tematicas']) && isset($source['lang']['tematicas']) && is_array($source['lang']['tematicas'])) {
    $en['tematicas'] = $capitalizarNombres($en['tematicas'], $source['lang']['tematicas']);
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents($root . '/lang/en.json', json_encode($en, $flags) . "\n");
file_put_contents($root . '/lang/contenido_en.json', json_encode($contenido, $flags) . "\n");

echo 'lang/en.json: ' . count($en) . " secciones\n";
echo 'lang/contenido_en.json: ' . count($contenido) . " secciones\n";
echo "Sin traducir: {$faltan}\n";
