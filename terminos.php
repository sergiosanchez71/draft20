<?php
/**
 * Draft 20 — Términos de uso (/terminos).
 *
 * Condiciones del servicio: uso gratuito sin cuenta, conducta, disponibilidad
 * y límites. Complementa al aviso legal (datos del titular, LSSI-CE) y a la
 * política de privacidad (datos). Sin anuncios por ser página legal.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

pagina_head([
    'titulo' => 'Términos de uso — ' . SITE_NOMBRE,
    'descripcion' => 'Condiciones de uso de Draft 20: servicio gratuito sin registro, conducta esperada y disponibilidad del juego.',
    'canonical' => '/terminos',
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-3">Términos de uso</h1>
        <p class="text-slate-300 text-sm leading-relaxed mb-8">Al jugar a Draft 20 aceptas estas condiciones. Son cortas y están en lenguaje normal.</p>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">El servicio</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                Draft 20 es un juego gratuito de subasta por turnos para dos jugadores, accesible desde el
                navegador sin crear cuentas ni instalar nada. No hay pagos, suscripciones ni monedas de verdad:
                las 20 monedas son el presupuesto de cada partida y no tienen ningún valor fuera del juego.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Uso correcto</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                Puedes crear salas, invitar a quien quieras y jugar todas las partidas que te apetezcan. No está
                permitido automatizar el juego con bots externos, interferir con el servicio ni usarlo para fines
                distintos de jugar. Los nombres ofensivos pueden ser expulsados de las salas.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Disponibilidad</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                El servicio se ofrece tal cual, sin garantías de disponibilidad permanente. Puede interrumpirse
                por mantenimiento o causas técnicas, y puede modificarse o retirarse sin previo aviso. Las
                partidas en curso dependen de la conexión de ambos jugadores.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Contenido del juego</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                Los nombres de personajes, obras y marcas citados en las temáticas se usan con fines de
                entretenimiento y referencia; el proyecto no está afiliado ni respaldado por sus titulares.
            </p>
        </section>

        <p class="text-sm text-slate-300 leading-relaxed mb-6">
            Para datos personales consulta la <a class="hover:text-amber-400" href="/privacidad">política de privacidad</a>
            y para los datos del titular el <a class="hover:text-amber-400" href="/aviso-legal">aviso legal</a>.
        </p>

        <p class="text-xs text-slate-400 mt-8 mb-6">Última actualización: <?= e(date('m/Y')) ?></p>
    </main>
<?php
pagina_foot();
