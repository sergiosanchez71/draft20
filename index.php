<?php
declare(strict_types=1);

$langFile = __DIR__ . '/lang/es.json';
$LANG = json_decode((string) file_get_contents($langFile), true);
if (!is_array($LANG)) {
    http_response_code(500);
    echo 'Error cargando i18n';
    exit;
}

$salaFromLink = $_GET['sala'] ?? null;
if (!is_string($salaFromLink) || !preg_match('/^[A-Z0-9]{5}$/', $salaFromLink)) {
    $salaFromLink = null;
}

// Catálogo de temáticas agrupadas por categoría. Fuente única para el lobby.
$categorias = [
    ['id' => 'comida', 'emoji' => '🍔', 'tematicas' => [
        ['id' => 'hamburguesa', 'emoji' => '🍔'],
        ['id' => 'tapas',       'emoji' => '🍤'],
        ['id' => 'pizza',       'emoji' => '🍕'],
        ['id' => 'barbacoa',    'emoji' => '🥩'],
        ['id' => 'sushi',       'emoji' => '🍣'],
        ['id' => 'postres',     'emoji' => '🍰'],
        ['id' => 'desayuno',    'emoji' => '☕'],
        ['id' => 'cerveza',     'emoji' => '🍺'],
    ]],
    ['id' => 'cultura', 'emoji' => '🎬', 'tematicas' => [
        ['id' => 'peliculas',   'emoji' => '🎬'],
        ['id' => 'videojuegos', 'emoji' => '🎮'],
        ['id' => 'musica',      'emoji' => '🎸'],
        ['id' => 'series',      'emoji' => '📺'],
        ['id' => 'anime',       'emoji' => '🌸'],
        ['id' => 'comics',      'emoji' => '💥'],
        ['id' => 'juegos_mesa', 'emoji' => '🎲'],
        ['id' => 'libros',      'emoji' => '📚'],
        ['id' => 'teatro',      'emoji' => '🎭'],
        ['id' => 'karaoke',     'emoji' => '🎤'],
    ]],
    ['id' => 'deporte', 'emoji' => '⚽', 'tematicas' => [
        ['id' => 'futbol',     'emoji' => '⚽'],
        ['id' => 'coches',     'emoji' => '🏎️'],
        ['id' => 'baloncesto', 'emoji' => '🏀'],
        ['id' => 'tenis',      'emoji' => '🎾'],
        ['id' => 'olimpiadas', 'emoji' => '🥇'],
        ['id' => 'gimnasio',   'emoji' => '🏋️'],
        ['id' => 'esports',    'emoji' => '🖥️'],
        ['id' => 'boxeo',      'emoji' => '🥊'],
    ]],
    ['id' => 'fantasia', 'emoji' => '🐉', 'tematicas' => [
        ['id' => 'zombies',   'emoji' => '🧟'],
        ['id' => 'poderes',   'emoji' => '🦸'],
        ['id' => 'magos',     'emoji' => '🪄'],
        ['id' => 'villanos',  'emoji' => '😈'],
        ['id' => 'dragones',  'emoji' => '🐉'],
        ['id' => 'fantasmas', 'emoji' => '👻'],
    ]],
    ['id' => 'historia', 'emoji' => '🏴‍☠️', 'tematicas' => [
        ['id' => 'piratas',    'emoji' => '🏴‍☠️'],
        ['id' => 'medieval',   'emoji' => '🏰'],
        ['id' => 'gladiador',  'emoji' => '⚔️'],
        ['id' => 'vikingos',   'emoji' => '🪓'],
        ['id' => 'egipto',     'emoji' => '🔺'],
        ['id' => 'romanos',    'emoji' => '🏛️'],
        ['id' => 'samurais',   'emoji' => '🥋'],
        ['id' => 'vaqueros',   'emoji' => '🤠'],
    ]],
    ['id' => 'ciencia', 'emoji' => '🚀', 'tematicas' => [
        ['id' => 'espacio',  'emoji' => '🚀'],
        ['id' => 'robots',   'emoji' => '🤖'],
        ['id' => 'inventos', 'emoji' => '💡'],
        ['id' => 'criptos',  'emoji' => '🪙'],
    ]],
    ['id' => 'naturaleza', 'emoji' => '🌴', 'tematicas' => [
        ['id' => 'vacaciones',     'emoji' => '🏖️'],
        ['id' => 'animales',       'emoji' => '🦁'],
        ['id' => 'granja',         'emoji' => '🚜'],
        ['id' => 'dinosaurios',    'emoji' => '🦖'],
        ['id' => 'fondo_marino',   'emoji' => '🐋'],
        ['id' => 'selva',          'emoji' => '🐆'],
        ['id' => 'montana',        'emoji' => '🏔️'],
        ['id' => 'isla_desierta',  'emoji' => '🏝️'],
    ]],
    ['id' => 'vida', 'emoji' => '🏠', 'tematicas' => [
        ['id' => 'pareja',     'emoji' => '❤️'],
        ['id' => 'oficina',    'emoji' => '🏠'],
        ['id' => 'influencer', 'emoji' => '📷'],
        ['id' => 'boda',       'emoji' => '👰'],
    ]],
    ['id' => 'crimen', 'emoji' => '🕵️', 'tematicas' => [
        ['id' => 'atraco',         'emoji' => '🏦'],
        ['id' => 'detective',      'emoji' => '🔍'],
        ['id' => 'ciberseguridad', 'emoji' => '🐞'],
    ]],
];
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <title>Draft 20</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>window.LANG = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
    <script>window.__CATEGORIAS = <?= json_encode($categorias, JSON_UNESCAPED_UNICODE) ?>;</script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col">
    <main id="app" class="flex-1 flex flex-col"></main>
    <script src="js/app.js"></script>
    <script>
        (function () {
            const linkSala = <?= json_encode($salaFromLink, JSON_UNESCAPED_UNICODE) ?>;
            window.__init(linkSala);
        })();
    </script>
</body>
</html>