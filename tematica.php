<?php
/**
 * Draft 20 — Ficha de temática (/tematica/<id>).
 *
 * SEO: H1, intro, lista de los 20 ítems (sin valores), migas, relacionadas
 * y JSON-LD BreadcrumbList + ItemList. CTA para jugar esa temática.
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contenido_seo.php';

$LANG = cargar_lang();
$SEO  = is_array($LANG['seo'] ?? null) ? $LANG['seo'] : [];

$id = $_GET['id'] ?? null;
$mapa = mapa_tematicas();
if (!is_string($id) || !isset($mapa[$id])) {
    pagina_404();
    exit;
}

$tm = $mapa[$id];
$nombre = nombre_tematica($id);
$categoria = nombre_categoria($tm['categoria_id']);
$items = items_tematica($id);
$cont = tematica_contenido($id);
$preguntas = is_array($cont['preguntas'] ?? null) ? $cont['preguntas'] : [];

// Meta description: descripción editorial única (sin revelar valores).
$descripcion = $cont !== null
    ? recortar((string) $cont['descripcion'], 155)
    : 'Los 20 ítems de ' . $nombre . ' en Draft 20. Subasta por turnos gratis para 2 jugadores, sin registro.';

$titulo = $nombre . ' — 20 ítems para jugar a Draft 20';

$itemList = [];
foreach ($items as $i => $it) {
    $itemList[] = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => (string) ($LANG['items'][$it['id']] ?? $it['id']),
    ];
}

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => (string) ($SEO['migas_inicio'] ?? 'Inicio'), 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $categoria, 'item' => SITE_URL . '/categoria/' . $tm['categoria_id']],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $nombre],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => (string) ($SEO['tematica_items_titulo'] ?? 'Ítems de {t}'),
        'numberOfItems' => count($items),
        'itemListElement' => $itemList,
    ],
];
$faqLd = json_ld_faq($preguntas);
if ($faqLd !== null) {
    $jsonLd[] = $faqLd;
}

$ogFile = __DIR__ . '/og/tematica/' . $id . '.png';

pagina_head([
    'titulo' => $titulo,
    'descripcion' => $descripcion,
    'canonical' => '/tematica/' . $id,
    'og_image' => is_file($ogFile) ? SITE_URL . '/og/tematica/' . $id . '.png' : null,
    'json_ld' => $jsonLd,
]);
?>
    <main class="flex-1 w-full max-w-3xl mx-auto px-4 pt-8">
        <nav class="text-xs text-slate-400 mb-4" aria-label="Migas de pan">
            <a class="hover:text-amber-400" href="/"><?= e((string) ($SEO['migas_inicio'] ?? 'Inicio')) ?></a>
            <span class="mx-1">/</span>
            <a class="hover:text-amber-400" href="/categoria/<?= e($tm['categoria_id']) ?>"><?= e($categoria) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e($nombre) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-2 flex items-center gap-2 justify-center sm:justify-start">
            <?= emoji_icono((string) $tm['emoji'], 40, '') ?>
            <span><?= e($nombre) ?></span>
        </h1>
        <p class="text-slate-400 text-sm mb-1"><a class="hover:text-amber-400 inline-flex items-center gap-1.5" href="/categoria/<?= e($tm['categoria_id']) ?>"><?= emoji_icono((string) $tm['categoria_emoji'], 20, '') ?> <?= e($categoria) ?></a></p>
        <?php if ($cont !== null): ?>
        <p class="text-slate-300 text-sm leading-relaxed mt-4 mb-6"><?= e((string) $cont['descripcion']) ?></p>
        <?php else: ?>
        <p class="text-slate-300 text-sm leading-relaxed mt-4 mb-6"><?= e(str_replace('{t}', $nombre, (string) ($SEO['tematica_intro'] ?? ''))) ?></p>
        <?php endif; ?>
        <?php $jsonTema = __DIR__ . '/tematicas/' . $id . '.json'; if (is_file($jsonTema)): ?>
        <p class="text-xs text-slate-500 mb-6"><?= e(seo_ui('guia_actualizado')) ?>: <?= e(date('m/Y', (int) filemtime($jsonTema))) ?></p>
        <?php endif; ?>

        <button id="btnJugarTema" type="button" data-tematica="<?= e($id) ?>" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap mb-2 disabled:opacity-60"><?= e((string) ($SEO['tematica_cta'] ?? 'Jugar')) ?></button>
        <p class="text-xs text-slate-500 mb-8"><a class="hover:text-amber-400" href="/?tematica=<?= e($id) ?>#app"><?= e('o elige temática en el lobby') ?></a></p>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(str_replace('{t}', $nombre, (string) ($SEO['tematica_items_titulo'] ?? 'Ítems de {t}'))) ?></h2>
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-10">
            <?php foreach ($items as $it): ?>
            <li class="flex items-center gap-3 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2">
                <span class="w-8 flex justify-center flex-shrink-0"><?= emoji_icono((string) $it['emoji'], 28, (string) ($LANG['items'][$it['id']] ?? $it['id'])) ?></span>
                <span class="text-sm text-slate-100"><?= e((string) ($LANG['items'][$it['id']] ?? $it['id'])) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($preguntas !== []): ?>
        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(seo_ui('tematica_faq_titulo')) ?></h2>
        <div class="mb-10">
            <?php foreach ($preguntas as $f): ?>
            <details class="bg-slate-800 border border-slate-700 rounded-lg p-4 mb-2">
                <summary class="font-semibold text-slate-100 cursor-pointer"><?= e((string) ($f['q'] ?? '')) ?></summary>
                <p class="text-sm text-slate-300 mt-2 leading-relaxed"><?= e((string) ($f['a'] ?? '')) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e((string) ($SEO['tematica_relacionadas'] ?? 'Temáticas parecidas')) ?></h2>
        <ul class="flex flex-wrap gap-2 mb-6">
            <?php
            $relacionadas = 0;
            foreach (categorias() as $cat) {
                if ($cat['id'] !== $tm['categoria_id']) {
                    continue;
                }
                foreach ($cat['tematicas'] as $otra) {
                    if ($otra['id'] === $id) {
                        continue;
                    }
                    $relacionadas++;
                    if ($relacionadas > 8) {
                        break 2;
                    }
                    ?>
            <li>
                <a href="/tematica/<?= e($otra['id']) ?>" class="inline-block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-full px-3 py-1.5 text-sm text-slate-200"><?= e($otra['emoji'] . ' ' . nombre_tematica($otra['id'])) ?></a>
            </li>
                    <?php
                }
            }
            ?>
        </ul>
        <a href="/categoria/<?= e($tm['categoria_id']) ?>" class="inline-block text-amber-400 hover:text-amber-300 text-sm font-semibold mb-8"><?= e('Ver todas las temáticas de ' . $categoria) ?> →</a>

        <h2 class="text-xl font-bold text-slate-100 mb-4">También te puede interesar</h2>
        <ul class="flex flex-wrap gap-2 mb-8">
            <?php
            $otras = 0;
            foreach (categorias() as $cat) {
                if ($cat['id'] === $tm['categoria_id']) {
                    continue;
                }
                $otra = $cat['tematicas'][0] ?? null;
                if ($otra === null) {
                    continue;
                }
                ?>
            <li><a href="/tematica/<?= e($otra['id']) ?>" class="inline-block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-full px-3 py-1.5 text-sm text-slate-200"><?= e($otra['emoji'] . ' ' . nombre_tematica($otra['id'])) ?></a></li>
                <?php
                if (++$otras >= 3) {
                    break;
                }
            }
            ?>
        </ul>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(seo_ui('guia_enlaces_titulo')) ?></h2>
        <ul class="space-y-2 mb-6">
            <li><a class="text-sm text-slate-200 hover:text-amber-400" href="/guia/como-ganar-draft-20">Cómo ganar en Draft 20: tácticas de subasta →</a></li>
            <li><a class="text-sm text-slate-200 hover:text-amber-400" href="/guia/mejores-tematicas">Las 10 temáticas más divertidas de Draft 20 →</a></li>
        </ul>
    </main>
<?php
// Crear la sala desde la propia ficha (1 clic): guarda sesión y va al juego.
$jsJugar = '(function () {'
    . ' var b = document.getElementById("btnJugarTema"); if (!b) return;'
    . ' var tema = b.getAttribute("data-tematica");'
    . ' b.addEventListener("click", function () {'
    . '  b.disabled = true;'
    . '  var nombre = ""; var mv = false; try { nombre = localStorage.getItem("draft20_nombre") || ""; mv = localStorage.getItem("draft20_mostrar_valores") === "1"; } catch (e) {}'
    . '  fetch("/api/crear_sala.php", { method: "POST", headers: { "Content-Type": "application/json" },'
    . '   credentials: "same-origin",'
    . '   body: JSON.stringify({ tematica: tema, nombre: nombre, mostrar_valores: mv }) })'
    . '  .then(function (r) { return r.json(); })'
    . '  .then(function (r) {'
    . '   if (!r || !r.ok) { window.location.href = "/?tematica=" + encodeURIComponent(tema) + "#app"; return; }'
    . '   try { localStorage.setItem("draft20_" + r.codigo, JSON.stringify({ jugadorId: r.jugador_id, jugadorNombre: nombre || "Jugador 1", ts: Date.now() })); } catch (e) {}'
    . '   window.location.href = "/juego.php?codigo=" + encodeURIComponent(r.codigo);'
    . '  })'
    . '  .catch(function () { window.location.href = "/?tematica=" + encodeURIComponent(tema) + "#app"; });'
    . ' });'
    . '})();';

pagina_foot(['inline' => $jsJugar]);
