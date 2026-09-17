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
        'header' => "Content-Type: application/json\r\n",
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
    return ['code' => $code, 'json' => json_decode($raw, true)];
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
