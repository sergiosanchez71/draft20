<?php
/**
 * Draft 20 — Aviso legal (/aviso-legal).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

pagina_head([
    'titulo' => 'Aviso legal — ' . SITE_NOMBRE,
    'descripcion' => 'Aviso legal e información del titular del sitio Draft 20 (LSSI-CE).',
    'canonical' => '/aviso-legal',
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <h1 class="text-3xl font-bold text-slate-100 mb-3">Aviso legal</h1>
        <p class="text-sm text-slate-300 leading-relaxed mb-6">
            En cumplimiento del artículo 10 de la Ley 34/2002, de Servicios de la Sociedad de la Información
            y de Comercio Electrónico (LSSI-CE), se informa de los siguientes datos.
        </p>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Titular del sitio</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                <?= e(SITE_NOMBRE) ?> — proyecto independiente.
                Contacto: <a class="hover:text-amber-400" href="mailto:<?= e(SITE_EMAIL) ?>"><?= e(SITE_EMAIL) ?></a>.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Objeto</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                El sitio ofrece un juego gratuito de subasta por turnos para dos jugadores. No requiere registro,
                no tiene anuncios ni pagos, y no se recogen datos personales más allá de lo descrito en la
                <a class="hover:text-amber-400" href="/privacidad">política de privacidad</a>.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Propiedad intelectual</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                El código, los textos y el diseño del sitio pertenecen a su titular. Los nombres de personajes,
                obras, marcas y personajes públicos citados en el juego se usan con fines de entretenimiento y
                referencia; el proyecto no está afiliado ni respaldado por sus titulares.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Responsabilidad</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                El titular no se responsabiliza del uso indebido del servicio ni de interrupciones o errores
                derivados de causas técnicas, y puede modificar o retirar el servicio sin previo aviso.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Enlaces a terceros</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                Los enlaces a sitios de terceros se ofrecen a título informativo, sin que el titular controle
                sus contenidos.
            </p>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-bold text-amber-300 mb-2">Legislación aplicable</h2>
            <p class="text-sm text-slate-300 leading-relaxed">
                Este aviso se rige por la legislación española.
            </p>
        </section>

        <p class="text-xs text-slate-500 mt-8 mb-6">Última actualización: <?= e(date('m/Y')) ?></p>
    </main>
<?php
pagina_foot();
