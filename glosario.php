<?php
/**
 * Draft 20 — Glosario (/glosario).
 *
 * Definiciones de los términos del juego con DefinedTermSet.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$terminos = [
    ['id' => 'draft', 'nombre' => 'Draft', 'def' => 'Formato en el que se reparte un presupuesto fijo entre una lista de ítems y hay que construir la mejor selección posible. En Draft 20, cada jugador recibe 20 monedas.', 'enlace' => '/guia/draft-de-20-monedas'],
    ['id' => 'subasta', 'nombre' => 'Subasta', 'def' => 'En cada ronda sale un ítem y los dos jugadores pujan por turnos. Cuando alguien se baja, el último que pujó paga el precio y se lleva el ítem.', 'enlace' => '/como-jugar'],
    ['id' => 'puja', 'nombre' => 'Puja', 'def' => 'Oferta que sube el precio actual del ítem. En Draft 20 solo hay dos botones: PUJAR +1 y PUJAR +3.', 'enlace' => '/como-jugar'],
    ['id' => 'me-bajo', 'nombre' => 'ME BAJO', 'def' => 'Retirarse de la subasta. Si el rival es el último pujador, paga el precio y se queda el ítem; tú no gastas nada.', 'enlace' => '/guia/como-ganar-draft-20'],
    ['id' => 'ultimo-pujador', 'nombre' => 'Último pujador', 'def' => 'Jugador que marcó el precio actual. Si el rival se baja, él paga y se lleva el ítem; por eso conviene medir cuándo pujar.', 'enlace' => '/guia/como-ganar-draft-20'],
    ['id' => 'valor', 'nombre' => 'Valor (⭐)', 'def' => 'Calidad intrínseca de cada ítem, en una escala de 1 a 10. Es secreto hasta el final de la partida y decide al ganador.', 'enlace' => '/como-jugar'],
    ['id' => 'item', 'nombre' => 'Ítem', 'def' => 'Cada elemento que se subasta: un personaje, un objeto o un plato. Cada temática tiene 20 ítems distintos.', 'enlace' => '/#tematicas'],
    ['id' => 'tematica', 'nombre' => 'Temática', 'def' => 'La lista de 20 ítems sobre la que se juega la partida (fútbol, anime, comida, streamers…). Draft 20 tiene 72 temáticas.', 'enlace' => '/#tematicas'],
    ['id' => 'cap', 'nombre' => 'Cap de 4 ítems', 'def' => 'Máximo de ítems por jugador. Al llegar a 4, los ítems que salgan después pasan automáticamente al rival.', 'enlace' => '/guia/como-ganar-draft-20'],
    ['id' => 'deadlock', 'nombre' => 'PASAR (sin monedas)', 'def' => 'Si te toca un ítem fresco y no tienes dinero, pulsas PASAR: el rival decide si se lo queda por 1 🪙 o te lo regala.', 'enlace' => '/como-jugar'],
    ['id' => 'valores-visibles', 'nombre' => 'Valores visibles', 'def' => 'Modo opcional que muestra el ⭐ de cada ítem durante la partida. Lo fija quien crea la sala y afecta a los dos jugadores.', 'enlace' => '/como-jugar'],
    ['id' => 'revancha', 'nombre' => 'Revancha', 'def' => 'Al terminar, cualquiera puede proponer una revancha con temática nueva; el rival la acepta o la rechaza desde la pantalla final.', 'enlace' => '/como-jugar'],
    ['id' => 'serie', 'nombre' => 'Serie al mejor de 3', 'def' => 'Marcador de revanchas contra el mismo rival: quien gana dos partidas se lleva la serie.', 'enlace' => '/guia/como-ganar-draft-20'],
    ['id' => 'bot', 'nombre' => 'Bot de práctica', 'def' => 'Rival controlado por la máquina con cuatro niveles: Fácil, Normal, Difícil y Extremo. Sirve para practicar sin rival humano.', 'enlace' => '/como-jugar'],
];

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Glosario'],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'DefinedTermSet',
        'name' => 'Glosario de Draft 20',
        'inLanguage' => 'es',
        'hasDefinedTerm' => array_map(static function (array $t): array {
            return [
                '@type' => 'DefinedTerm',
                'name' => $t['nombre'],
                'description' => $t['def'],
                'url' => SITE_URL . '/glosario#' . $t['id'],
            ];
        }, $terminos),
    ],
];

pagina_head([
    'titulo' => 'Glosario de Draft 20: draft, subasta, puja y más',
    'descripcion' => 'Todos los términos de Draft 20 explicados: draft, subasta, puja, ME BAJO, valor ⭐, cap de 4 ítems, deadlock, revancha y serie.',
    'canonical' => '/glosario',
    'og_image' => SITE_URL . '/og/seccion/glosario.png',
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/">Inicio</a>
            <span class="mx-1">/</span>
            <span class="text-slate-300">Glosario</span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-3">Glosario de Draft 20</h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-8">
            Los términos que aparecen en el juego y en las guías, explicados en una frase. Si acabas de llegar,
            empieza por <a class="text-amber-400 hover:text-amber-300" href="/como-jugar">cómo se juega</a>.
        </p>

        <dl class="space-y-3 mb-10">
            <?php foreach ($terminos as $t): ?>
            <div id="<?= e($t['id']) ?>" class="bg-slate-800 border border-slate-700 rounded-lg p-4">
                <dt class="font-bold text-amber-300"><?= e($t['nombre']) ?></dt>
                <dd class="text-sm text-slate-300 leading-relaxed mt-1"><?= e($t['def']) ?></dd>
                <?php if (!empty($t['enlace'])): ?>
                <dd class="mt-2"><a class="text-xs font-semibold text-slate-400 hover:text-amber-400" href="<?= e($t['enlace']) ?>">Ver más →</a></dd>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </dl>

        <a href="/#app" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-6">Jugar ahora</a>
    </main>
<?php
pagina_foot();
