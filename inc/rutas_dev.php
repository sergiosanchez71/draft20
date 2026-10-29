<?php
/**
 * Rutas limpias para el servidor embebido de PHP (php -S).
 *
 * El servidor de desarrollo no lee .htaccess: cualquier ruta sin extensión
 * acaba ejecutando index.php. Este despachador replica las rewrites de
 * .htaccess para que /como-jugar, /tematica/<id>, /guia/<slug>… funcionen
 * igual en local. Solo se activa con PHP_SAPI === 'cli-server'; en producción
 * las rewrites las resuelve Apache antes de llegar a index.php.
 */
declare(strict_types=1);

/** ¿Estamos bajo el servidor embebido de PHP? */
function rutas_dev_activas(): bool
{
    return PHP_SAPI === 'cli-server';
}

/** Ejecuta la página que corresponde a la ruta limpia solicitada. */
function rutas_dev_despachar(): void
{
    if (!rutas_dev_activas()) {
        return;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $ruta = parse_url($uri, PHP_URL_PATH);
    if (!is_string($ruta) || $ruta === '') {
        $ruta = '/';
    }
    $ruta = rawurldecode($ruta);

    // La home y el propio index.php siguen su curso normal.
    if ($ruta === '/' || $ruta === '/index.php') {
        return;
    }

    // Mismas rutas que .htaccess (sin las redirecciones de dominio/HTTPS).
    // Cada regla: [patrón, archivo, parámetros => índice del grupo].
    $reglas = [
        ['#^/tematica/([a-z0-9_]+)/?$#', 'tematica.php', ['id' => 1]],
        ['#^/categoria/([a-z0-9_]+)/?$#', 'categoria.php', ['id' => 1]],
        ['#^/guia/([a-z0-9-]+)/?$#', 'guia.php', ['slug' => 1]],
        ['#^/guias/?$#', 'guias.php', []],
        ['#^/como-jugar/?$#', 'como_jugar.php', []],
        ['#^/como-jugar-y-estrategia/?$#', 'estrategia.php', []],
        ['#^/preguntas-frecuentes/?$#', 'preguntas-frecuentes.php', []],
        ['#^/terminos/?$#', 'terminos.php', []],
        ['#^/acerca/?$#', 'acerca.php', []],
        ['#^/contacto/?$#', 'contacto.php', []],
        ['#^/privacidad/?$#', 'privacidad.php', []],
        ['#^/aviso-legal/?$#', 'aviso_legal.php', []],
        ['#^/glosario/?$#', 'glosario.php', []],
        ['#^/ranking/?$#', 'ranking.php', []],
        ['#^/juegos-de-subasta/?$#', 'juegos_de_subasta.php', []],
    ];

    foreach ($reglas as $regla) {
        [$patron, $archivo, $parametros] = $regla;
        if (!preg_match($patron, $ruta, $coincidencias)) {
            continue;
        }
        foreach ($parametros as $nombre => $indice) {
            $valor = (string) ($coincidencias[$indice] ?? '');
            $_GET[$nombre] = $valor;
            $_REQUEST[$nombre] = $valor;
        }
        $_SERVER['SCRIPT_NAME'] = '/' . $archivo;
        require __DIR__ . '/../' . $archivo;
        exit;
    }

    // Rutas internas y desconocidas: 404 real, como en producción.
    http_response_code(404);
    $_SERVER['SCRIPT_NAME'] = '/404.php';
    require __DIR__ . '/../404.php';
    exit;
}
