<?php
/**
 * Draft 20 — Catálogo de temáticas agrupadas por categoría.
 *
 * Fuente única del listado de temáticas para el lobby (index.php) y el juego
 * (juego.php, para header y revancha). Devuelve un array de categorías:
 *   [ ['id' => 'comida', 'emoji' => '🍔', 'tematicas' => [ ['id' => ..., 'emoji' => ...], ... ]], ... ]
 */
declare(strict_types=1);

return [
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
