<?php
/**
 * Draft 20 — Catálogo de temáticas agrupadas por categoría.
 *
 * Fuente única del listado de temáticas para el lobby (index.php) y el juego
 * (juego.php, para header y revancha). Devuelve un array de categorías:
 *   [ ['id' => 'comida', 'emoji' => '🍔', 'tematicas' => [ ['id' => ..., 'emoji' => ...], ... ]], ... ]
 *
 * Las temáticas con 'destacado' => true salen además en la sección
 * ⭐ Destacados al principio de los selectores (SSR + JS).
 */
declare(strict_types=1);

return [
    ['id' => 'comida', 'emoji' => '🍔', 'tematicas' => [
        ['id' => 'hamburguesa', 'emoji' => '🍔', 'destacado' => true],
        ['id' => 'tapas',       'emoji' => '🍤'],
        ['id' => 'pizza',       'emoji' => '🍕', 'destacado' => true],
        ['id' => 'barbacoa',    'emoji' => '🥩'],
        ['id' => 'sushi',       'emoji' => '🍣'],
        ['id' => 'postres',     'emoji' => '🍰'],
        ['id' => 'cerveza',     'emoji' => '🍺'],
    ]],
    ['id' => 'cultura', 'emoji' => '🎬', 'tematicas' => [
        ['id' => 'peliculas',   'emoji' => '🎬'],
        ['id' => 'series',      'emoji' => '📺'],
        ['id' => 'anime',       'emoji' => '🌸', 'destacado' => true],
        ['id' => 'comics',      'emoji' => '💥'],
        ['id' => 'libros',      'emoji' => '📚'],
        ['id' => 'musica',      'emoji' => '🎸'],
        ['id' => 'karaoke',     'emoji' => '🎤'],
        ['id' => 'teatro',      'emoji' => '🎭'],
        ['id' => 'villanos',    'emoji' => '😈'],
    ]],
    ['id' => 'viral', 'emoji' => '📱', 'tematicas' => [
        ['id' => 'simpsons',    'emoji' => '🍩'],
        ['id' => 'disney',      'emoji' => '🏰'],
        ['id' => 'marvel_dc',   'emoji' => '🦸', 'destacado' => true],
        ['id' => 'dragon_ball', 'emoji' => '🐉'],
        ['id' => 'one_piece',   'emoji' => '🏴‍☠️'],
        ['id' => 'harry_potter','emoji' => '🪄', 'destacado' => true],
        ['id' => 'star_wars',   'emoji' => '🌌', 'destacado' => true],
        ['id' => 'pokemon',     'emoji' => '⚡', 'destacado' => true],
        ['id' => 'pop_stars',   'emoji' => '🌟'],
        ['id' => 'streamers',   'emoji' => '🎥'],
        ['id' => 'wwe',         'emoji' => '🤼'],
    ]],
    ['id' => 'deporte', 'emoji' => '⚽', 'tematicas' => [
        ['id' => 'futbol',      'emoji' => '⚽', 'destacado' => true],
        ['id' => 'nba',         'emoji' => '🏀'],
        ['id' => 'tenis',       'emoji' => '🎾'],
        ['id' => 'boxeo',       'emoji' => '🥊'],
        ['id' => 'esports',     'emoji' => '🖥️'],
        ['id' => 'coches',      'emoji' => '🏎️'],
        ['id' => 'olimpiadas',  'emoji' => '🥇'],
        ['id' => 'f1',          'emoji' => '🏁'],
    ]],
    ['id' => 'ocio', 'emoji' => '🎮', 'tematicas' => [
        ['id' => 'videojuegos', 'emoji' => '🎮', 'destacado' => true],
        ['id' => 'juegos_mesa', 'emoji' => '🎲'],
        ['id' => 'consolas',    'emoji' => '🕹️'],
        ['id' => 'moviles',     'emoji' => '📱'],
        ['id' => 'juguetes',    'emoji' => '🧸'],
    ]],
    ['id' => 'fantasia', 'emoji' => '🐉', 'tematicas' => [
        ['id' => 'zombies',     'emoji' => '🧟'],
        ['id' => 'piratas',     'emoji' => '⚓'],
        ['id' => 'vikingos',    'emoji' => '🪓'],
        ['id' => 'romanos',     'emoji' => '🏛️'],
        ['id' => 'egipto',      'emoji' => '🔺'],
        ['id' => 'samurais',    'emoji' => '🥋'],
        ['id' => 'vaqueros',    'emoji' => '🤠'],
    ]],
    ['id' => 'ciencia', 'emoji' => '🚀', 'tematicas' => [
        ['id' => 'espacio',     'emoji' => '🚀'],
        ['id' => 'robots',      'emoji' => '🤖'],
    ]],
    ['id' => 'naturaleza', 'emoji' => '🌴', 'tematicas' => [
        ['id' => 'animales',       'emoji' => '🦁'],
        ['id' => 'dinosaurios',    'emoji' => '🦖'],
        ['id' => 'fondo_marino',   'emoji' => '🐋'],
        ['id' => 'granja',         'emoji' => '🚜'],
    ]],
    ['id' => 'crimen', 'emoji' => '🕵️', 'tematicas' => [
        ['id' => 'atraco',      'emoji' => '🏦'],
        ['id' => 'detective',   'emoji' => '🔍'],
    ]],
];
