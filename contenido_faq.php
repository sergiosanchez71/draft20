<?php
/**
 * Draft 20 — Contenido SEO: preguntas frecuentes (/preguntas-frecuentes).
 *
 * 12 respuestas editoriales originales (500+ palabras) sobre salas,
 * puntuación, desconexiones, temáticas y móvil. Se renderizan en <details>
 * accesibles + JSON-LD FAQPage, todo en el HTML del servidor.
 */
declare(strict_types=1);

return [
    'titulo' => 'Preguntas frecuentes sobre Draft 20',
    'desc' => 'Resolvemos las dudas de Draft 20: salas privadas e invitaciones, cómo se puntúa, qué pasa si te desconectas, temáticas disponibles y cómo jugar en el móvil.',
    'h1' => 'Preguntas frecuentes sobre Draft 20',
    'fecha' => '2026-09-28',
    'intro' => [
        'Aquí tienes las respuestas a lo que más nos preguntan: cómo montar una partida con un amigo, cómo se decide el ganador, qué ocurre si se cae la conexión a mitad de ronda y en qué dispositivos se puede jugar.',
    ],
    'faq' => [
        [
            'q' => '¿Cómo creo una sala privada para jugar con un amigo?',
            'a' => 'Desde la página principal, escribe tu nombre, elige temática (o deja «Todas» para que se sortee) y pulsa crear sala. Obtendrás un código de 5 letras que puedes compartir por WhatsApp o con el enlace de invitación directo: tu rival lo abre, entra con su nombre y la partida empieza sola, sin registros.',
        ],
        [
            'q' => '¿Cómo se calcula la puntuación y quién gana?',
            'a' => 'Cada ítem tiene un valor secreto de 1 a 10 estrellas que solo se revela al final. Gana quien sume más estrellas con sus 4 ítems. Si hay empate a estrellas, gana quien haya conservado más monedas, así que cada moneda que ahorres cuenta doble.',
        ],
        [
            'q' => '¿Qué pasa si me desconecto a mitad de una ronda?',
            'a' => 'Puedes volver a entrar con el mismo código de sala desde el mismo navegador y recuperarás tu sitio, porque la sesión se guarda en tu dispositivo. Si abandonas definitivamente, la partida no cuenta para las estadísticas y tu rival puede seguir practicando o pedir la revancha en otro momento.',
        ],
        [
            'q' => '¿Cuántas temáticas hay y cuáles son las mejores para empezar?',
            'a' => 'Hay 69 temáticas de 20 ítems cada una, con una selección de Destacadas que rota con las tendencias. Para empezar recomendamos Pizza y Fútbol, porque todo el mundo reconoce los ítems; cuando controles el juego, la opción «Todas» sortea temática en cada partida y ninguna se repite.',
        ],
        [
            'q' => '¿Se puede jugar en el móvil? ¿Hay que instalar algo?',
            'a' => 'Sí, y no hay que instalar nada: se juega en el navegador del móvil, con botones grandes pensados para pantallas táctiles. Si quieres, puedes añadirlo a la pantalla de inicio como una app más; funcionará a pantalla completa y con su icono propio.',
        ],
        [
            'q' => '¿Puedo jugar solo contra la máquina?',
            'a' => 'Sí, con el modo práctica: eliges temática y dificultad (Fácil, Normal, Difícil o Extremo) y el bot ocupa el segundo asiento con las mismas reglas que tú. También tienes la práctica guiada, que explica cada paso mientras juegas en fácil. Y desde la ficha de cada temática puedes entrar directo a una partida contra el bot en un clic.',
        ],
        [
            'q' => '¿Cómo funciona la revancha?',
            'a' => 'Al terminar la partida, cualquiera de los dos puede proponer revancha con una temática nueva desde la pantalla final. El rival la acepta o la rechaza allí mismo, y quien empieza la primera ronda alterna para que nadie tenga ventaja. Las revanchas encadenadas forman series al mejor de 3.',
        ],
        [
            'q' => '¿Hace falta registrarse o pagar algo?',
            'a' => 'No: es gratis y sin cuentas. Entras con un nombre temporal, juegas y listo. No hay pagos, ni suscripciones, ni monedas de verdad: las 20 monedas son solo el presupuesto de cada partida.',
        ],
        [
            'q' => '¿Qué son los valores visibles (⭐)?',
            'a' => 'Es un modo opcional que muestra las estrellas de cada ítem durante la partida, en vez de mantenerlas secretas hasta el final. Lo elige quien crea la sala y afecta a los dos jugadores. Es ideal para aprender, aunque la partida pierde parte del misterio y del farol.',
        ],
        [
            'q' => '¿Cuánto dura una partida?',
            'a' => 'Unos cinco minutos: 8 rondas de subasta por turnos con decisiones rápidas. Es perfecto para encadenar dos o tres seguidas en un descanso, y la revancha se monta en segundos con otra temática.',
        ],
        [
            'q' => '¿Podemos jugar más de dos personas?',
            'a' => 'Las partidas son duelos 1 contra 1, pero con más gente lo normal es montar liguilla: dos juegan, el ganador se queda y entra el siguiente retador. El resto puede hacer de jurado y votar el mejor equipo al final, como en los vídeos originales del trend.',
        ],
        [
            'q' => '¿Mis partidas y estadísticas se guardan?',
            'a' => 'Tus resultados se guardan en tu propio navegador (victorias, rachas y logros), sin cuentas ni servidores con tus datos. Solo se registran estadísticas anónimas de partidas terminadas (temática y modo) para el ranking mensual de lo más jugado, sin nombres ni identificadores.',
        ],
    ],
    'enlaces' => [
        ['href' => '/como-jugar', 'texto' => 'Reglas resumidas: cómo se juega'],
        ['href' => '/como-jugar-y-estrategia', 'texto' => 'Estrategia completa para ganar'],
        ['href' => '/guias', 'texto' => 'Todas las guías'],
    ],
];
