<?php
/**
 * Draft 20 — Smoke E2E: crear → unirse → pujar → bajar → finalizar.
 *
 * Arranca el servidor embebido de PHP y recorre una partida completa
 * comprobando los códigos HTTP y el estado final. Sirve para CI.
 *
 * Uso: php tools/smoke.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$root = dirname(__DIR__);
$salasDir = $root . '/api/salas/';
$port = 9400 + (getmypid() % 300);
$logOut = sys_get_temp_dir() . '/draft20_smoke_' . $port . '.out';
$logErr = sys_get_temp_dir() . '/draft20_smoke_' . $port . '.err';

$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', $logOut, 'w'], 2 => ['file', $logErr, 'w']],
    $pipes
);

function kill_server($proc): void
{
    if (!is_resource($proc)) {
        return;
    }
    $st = @proc_get_status($proc);
    if (is_array($st) && $st['running'] && stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        @exec('taskkill /F /T /PID ' . $st['pid'] . ' 2>NUL');
    }
    @proc_terminate($proc);
    @proc_close($proc);
}

function http_req(string $method, string $url, ?array $body = null): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\nUser-Agent: Draft20Smoke/1.0\r\n",
        'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    $raw = (string) @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw, 'headers' => $http_response_header ?? []];
}

$ok = 0; $fail = 0;
function check(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "OK   $msg\n"; return; }
    $fail++;
    echo "FAIL $msg\n";
}

$base = 'http://127.0.0.1:' . $port;
$listo = false;
for ($i = 0; $i < 40; $i++) {
    usleep(250000);
    if (http_req('GET', $base . '/index.php')['code'] === 200) { $listo = true; break; }
}
if (!$listo) {
    echo "FAIL: el servidor no arrancó\n";
    kill_server($proc);
    exit(1);
}

$codigos = [];

try {
    // 0) i18n válido (si el JSON se rompe, las páginas degradan en silencio)
    $lang = json_decode((string) @file_get_contents($root . '/lang/es.json'), true);
    check(is_array($lang) && count($lang['items'] ?? []) > 1000, 'lang/es.json válido con items');

    // 0b) Iconos Fluent Emoji servidos (MIT)
    $icono = http_req('GET', $base . '/img/emoji/1f410.svg');
    check($icono['code'] === 200, 'icono Fluent SVG servido (200)');

    // 1) Crear
    $r = http_req('POST', $base . '/api/crear_sala.php', ['tematica' => 'hamburguesa', 'nombre' => 'Smoke1']);
    check($r['code'] === 200 && !empty($r['json']['codigo']), 'crear_sala → 200');
    $cod = (string) ($r['json']['codigo'] ?? '');
    $j1 = (string) ($r['json']['jugador_id'] ?? '');
    $codigos[] = $cod;

    // 2) Estado sin jugador_id: sin datos internos
    $e = http_req('GET', $base . '/api/estado.php?codigo=' . $cod);
    check($e['code'] === 200, 'estado (lobby) → 200');
    check((int) ($e['json']['sala']['total_items'] ?? 0) === 8, 'total_items = 8');
    check(!isset($e['json']['sala']['items_mezclados']), 'sin items_mezclados');
    check(!isset($e['json']['sala']['jugadores'][0]['id']), 'sin ids de jugadores');

    // 3) Unirse
    $r = http_req('POST', $base . '/api/unirse_sala.php', ['codigo' => $cod, 'nombre' => 'Smoke2']);
    check($r['code'] === 200 && !empty($r['json']['jugador_id']), 'unirse_sala → 200');
    $j2 = (string) ($r['json']['jugador_id'] ?? '');
    check(($r['json']['sala']['mi_slot'] ?? null) === 1, 'mi_slot = 1 para J2');

    // 4) Partida completa: en cada ronda puja el que abre y el otro se baja
    $sala = $r['json']['sala'];
    $vueltas = 0;
    while (($sala['estado'] ?? '') === 'jugando' && $vueltas++ < 40) {
        $turno = (int) ($sala['item_actual']['turno_de'] ?? 0);
        $actor = $turno === 0 ? $j1 : $j2;
        $otro  = $turno === 0 ? $j2 : $j1;

        $p = http_req('POST', $base . '/api/accion.php', ['codigo' => $cod, 'jugador_id' => $actor, 'accion' => 'pujar']);
        check($p['code'] === 200, 'pujar (ronda ' . ($vueltas) . ') → 200');
        if ($p['code'] !== 200) { break; }
        $sala = $p['json']['sala'];

        if (($sala['estado'] ?? '') !== 'jugando') { break; }

        $b = http_req('POST', $base . '/api/accion.php', ['codigo' => $cod, 'jugador_id' => $otro, 'accion' => 'bajar']);
        check($b['code'] === 200, 'bajar (ronda ' . ($vueltas) . ') → 200');
        if ($b['code'] !== 200) { break; }
        $sala = $b['json']['sala'];
    }

    // 5) Final
    check(($sala['estado'] ?? '') === 'finalizada', 'partida finalizada');
    $n0 = count($sala['jugadores'][0]['items_ganados'] ?? []);
    $n1 = count($sala['jugadores'][1]['items_ganados'] ?? []);
    check($n0 + $n1 === 8, '8 ítems repartidos (' . $n0 . '+' . $n1 . ')');
    check($n0 === 4 && $n1 === 4, 'reparto 4-4');
    check(!isset($sala['jugadores'][0]['id']) && !isset($sala['jugadores'][1]['id']), 'respuesta final sin ids');

    // 6) Revancha real: el proponente crea la sala nueva y prueba su id nuevo
    $rn = http_req('POST', $base . '/api/crear_sala.php', ['tematica' => 'pizza', 'nombre' => 'Smoke1']);
    check($rn['code'] === 200, 'crear sala de revancha → 200');
    $codNuevo = (string) ($rn['json']['codigo'] ?? '');
    $codigos[] = $codNuevo;
    $rev = http_req('POST', $base . '/api/revancha.php', [
        'codigo' => $cod,
        'jugador_id' => $j1,
        'accion' => 'proponer',
        'codigo_nuevo' => $codNuevo,
        'jugador_id_nuevo' => (string) ($rn['json']['jugador_id'] ?? ''),
        'tematica' => 'pizza',
    ]);
    check($rev['code'] === 200 && (($rev['json']['sala']['revancha']['por'] ?? null) === 0), 'proponer revancha → 200');
    $revSinId = http_req('POST', $base . '/api/revancha.php', [
        'codigo' => $cod,
        'jugador_id' => $j1,
        'accion' => 'proponer',
        'codigo_nuevo' => $codNuevo,
        'tematica' => 'pizza',
    ]);
    check($revSinId['code'] === 400, 'proponer sin jugador_id_nuevo → 400');
    $acep = http_req('POST', $base . '/api/unirse_sala.php', ['codigo' => $codNuevo, 'nombre' => 'Smoke2']);
    check($acep['code'] === 200 && (($acep['json']['sala']['estado'] ?? '') === 'jugando'), 'aceptar revancha → 200');

    // 7) Bot: solo el creador de la sala puede sentarlo
    $rb = http_req('POST', $base . '/api/crear_sala.php', ['tematica' => 'futbol', 'nombre' => 'SmokeBot']);
    $codB = (string) ($rb['json']['codigo'] ?? '');
    $codigos[] = $codB;
    $j1b = (string) ($rb['json']['jugador_id'] ?? '');
    $mal = http_req('POST', $base . '/api/unirse_sala.php', ['codigo' => $codB, 'nombre' => 'Intruso', 'bot' => true]);
    check($mal['code'] === 403, 'bot sin creador_id → 403');
    $bien = http_req('POST', $base . '/api/unirse_sala.php', ['codigo' => $codB, 'nombre' => 'Bot', 'bot' => true, 'creador_id' => $j1b]);
    check($bien['code'] === 200 && (($bien['json']['sala']['bot_slot'] ?? null) === 1), 'bot con creador_id → 200');

    // 8) Cap: si el jugador en turno tiene 4 ítems, se auto-regala el actual;
    //    las acciones fuera de turno deben devolver 409 (no resolverlo en silencio).
    $rc = http_req('POST', $base . '/api/crear_sala.php', ['tematica' => 'hamburguesa', 'nombre' => 'Cap1']);
    $codC = (string) ($rc['json']['codigo'] ?? '');
    $codigos[] = $codC;
    $j1c = (string) ($rc['json']['jugador_id'] ?? '');
    $rc2 = http_req('POST', $base . '/api/unirse_sala.php', ['codigo' => $codC, 'nombre' => 'Cap2']);
    $j2c = (string) ($rc2['json']['jugador_id'] ?? '');
    check(($rc2['json']['sala']['item_actual']['turno_de'] ?? null) === 0, 'cap: el ítem inicial es de J1');

    $fuera = http_req('POST', $base . '/api/accion.php', ['codigo' => $codC, 'jugador_id' => $j2c, 'accion' => 'pujar', 'incremento' => 1]);
    check($fuera['code'] === 409, 'accion fuera de turno → 409');

    $pathC = $salasDir . $codC . '.json';
    $rawC = json_decode((string) file_get_contents($pathC), true);
    $itemCapId = $rawC['item_actual']['id'];
    $rawC['jugadores'][0]['items_ganados'] = [];
    for ($k = 0; $k < 4; $k++) {
        $rawC['jugadores'][0]['items_ganados'][] = ['id' => 'fake' . $k, 'emoji' => '🍔', 'valor' => 5, 'precio' => 3];
    }
    file_put_contents($pathC, json_encode($rawC, JSON_UNESCAPED_UNICODE));

    $fuera2 = http_req('POST', $base . '/api/accion.php', ['codigo' => $codC, 'jugador_id' => $j2c, 'accion' => 'pujar', 'incremento' => 1]);
    check($fuera2['code'] === 409, 'fuera de turno con rival capped → 409 (no resuelve el cap)');

    $cap = http_req('POST', $base . '/api/accion.php', ['codigo' => $codC, 'jugador_id' => $j1c, 'accion' => 'pujar', 'incremento' => 1]);
    $capIds = array_column($cap['json']['sala']['jugadores'][1]['items_ganados'] ?? [], 'id');
    check($cap['code'] === 200 && in_array($itemCapId, $capIds, true), 'capped en turno: el ítem pasa al rival');

    // 9) Deadlock sin dinero: pasar_deadlock abre decisión para el rival.
    $rd = http_req('POST', $base . '/api/crear_sala.php', ['tematica' => 'hamburguesa', 'nombre' => 'Dead1']);
    $codD = (string) ($rd['json']['codigo'] ?? '');
    $codigos[] = $codD;
    $j1d = (string) ($rd['json']['jugador_id'] ?? '');
    $rd2 = http_req('POST', $base . '/api/unirse_sala.php', ['codigo' => $codD, 'nombre' => 'Dead2']);
    $j2d = (string) ($rd2['json']['jugador_id'] ?? '');

    $pathD = $salasDir . $codD . '.json';
    $rawD = json_decode((string) file_get_contents($pathD), true);
    $rawD['jugadores'][0]['dinero'] = 0;
    $rawD['jugadores'][1]['dinero'] = 20;
    file_put_contents($pathD, json_encode($rawD, JSON_UNESCAPED_UNICODE));

    $pas = http_req('POST', $base . '/api/accion.php', ['codigo' => $codD, 'jugador_id' => $j1d, 'accion' => 'pasar_deadlock']);
    check($pas['code'] === 200 && (($pas['json']['sala']['decision_pendiente']['para'] ?? null) === 1), 'deadlock: decisión pendiente para J2');
    $asig = http_req('POST', $base . '/api/accion.php', ['codigo' => $codD, 'jugador_id' => $j2d, 'accion' => 'asignar_rival', 'destino' => 1, 'precio' => 1]);
    check($asig['code'] === 200 && (($asig['json']['sala']['jugadores'][1]['dinero'] ?? null) === 19), 'deadlock: asignar_rival cobra 1 → 19');
    check(($asig['json']['sala']['decision_pendiente'] ?? null) === null, 'deadlock: decisión limpia');

    // 10) CSP con nonce: el HTML y los scripts inline deben ir firmados.
    $rcsp = http_req('GET', $base . '/index.php');
    $hcsp = implode("\n", $rcsp['headers']);
    check($rcsp['code'] === 200, 'home 200 para comprobar la CSP');
    $mCsp = [];
    check((bool) preg_match('/Content-Security-Policy:.*script-src \'self\' \'nonce-([A-Za-z0-9+\/=]+)\'/i', $hcsp, $mCsp), 'CSP: script-src con nonce');
    $nonce = $mCsp[1] ?? '';
    check($nonce !== '' && substr_count((string) $rcsp['raw'], 'nonce="' . $nonce . '"') >= 1, 'home: los scripts inline llevan el nonce');
    $rcsp2 = http_req('GET', $base . '/como-jugar');
    $hcsp2 = implode("\n", $rcsp2['headers']);
    $mCsp2 = [];
    check((bool) preg_match('/script-src \'self\' \'nonce-([A-Za-z0-9+\/=]+)\'/', $hcsp2, $mCsp2), 'como-jugar: CSP con nonce');
    $n2 = $mCsp2[1] ?? '';
    check(strpos((string) $rcsp2['raw'], 'application/ld+json" nonce="' . $n2 . '"') !== false, 'JSON-LD firmado con el nonce');
    check(strpos($hcsp, "object-src 'none'") !== false, 'CSP: object-src none');
    // El hosting puede reescribir la cabecera HTTP: la política también va en <meta>.
    $mMeta = [];
    check((bool) preg_match('/<meta http-equiv="Content-Security-Policy" content="([^"]+)"/', (string) $rcsp['raw'], $mMeta), 'meta CSP presente');
    $metaCsp = $mMeta[1] ?? '';
    check($nonce !== '' && $metaCsp !== '' && strpos($metaCsp, $nonce) !== false, 'el meta CSP usa el mismo nonce');
    check(strpos($metaCsp, 'object-src') !== false && strpos($metaCsp, 'default-src') !== false, 'meta CSP completa');

    // 10b) SEO: entidad de sitio, llms.txt y lastmod real del sitemap.
    $rSeoHome = http_req('GET', $base . '/index.php');
    check(strpos((string) $rSeoHome['raw'], '"@type":"Organization"') !== false, 'home: JSON-LD Organization');
    check(strpos((string) $rSeoHome['raw'], '"@type":"WebSite"') !== false, 'home: JSON-LD WebSite');
    $rSeoGuia = http_req('GET', $base . '/guia.php?slug=como-ganar-draft-20');
    check($rSeoGuia['code'] === 200
        && strpos((string) $rSeoGuia['raw'], '"publisher":{"@id":"https://draft20.es/#organizacion"}') !== false,
        'guía: Article con publisher de organización');
    $rLlms = http_req('GET', $base . '/llms.txt');
    check($rLlms['code'] === 200 && strpos((string) $rLlms['raw'], '# Draft 20') === 0, 'llms.txt servido con H1');
    check(strpos((string) $rSeoHome['raw'], 'adsbygoogle.js?client=ca-pub-9504493922636861') !== false, 'home: script de AdSense en el head');
    check(strpos($hcsp, 'pagead2.googlesyndication.com') !== false, 'CSP: dominios de AdSense permitidos');
    $rAds = http_req('GET', $base . '/ads.txt');
    check($rAds['code'] === 200 && strpos((string) $rAds['raw'], 'pub-9504493922636861') !== false, 'ads.txt servido con el publisher');
    check(strpos((string) $rSeoHome['raw'], 'data-ad-slot="1410891766"') !== false, 'home: bloque manual del lobby');
    $rJuegoAds = http_req('GET', $base . '/juego.php?codigo=ABC12');
    check($rJuegoAds['code'] === 200, 'juego: shell servido (200)');
    check(strpos((string) $rJuegoAds['raw'], 'adsbygoogle.js?client=ca-pub-9504493922636861') !== false, 'juego: loader de AdSense');
    check(strpos((string) $rJuegoAds['raw'], '"banner":"6658157047"') !== false
        && strpos((string) $rJuegoAds['raw'], '"final":"4631951476"') !== false,
        'juego: slots banner/final en window.__ADS');
    foreach (['/tematica.php?id=futbol', '/guia.php?slug=draft-de-20-monedas', '/juegos_de_subasta.php', '/como_jugar.php'] as $rutaCont) {
        $rCont = http_req('GET', $base . $rutaCont);
        check($rCont['code'] === 200 && strpos((string) $rCont['raw'], 'data-ad-slot="1818472919"') !== false,
            'contenido ' . $rutaCont . ': bloque in-article');
        check(substr_count((string) $rCont['raw'], '(window.adsbygoogle = window.adsbygoogle || []).push({});') === 1,
            'contenido ' . $rutaCont . ': push único');
    }
    $rSitemap = http_req('GET', $base . '/sitemap.php');
    $mSitemap = [];
    check($rSitemap['code'] === 200
        && (bool) preg_match('#<loc>https://draft20\.es/privacidad</loc>\s*<lastmod>([^<]+)</lastmod>#', (string) $rSitemap['raw'], $mSitemap),
        'sitemap: /privacidad con lastmod');
    check(($mSitemap[1] ?? '') === date('Y-m-d', (int) filemtime($root . '/privacidad.php')), 'sitemap: lastmod real de /privacidad');

    // 11) Contador anónimo de eventos + beacon en las páginas SEO.
    $rEv = http_req('POST', $base . '/api/evento.php', ['evento' => 'page:home']);
    check($rEv['code'] === 204, 'evento válido → 204');
    $rEv2 = http_req('POST', $base . '/api/evento.php', ['evento' => 'evento:inventado']);
    check($rEv2['code'] === 400, 'evento fuera de la lista blanca → 400');
    $rEv3 = http_req('GET', $base . '/api/evento.php');
    check($rEv3['code'] === 405, 'evento por GET → 405');
    $statsFile = $root . '/api/datos/stats/' . date('Y-m-d') . '.json';
    $nAntes = 0;
    if (is_file($statsFile)) {
        $prev = json_decode((string) file_get_contents($statsFile), true);
        $nAntes = (int) (is_array($prev) ? ($prev['page:home'] ?? 0) : 0);
    }
    http_req('POST', $base . '/api/evento.php', ['evento' => 'page:home']);
    $nDespues = 0;
    if (is_file($statsFile)) {
        $post = json_decode((string) file_get_contents($statsFile), true);
        $nDespues = (int) (is_array($post) ? ($post['page:home'] ?? 0) : 0);
    }
    check($nDespues === $nAntes + 1, 'el contador diario incrementa');
    $rHomeEv = http_req('GET', $base . '/index.php');
    check(strpos((string) $rHomeEv['raw'], '/api/evento.php') !== false, 'las páginas SEO incluyen el beacon');

    // 12) Partida rápida: modo ⭐, listado de cola y preferencia de emparejamiento.
    // La cola se vacía antes para no depender de restos de otras ejecuciones.
    $colaPath = $root . '/api/datos/cola_rapida.json';
    @unlink($colaPath);

    $rq1 = http_req('POST', $base . '/api/partida_rapida.php', ['nombre' => 'RapidaA', 'mostrar_valores' => false]);
    check($rq1['code'] === 200 && ($rq1['json']['rol'] ?? '') === 'creador' && ($rq1['json']['mostrar_valores'] ?? null) === false,
        'rápida sin ⭐ → creador con valores ocultos');
    $codA = (string) ($rq1['json']['codigo'] ?? '');
    $codigos[] = $codA;

    // La cola real solo tiene una entrada (el segundo que busca empareja con el
    // primero), así que la segunda se añade a mano con una sala manual en ⭐.
    $rm = http_req('POST', $base . '/api/crear_sala.php', ['tematica' => 'pizza', 'nombre' => 'RapidaB', 'mostrar_valores' => true]);
    $codB = (string) ($rm['json']['codigo'] ?? '');
    $codigos[] = $codB;
    $cola = json_decode((string) @file_get_contents($colaPath), true);
    if (!is_array($cola)) {
        $cola = ['espera' => []];
    }
    $cola['espera'][] = ['codigo' => $codB, 'jugador_id' => (string) ($rm['json']['jugador_id'] ?? ''), 'creado_en' => time()];
    file_put_contents($colaPath, json_encode($cola, JSON_UNESCAPED_UNICODE));

    $rqCola = http_req('POST', $base . '/api/partida_rapida.php', ['accion' => 'cola']);
    $lista = $rqCola['json']['espera'] ?? null;
    check($rqCola['code'] === 200 && is_array($lista) && count($lista) === 2, 'cola: lista las dos entradas vivas');
    $primera = is_array($lista) ? ($lista[0] ?? []) : [];
    check(isset($primera['nombre'], $primera['visible']) && !isset($primera['codigo']) && !isset($primera['jugador_id']),
        'cola: saneada (nombre/⭐, sin código ni id)');
    check(($lista[1]['visible'] ?? null) === true, 'cola: refleja el modo ⭐ de cada entrada');
    $rqColaYo = http_req('POST', $base . '/api/partida_rapida.php', ['accion' => 'cola', 'jugador_id' => (string) ($rq1['json']['jugador_id'] ?? '')]);
    check(count($rqColaYo['json']['espera'] ?? []) === 1, 'cola: el listado excluye tu propia entrada');

    // Preferencia/estricto: buscar con ⭐ debe emparejar con la sala B (aunque A espere antes).
    $rqMatch = http_req('POST', $base . '/api/partida_rapida.php', ['nombre' => 'RapidaC', 'mostrar_valores' => true]);
    check($rqMatch['code'] === 200 && ($rqMatch['json']['codigo'] ?? '') === $codB && ($rqMatch['json']['mostrar_valores'] ?? null) === true,
        'emparejamiento: mismo modo de ⭐');
    check(($rqMatch['json']['rol'] ?? '') === 'rival', 'emparejamiento: entra como rival');

    // Estricto: ahora solo queda A (sin ⭐); buscar CON ⭐ no debe emparejar con ella.
    $rqEstricto = http_req('POST', $base . '/api/partida_rapida.php', ['nombre' => 'RapidaE', 'mostrar_valores' => true]);
    check($rqEstricto['code'] === 200 && ($rqEstricto['json']['rol'] ?? '') === 'creador'
        && ($rqEstricto['json']['codigo'] ?? '') !== $codA && ($rqEstricto['json']['mostrar_valores'] ?? null) === true,
        'emparejamiento estricto: con otro modo se crea sala nueva');
    $codE = (string) ($rqEstricto['json']['codigo'] ?? '');
    $codigos[] = $codE;
    check(count(http_req('POST', $base . '/api/partida_rapida.php', ['accion' => 'cola'])['json']['espera'] ?? []) === 2,
        'cola: ahora espera una entrada por modo');

    // Y quien llega sin ⭐ empareja con la que espera sin ⭐.
    $rqMatch2 = http_req('POST', $base . '/api/partida_rapida.php', ['nombre' => 'RapidaD', 'mostrar_valores' => false]);
    check(($rqMatch2['json']['codigo'] ?? '') === $codA, 'emparejamiento: el que llega sin ⭐ cae en la sala sin ⭐');

    // 13) Salir de una sala privada mientras se espera (botón del lobby).
    $rpriv = http_req('POST', $base . '/api/crear_sala.php', ['tematica' => 'pizza', 'nombre' => 'Priv1']);
    $codPriv = (string) ($rpriv['json']['codigo'] ?? '');
    $codigos[] = $codPriv;
    $j1Priv = (string) ($rpriv['json']['jugador_id'] ?? '');
    $rcancel = http_req('POST', $base . '/api/partida_rapida.php', ['accion' => 'cancelar', 'codigo' => $codPriv, 'jugador_id' => $j1Priv]);
    check($rcancel['code'] === 200 && ($rcancel['json']['ok'] ?? false) === true, 'salir de sala privada: cancelar → 200');
    $rprivEstado = http_req('GET', $base . '/api/estado.php?codigo=' . $codPriv . '&jugador_id=' . $j1Priv);
    check($rprivEstado['code'] === 404, 'salir de sala privada: la sala se borra');
} finally {
    foreach ($codigos as $c) {
        if ($c !== '') {
            @unlink($salasDir . $c . '.json');
        }
    }
    kill_server($proc);
}

echo "\n=== SMOKE E2E: $ok OK / $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
