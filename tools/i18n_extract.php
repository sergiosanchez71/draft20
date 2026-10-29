<?php
/**
 * Draft 20 — Extrae todo el contenido traducible a un JSON de trabajo.
 *
 * Uso: php tools/i18n_extract.php
 * Escribe lang/_source_es.json (no se publica; solo alimenta la traducción).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
$lang = json_decode((string) file_get_contents($root . '/lang/es.json'), true);
if (!is_array($lang)) {
    fwrite(STDERR, "No se pudo leer lang/es.json\n");
    exit(1);
}

$tematicas = (require $root . '/contenido_tematicas_1.php') + (require $root . '/contenido_tematicas_2.php');
$guias = (require $root . '/contenido_guias_1.php')
    + (require $root . '/contenido_guias_2.php')
    + (require $root . '/contenido_guias_3.php')
    + (require $root . '/contenido_guias_4.php');

$data = [
    'lang' => $lang,
    'contenido' => [
        'categorias' => require $root . '/contenido_categorias.php',
        'tematicas' => $tematicas,
        'guias' => $guias,
        'estrategia' => require $root . '/contenido_estrategia.php',
        'faq' => require $root . '/contenido_faq.php',
        'hub' => require $root . '/contenido_hub_subasta.php',
    ],
];

$path = $root . '/lang/_source_es.json';
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

/** Cuenta cadenas traducibles (misma regla que el script Node). */
$n = 0;
$chars = 0;
$walk = static function ($v, string $key = '') use (&$walk, &$n, &$chars): void {
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            $walk($x, is_string($k) ? $k : $key);
        }
        return;
    }
    if (!is_string($v) || trim($v) === '') {
        return;
    }
    if (in_array($key, ['href', 'fecha', 'id', 'enlace', 'slug'], true)) {
        return;
    }
    $n++;
    $chars += mb_strlen($v);
};
$walk($data);

echo "Extraído: {$n} cadenas / {$chars} caracteres\n";
echo "Fuente: lang/_source_es.json\n";
