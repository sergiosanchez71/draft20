<?php
/**
 * Draft 20 — Contenido SEO: pilar de estrategia (/como-jugar-y-estrategia).
 *
 * Redacción editorial original (800+ palabras): concepto, reglas de las
 * 8 rondas, banca de 20 monedas, psicología de la puja y estrategias por
 * temática. Sin valores ⭐ concretos de ningún ítem.
 *
 * Estructura de bloques: ['h2' => ...], ['h3' => ...], ['p' => [...]],
 * ['ul' => [...]].
 */
declare(strict_types=1);

return [
    'titulo' => 'Cómo jugar a Draft 20 y ganar: reglas y estrategia completa',
    'desc' => 'Guía completa de Draft 20: concepto, reglas de las 8 rondas, gestión de las 20 monedas, psicología de la puja (farol, inflar precios, forzar sobrecostes) y estrategias por temática.',
    'h1' => 'Cómo jugar a Draft 20 y ganar: estrategia completa',
    'fecha' => '2026-09-28',
    'intro' => [
        'Draft 20 es un duelo de subastas para dos jugadores: 8 rondas, 20 monedas por cabeza y una temática por partida. Cada ronda sale un ítem a subasta, pujáis por turnos y quien se lo lleva lo añade a su equipo de 4. Gana quien suma más estrellas al final. Las reglas se aprenden en dos minutos; ganar de forma consistente exige gestionar la banca, leer al rival y conocer la temática.',
        'Esta guía es el manual completo: primero las reglas paso a paso y después la estrategia que separa a quien improvisa de quien gana series. Si solo quieres las reglas, las tienes resumidas en la página de cómo se juega.',
    ],
    'bloques' => [
        ['h2' => 'Concepto de Draft 20'],
        ['p' => [
            'La idea viene de los vídeos virales del «draft de 20 dólares»: un presupuesto fijo a repartir entre una lista de ítems, donde la gracia está en elegir bien y no en poder pagar más que nadie. Draft 20 convierte esa dinámica en un duelo con reglas de verdad: el presupuesto son 20 monedas, la lista son 20 ítems de una temática y el reparto se decide pujando, no votando.',
            'Cada partida enfrenta a dos jugadores con las mismas condiciones: las mismas 20 monedas, los mismos 8 ítems sorteados de la temática y la misma información. No hay azar después del sorteo inicial ni ventajas ocultas: si pierdes, es porque el rival pujó mejor que tú. Y con la revancha a un toque, siempre hay una segunda oportunidad con otra temática.',
        ]],
        ['h2' => 'Reglas paso a paso de las 8 rondas'],
        ['p' => [
            'Una partida tiene exactamente 8 rondas y cada ronda subasta un ítem distinto. El equipo final de cada jugador tiene 4 ítems como máximo: cuando alguien llega a 4, los ítems que salgan después pasan automáticamente al rival. Así las 8 rondas siempre reparten 4 y 4.',
        ]],
        ['ul' => [
            'Ronda 1 a 8: sale un ítem de la temática con su precio inicial en 0.',
            'El jugador al que le toca empieza: puede PUJAR +1, PUJAR +3 o plantarse con ME BAJO.',
            'Los turnos alternan hasta que uno de los dos se baja: el último que pujó paga el precio final y se lleva el ítem.',
            'Si te toca pujar y no tienes monedas, pulsas PASAR y el rival decide: quedarse el ítem por 1 moneda o regalártelo.',
            'El valor en estrellas de cada ítem (de 1 a 10) es secreto hasta el final, salvo que juguéis con valores visibles.',
            'Al completar las 8 rondas gana quien sume más estrellas; si hay empate, gana quien conserve más monedas.',
        ]],
        ['p' => [
            'El orden de salida importa: quien empieza la ronda pone la primera puja y marca el tono, pero quien se baja último decide quién paga. Por eso la partida no va de ganar pujas, sino de pagar el precio justo por los ítems que quieres y caro por los que quiere el rival.',
        ]],
        ['h2' => 'Gestión matemática de la banca: 20 monedas'],
        ['p' => [
            'Tienes 20 monedas para 4 ítems: la media justa son 5 monedas por ítem. Todo lo que pagues de más en un ítem lo estás quitando de los otros tres, y todo lo que ahorres en un ítem mediocre lo puedes invertir en la estrella de la lista. La banca no se gestiona por sensaciones, sino con dos reglas sencillas.',
        ]],
        ['h3' => 'La regla del tercio'],
        ['p' => [
            'Reserva unas 6-7 monedas (un tercio) para la segunda mitad de la partida. Los ítems gordos suelen salir cuando ya has gastado, y llegar a las rondas 6-8 sin banca te convierte en espectador: el rival se lleva lo mejor al precio que quiera. Si a mitad de partida has gastado más de 13-14 monedas, estás comprando demasiado caro.',
        ]],
        ['h3' => 'Cuándo gastar y cuándo guardar'],
        ['p' => [
            'Gasta fuerte solo en ítems que cambien la partida: los que claramente valen 8 o más estrellas en su temática. En el resto, tu objetivo no es llevártelos baratos, sino obligar al rival a pagarlos caros (ver psicología). Y nunca te quedes a cero a propósito: sin monedas pierdes el turno de puja y el rival decide por ti con la regla de PASAR.',
        ]],
        ['ul' => [
            'Presupuesto medio por ítem: 5 monedas; más de 7 en un ítem normal es sobreprecio.',
            'A mitad de partida (ronda 4) deberías conservar al menos 6-7 monedas.',
            'El empate se rompe por monedas restantes: cada moneda guardada vale doble.',
        ]],
        ['h2' => 'Psicología de la puja: farol, inflar y forzar'],
        ['p' => [
            'Como los valores son secretos, la subasta es un juego de información: cada puja dice algo de lo que crees que vale el ítem, y cada plantada también. Quien mejor miente y mejor lee, gana. Estas son las tres armas.',
        ]],
        ['h3' => 'El farol: pujar lo que no quieres'],
        ['p' => [
            'Pujar con fuerza por un ítem mediocre hace creer al rival que es una joya, y a veces se lo lleva pagando de más por miedo a dejártelo barato. El farol funciona mejor a principio de partida, cuando aún no se conocen los gustos de cada uno, y con moderación: si te descubren, el rival dejará de creerte cuando pujes de verdad.',
        ]],
        ['h3' => 'Inflar precios: la subida que no duele'],
        ['p' => [
            'Cuando el rival quiere claramente un ítem, cada +1 o +3 que añadas antes de bajarte es dinero que no tendrá después. La técnica es subir de uno en uno hasta notar que duda, y bajarse justo antes de que el precio te comprometa a ti. Un ítem que él habría pagado a 4 y se lleva a 7 son 3 monedas menos para el resto de la partida.',
        ]],
        ['h3' => 'Forzar sobrecostes al rival'],
        ['p' => [
            'La versión avanzada de inflar: identificar el ítem que el rival necesita sí o sí (por ejemplo, el último hueco de su equipo) y exprimirlo hasta el límite. Si necesita ese ítem para completar 4, pagará casi cualquier cosa, y saldrá de esa ronda sin banca para lo que venga. Eso sí: calcula bien, porque si se baja antes que tú, el sobrecoste lo pagas tú.',
        ]],
        ['h2' => 'Estrategias por temática'],
        ['p' => [
            'No todas las temáticas se juegan igual, porque no todas las listas están construidas igual. En las temáticas que dominas (Pizza, Fútbol), fíate de tu criterio: sabes qué ítems son élite y cuáles son relleno. En las que no dominas, juega a banca y a rival: gasta poco, infla mucho y guarda para el final.',
        ]],
        ['ul' => [
            'Temáticas conocidas (Pizza, Fútbol): identifica tus 2-3 ítems élite antes de empezar y ve a por ellos; el resto es moneda de cambio para inflar.',
            'Temáticas de cultureta (Juegos de mesa, Música): si el rival sabe más que tú, no compitas en conocimiento; compite en banca y deja que pague caro su sabiduría.',
            'Temática aleatoria («Todas»): nadie puede preparar nada, así que gana quien mejor improvisa: faroles baratos, banca para el final y cero apego a ningún ítem.',
            'Con el cap de 4 en mente: si ya tienes 3 ítems y queda poco, cada moneda vale más, porque el rival sabe que necesitas cerrar equipo.',
            'Sin monedas (PASAR): si el rival te regala el ítem, a veces es porque vale poco; si se lo queda por 1, era mejor de lo que parecía. Apunta esa información.',
        ]],
        ['p' => [
            'Y la última estrategia no es de partida, sino de serie: juega la revancha siempre con otra temática. Cambiar de lista resetea las lecturas del rival y premia al jugador más completo, no al más especialista.',
        ]],
    ],
    'enlaces' => [
        ['href' => '/como-jugar', 'texto' => 'Reglas resumidas: cómo se juega'],
        ['href' => '/guia/como-ganar-draft-20', 'texto' => 'Tácticas para ganar la revancha'],
        ['href' => '/guia/mejores-tematicas', 'texto' => 'Las temáticas más divertidas'],
        ['href' => '/tematica/pizza', 'texto' => 'Practicar con la temática de pizza'],
    ],
];
