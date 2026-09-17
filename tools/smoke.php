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
