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
    : str_replace('{tema}', $nombre, t('ui.tematica.meta_desc'));

$titulo = str_replace('{tema}', $nombre, t('ui.tematica.titulo'));

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
            ['@type' => 'ListItem', 'position' => 1, 'name' => (string) ($SEO['migas_inicio'] ?? 'Inicio'), 'item' => SITE_URL . url_lang('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $categoria, 'item' => SITE_URL . url_lang('/categoria/' . $tm['categoria_id'])],
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
        <nav class="text-xs text-slate-400 mb-4" aria-label="<?= e(t('ui.comun.migas')) ?>">
            <a class="hover:text-amber-400" href="<?= e(url_lang('/')) ?>"><?= e((string) ($SEO['migas_inicio'] ?? 'Inicio')) ?></a>
            <span class="mx-1">/</span>
            <a class="hover:text-amber-400" href="<?= e(url_lang('/categoria/' . $tm['categoria_id'])) ?>"><?= e($categoria) ?></a>
            <span class="mx-1">/</span>
            <span class="text-slate-300"><?= e($nombre) ?></span>
        </nav>

        <h1 class="text-3xl font-bold text-slate-100 mb-2 flex items-center gap-2 justify-center sm:justify-start">
            <?= emoji_icono((string) $tm['emoji'], 40, '') ?>
            <span><?= e($nombre) ?></span>
        </h1>
        <p class="text-slate-400 text-sm mb-1"><a class="hover:text-amber-400 inline-flex items-center gap-1.5" href="<?= e(url_lang('/categoria/' . $tm['categoria_id'])) ?>"><?= emoji_icono((string) $tm['categoria_emoji'], 20, '') ?> <?= e($categoria) ?></a></p>
        <?php if ($cont !== null): ?>
        <p class="text-slate-300 text-sm leading-relaxed mt-4 mb-6"><?= e((string) $cont['descripcion']) ?></p>
        <?php else: ?>
        <p class="text-slate-300 text-sm leading-relaxed mt-4 mb-6"><?= e(str_replace('{t}', $nombre, (string) ($SEO['tematica_intro'] ?? ''))) ?></p>
        <?php endif; ?>
        <?php $jsonTema = __DIR__ . '/tematicas/' . $id . '.json'; if (is_file($jsonTema)): ?>
        <p class="text-xs text-slate-400 mb-6"><?= e(t('ui.comun.actualizado')) ?>: <?= e(mes_anio(date('Y-m', (int) filemtime($jsonTema)))) ?></p>
        <?php endif; ?>
        <?php
        // Puesto en el ranking del mes en curso (datos reales anonimizados).
        // Sin datos no se pinta nada. Tope 1 MB como el teaser de la home.
        $puestoMes = null;
        $mesRankId = date('Y-m');
        $logRankFicha = __DIR__ . '/api/datos/partidas_humanas.jsonl';
        if (is_file($logRankFicha) && filesize($logRankFicha) < 1048576) {
            require_once __DIR__ . '/api/ranking_lib.php';
            $aggFicha = ranking_humanas($logRankFicha, 0);
            $topFicha = array_keys((array) ($aggFicha['porMes'][$mesRankId]['top'] ?? []));
            $posFicha = array_search($id, $topFicha, true);
            if ($posFicha !== false) $puestoMes = $posFicha + 1;
        }
        ?>
        <?php if ($puestoMes !== null): ?>
        <p class="text-sm text-slate-200 mb-6">🏅 <?= e(t('ui.tematica.puesto_mes', ['n' => $puestoMes, 'mes' => nombre_mes(substr($mesRankId, 5, 2))])) ?> <a class="text-amber-400 hover:text-amber-300 font-semibold" href="<?= e(url_lang('/ranking')) ?>"><?= e(t('ui.tematica.ver_ranking')) ?> →</a></p>
        <?php endif; ?>

        <div class="flex flex-wrap gap-2 mb-2">
            <button id="btnJugarTema" type="button" data-tematica="<?= e($id) ?>" class="inline-block bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap disabled:opacity-60"><?= e((string) ($SEO['tematica_cta'] ?? 'Jugar')) ?></button>
            <button id="btnBotTema" type="button" data-tematica="<?= e($id) ?>" class="inline-block bg-slate-700 hover:bg-slate-600 text-slate-100 font-bold py-3 px-6 rounded-lg btn-tap disabled:opacity-60">🤖 <?= e(t('ui.tematica.jugar_bot')) ?></button>
        </div>
        <div class="flex flex-wrap items-center gap-2 mb-2">
            <span class="text-xs text-slate-400"><?= e(t('ui.tematica.dificultad_bot')) ?>:</span>
            <div id="difBotTema" class="flex flex-wrap gap-2">
                <?php foreach (['facil', 'normal', 'dificil', 'extremo'] as $dif): ?>
                <button type="button" data-dif="<?= e($dif) ?>" class="px-3 py-1.5 rounded-full text-xs border btn-tap bg-slate-700 text-slate-200 border-slate-600"><?= e((string) ($LANG['ui']['lobby']['bot_' . $dif] ?? $dif)) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="text-xs text-slate-400 mb-8"><a class="hover:text-amber-400" href="<?= e(url_lang('/?tematica=' . rawurlencode($id) . '#app')) ?>"><?= e(t('ui.tematica.elige_lobby')) ?></a></p>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(str_replace('{t}', $nombre, (string) ($SEO['tematica_items_titulo'] ?? 'Ítems de {t}'))) ?></h2>
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-10">
            <?php foreach ($items as $it): ?>
            <li class="flex items-center gap-3 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2">
                <span class="w-8 flex justify-center flex-shrink-0"><?= emoji_icono((string) $it['emoji'], 28, (string) ($LANG['items'][$it['id']] ?? $it['id'])) ?></span>
                <span class="text-sm text-slate-100"><?= e((string) ($LANG['items'][$it['id']] ?? $it['id'])) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php $tacticaTema = is_array($cont) ? (string) ($cont['tactica'] ?? '') : ''; ?>
        <?php if ($tacticaTema !== ''): ?>
        <h2 class="text-xl font-bold text-slate-100 mb-3"><?= e(t('ui.tematica.como_jugarla', ['tema' => $nombre])) ?></h2>
        <p class="text-sm text-slate-300 leading-relaxed mb-10"><?= e($tacticaTema) ?></p>
        <?php endif; ?>

        <?= ads_slot() ?>

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
                <a href="<?= e(url_lang('/tematica/' . $otra['id'])) ?>" class="inline-block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-full px-3 py-1.5 text-sm text-slate-200"><?= e($otra['emoji'] . ' ' . nombre_tematica($otra['id'])) ?></a>
            </li>
                    <?php
                }
            }
            ?>
        </ul>
        <a href="<?= e(url_lang('/categoria/' . $tm['categoria_id'])) ?>" class="inline-block text-amber-400 hover:text-amber-300 text-sm font-semibold mb-8"><?= e(t('ui.tematica.ver_todas', ['categoria' => $categoria])) ?> →</a>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(t('ui.tematica.tambien')) ?></h2>
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
            <li><a href="<?= e(url_lang('/tematica/' . $otra['id'])) ?>" class="inline-block bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-full px-3 py-1.5 text-sm text-slate-200"><?= e($otra['emoji'] . ' ' . nombre_tematica($otra['id'])) ?></a></li>
                <?php
                if (++$otras >= 3) {
                    break;
                }
            }
            ?>
        </ul>

        <h2 class="text-xl font-bold text-slate-100 mb-4"><?= e(seo_ui('guia_enlaces_titulo')) ?></h2>
        <?php
        $gGanar = guia_contenido('como-ganar-draft-20');
        $gMejores = guia_contenido('mejores-tematicas');
        ?>
        <ul class="space-y-2 mb-6">
            <li><a class="text-sm text-slate-200 hover:text-amber-400" href="<?= e(url_lang('/guia/como-ganar-draft-20')) ?>"><?= e((string) ($gGanar['titulo'] ?? 'Cómo ganar en Draft 20: tácticas de subasta')) ?> →</a></li>
            <li><a class="text-sm text-slate-200 hover:text-amber-400" href="<?= e(url_lang('/guia/mejores-tematicas')) ?>"><?= e((string) ($gMejores['titulo'] ?? 'Las 10 temáticas más divertidas de Draft 20')) ?> →</a></li>
        </ul>
    </main>
<?php
// Crear la sala desde la propia ficha (1 clic): guarda sesión y va al juego.
$jJugador1 = t('ui.juego.jugador_1');
$juegoBase = url_lang('/juego.php');
$jsJugar = '(function () {'
    . ' var b = document.getElementById("btnJugarTema"); if (!b) return;'
    . ' var tema = b.getAttribute("data-tematica");'
    . ' var jugador1 = ' . json_encode($jJugador1, JSON_UNESCAPED_UNICODE) . ';'
    . ' var lobbyUrl = ' . json_encode(url_lang('/?tematica=' . rawurlencode($id) . '#app'), JSON_UNESCAPED_UNICODE) . ';'
    . ' var juegoBase = ' . json_encode($juegoBase, JSON_UNESCAPED_UNICODE) . ';'
    . ' var juegoUrl = function (codigo) { return juegoBase + (juegoBase.indexOf("?") === -1 ? "?" : "&") + "codigo=" + encodeURIComponent(codigo); };'
    . ' b.addEventListener("click", function () {'
    . '  b.disabled = true;'
    . '  var nombre = ""; var mv = false; try { nombre = localStorage.getItem("draft20_nombre") || ""; mv = localStorage.getItem("draft20_mostrar_valores") === "1"; } catch (e) {}'
    . '  fetch("/api/crear_sala.php", { method: "POST", headers: { "Content-Type": "application/json" },'
    . '   credentials: "same-origin",'
    . '   body: JSON.stringify({ tematica: tema, nombre: nombre, mostrar_valores: mv }) })'
    . '  .then(function (r) { return r.json(); })'
    . '  .then(function (r) {'
    . '   if (!r || !r.ok) { window.location.href = lobbyUrl; return; }'
    . '   try { localStorage.setItem("draft20_" + r.codigo, JSON.stringify({ jugadorId: r.jugador_id, jugadorNombre: nombre || jugador1, ts: Date.now() })); } catch (e) {}'
    . '   window.location.href = juegoUrl(r.codigo);'
    . '  })'
    . '  .catch(function () { window.location.href = lobbyUrl; });'
    . ' });'
    . '})();';

// Partida contra el bot en 1 clic: crea la sala con la temática de la ficha,
// sienta al bot con la dificultad marcada y entra directo al juego.
// Réplica de iniciarPartidaBot() (js/app.core.js): la dificultad vive en
// localStorage y el juego la lee de ahí. Si algo falla, cae al lobby con
// la temática ya preseleccionada en el selector del bot.
$botNombres = array_values(array_filter(array_map('trim', explode('|', t('ui.lobby.bot_nombres'))), static fn(string $s): bool => $s !== ''));
$jsBot = '(function () {'
    . ' var b = document.getElementById("btnBotTema"); if (!b) return;'
    . ' var tema = b.getAttribute("data-tematica");'
    . ' var NOMBRES = ' . json_encode($botNombres, JSON_UNESCAPED_UNICODE) . ';'
    . ' var jugador1 = ' . json_encode($jJugador1, JSON_UNESCAPED_UNICODE) . ';'
    . ' var lobbyBotUrl = ' . json_encode(url_lang('/?tematica_bot=' . rawurlencode($id)), JSON_UNESCAPED_UNICODE) . ';'
    . ' var juegoBase = ' . json_encode($juegoBase, JSON_UNESCAPED_UNICODE) . ';'
    . ' var juegoUrl = function (codigo) { return juegoBase + (juegoBase.indexOf("?") === -1 ? "?" : "&") + "codigo=" + encodeURIComponent(codigo); };'
    . ' var difSel = "normal";'
    . ' try { var g = localStorage.getItem("draft20_bot_dificultad"); if (g === "facil" || g === "normal" || g === "dificil" || g === "extremo") difSel = g; } catch (e) {}'
    . ' function pintar() {'
    . '  var ps = document.querySelectorAll("#difBotTema [data-dif]");'
    . '  for (var i = 0; i < ps.length; i++) {'
    . '   var p = ps[i]; var on = p.getAttribute("data-dif") === difSel;'
    . '   p.className = "px-3 py-1.5 rounded-full text-xs border btn-tap " + (on ? "bg-amber-400 text-slate-900 border-amber-400 font-bold" : "bg-slate-700 text-slate-200 border-slate-600");'
    . '  }'
    . ' }'
    . ' var box = document.getElementById("difBotTema");'
    . ' if (box) { box.addEventListener("click", function (ev) {'
    . '  var t = ev.target; if (!t || !t.getAttribute) return;'
    . '  var d = t.getAttribute("data-dif"); if (!d) return;'
    . '  difSel = d; try { localStorage.setItem("draft20_bot_dificultad", d); } catch (e) {}'
    . '  pintar();'
    . ' }); }'
    . ' pintar();'
    . ' b.addEventListener("click", function () {'
    . '  b.disabled = true;'
    . '  var nombre = ""; var mv = false; try { nombre = localStorage.getItem("draft20_nombre") || ""; mv = localStorage.getItem("draft20_mostrar_valores") === "1"; } catch (e) {}'
    . '  var fallback = function () { b.disabled = false; window.location.href = lobbyBotUrl; };'
    . '  fetch("/api/crear_sala.php", { method: "POST", headers: { "Content-Type": "application/json" },'
    . '   credentials: "same-origin",'
    . '   body: JSON.stringify({ tematica: tema, nombre: nombre, mostrar_valores: mv }) })'
    . '  .then(function (r) { return r.json(); })'
    . '  .then(function (r) {'
    . '   if (!r || !r.ok) { fallback(); return; }'
    . '   var botNombre = "🤖 " + NOMBRES[Math.floor(Math.random() * NOMBRES.length)];'
    . '   fetch("/api/unirse_sala.php", { method: "POST", headers: { "Content-Type": "application/json" },'
    . '    credentials: "same-origin",'
    . '    body: JSON.stringify({ codigo: r.codigo, nombre: botNombre, bot: true, creador_id: r.jugador_id }) })'
    . '   .then(function (r2) { return r2.json(); })'
    . '   .then(function (r2) {'
    . '    if (!r2 || !r2.ok) { fallback(); return; }'
    . '    try { localStorage.setItem("draft20_bot_" + r.codigo, JSON.stringify({ jugadorId: r2.jugador_id, dificultad: difSel })); } catch (e) {}'
    . '    try { localStorage.setItem("draft20_" + r.codigo, JSON.stringify({ jugadorId: r.jugador_id, jugadorNombre: nombre || jugador1, ts: Date.now() })); } catch (e) {}'
    . '    window.location.href = juegoUrl(r.codigo);'
    . '   })'
    . '   .catch(fallback);'
    . '  })'
    . '  .catch(fallback);'
    . ' });'
    . '})();';

pagina_foot(['inline' => $jsJugar . $jsBot]);
