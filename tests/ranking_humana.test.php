<?php
/**
 * Draft 20 — Test del registro anónimo de humanas (CLI, sin servidor).
 *
 * Uso:  php tests/ranking_humana.test.php
 *
 * Usa un fichero temporal como log: simula finales reales (privada,
 * rápida, revancha humana con empate, bot y revancha contra bot),
 * 1 abandono (no debe loguearse) y 1 doble llamada (idempotencia).
 * Luego verifica el ranking agregado (incluido dias=0 y porMes).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

require_once __DIR__ . '/../api/log_humana.php';
require_once __DIR__ . '/../api/ranking_lib.php';

$ok = 0; $fail = 0;
function check(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "OK   $msg\n"; return; }
    $fail++;
    echo "FAIL $msg\n";
}

$log = sys_get_temp_dir() . '/humanas_test_' . getmypid() . '.jsonl';
@unlink($log);

function sala_base(array $ganados0, array $ganados1, array $extra = []): array
{
    return array_merge([
        'estado' => 'finalizada',
        'tematica' => 'pizza',
        'mostrar_valores' => false,
        'rapida' => false,
        'partida_n' => 1,
        'ronda' => 8,
        'creado_en' => time() - 300,
        'bot_slot' => null,
        'jugadores' => [
            ['dinero' => 5, 'items_ganados' => $ganados0],
            ['dinero' => 3, 'items_ganados' => $ganados1],
        ],
    ], $extra);
}

$it = static fn(int $v): array => ['id' => 'x', 'emoji' => '·', 'valor' => $v, 'precio' => 1];

// 1) Privada: J1 gana por ⭐ (10+9 vs 8+7).
$s1 = sala_base([$it(10), $it(9)], [$it(8), $it(7)]);
registrar_partida_humana($s1, $log);
check(!empty($s1['log_humana']), 'privada marca log_humana');

// 2) Rápida con ⭐ visibles: J2 gana.
$s2 = sala_base([$it(5)], [$it(9)], ['rapida' => true, 'mostrar_valores' => true, 'tematica' => 'futbol']);
registrar_partida_humana($s2, $log);

// 3) Revancha con empate a ⭐ y a monedas.
$s3 = sala_base([$it(6)], [$it(6)], ['partida_n' => 2, 'tematica' => 'anime', 'jugadores' => [
    ['dinero' => 4, 'items_ganados' => [$it(6)]],
    ['dinero' => 4, 'items_ganados' => [$it(6)]],
]]);
registrar_partida_humana($s3, $log);

// 4) Abandono (no finalizada) → no se registra.
$s4 = sala_base([$it(10)], [$it(1)], ['estado' => 'abandonada']);
registrar_partida_humana($s4, $log);
check(empty($s4['log_humana']), 'abandono no se registra');

// 5) Sala de bot → se registra con tipo=bot (distinción pedida).
$s5 = sala_base([$it(10)], [$it(1)], ['bot_slot' => 1]);
registrar_partida_humana($s5, $log);
check(!empty($s5['log_humana']), 'bot se registra con tipo=bot');

// 5b) Revancha contra bot → tipo=revancha_bot.
$s5b = sala_base([$it(10)], [$it(1)], ['bot_slot' => 1, 'partida_n' => 2, 'tematica' => 'tacos']);
registrar_partida_humana($s5b, $log);
check(!empty($s5b['log_humana']), 'revancha bot marca log_humana');

// 6) Doble llamada → una sola línea (idempotencia).
registrar_partida_humana($s1, $log);

$lineas = array_values(array_filter(explode("\n", trim((string) @file_get_contents($log))), static fn(string $l): bool => $l !== ''));
check(count($lineas) === 5, '5 líneas en el log (sin abandono/doble)');
$rs = array_map(static fn(string $l): array => json_decode($l, true), $lineas);
check($rs[0]['tematica'] === 'pizza' && $rs[0]['resultado'] === 'j1' && $rs[0]['tipo'] === 'privada' && $rs[0]['visibles'] === 0, 'línea 1: pizza privada j1 oculta');
check($rs[1]['tematica'] === 'futbol' && $rs[1]['resultado'] === 'j2' && $rs[1]['tipo'] === 'rapida' && $rs[1]['visibles'] === 1, 'línea 2: futbol rápida j2 visible');
check($rs[2]['resultado'] === 'empate' && $rs[2]['tipo'] === 'revancha_humano' && $rs[2]['rondas'] === 8 && $rs[2]['durSeg'] >= 290, 'línea 3: revancha humana con rondas y duración');
check($rs[3]['tipo'] === 'bot' && $rs[3]['tematica'] === 'pizza', 'línea 4: bot con distinción');
check($rs[4]['tipo'] === 'revancha_bot' && $rs[4]['tematica'] === 'tacos', 'línea 5: revancha contra bot');
check(isset($rs[0]['ts']) && $rs[0]['ts'] > 0, 'línea con hora (ts)');

// 7) Agregado compartido CLI+HTTP.
$agg = ranking_humanas($log, 30);
check($agg['total'] === 5, 'agregado total 5');
check(($agg['porTematica']['pizza'] ?? 0) === 2 && ($agg['porTematica']['futbol'] ?? 0) === 1, 'agregado por temática');
check(($agg['porTipo']['bot'] ?? 0) === 1 && ($agg['porTipo']['privada'] ?? 0) === 1, 'agregado por tipo con bot');
check(($agg['porTipo']['revancha_humano'] ?? 0) === 1 && ($agg['porTipo']['revancha_bot'] ?? 0) === 1, 'agregado distingue revanchas');

// 8) dias=0 (todo el histórico) + desglose por meses.
$aggAll = ranking_humanas($log, 0);
check($aggAll['total'] === 5, 'dias=0 trae todo');
$mesActual = date('Y-m');
check(isset($aggAll['porMes'][$mesActual]) && $aggAll['porMes'][$mesActual]['total'] === 5, 'porMes agrupa el mes en curso');
check(($aggAll['porMes'][$mesActual]['topTematica'] ?? '') === 'pizza', 'porMes top temática');

// 9) El top de cada mes usa su propio mes, no el global repetido.
$mesPasado = date('Y-m', strtotime('first day of last month'));
file_put_contents($log, json_encode(['ts' => strtotime($mesPasado . '-15 12:00:00'), 'tematica' => 'futbol', 'visibles' => 0, 'tipo' => 'privada', 'rondas' => 8, 'resultado' => 'j1', 'durSeg' => 300], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$aggMeses = ranking_humanas($log, 0);
check(($aggMeses['porMes'][$mesPasado]['top'][0] ?? '') === 'futbol', 'top mensual del mes pasado es futbol');
check(($aggMeses['porMes'][$mesActual]['top'][0] ?? '') === 'pizza', 'top mensual actual es pizza (no el global)');

// 10) Sala de Torre → no entra en el ranking (10 partidas sesgadas por subida).
$sT = sala_base([$it(10)], [$it(1)], ['torre' => true]);
registrar_partida_humana($sT, $log);
check(empty($sT['log_humana']), 'torre no se registra en el ranking');

// 11) Funnel de la Torre: global + por mes desde contadores diarios.
$dirTorre = sys_get_temp_dir() . '/torre_test_' . getmypid();
@mkdir($dirTorre, 0755, true);
$mesA = date('Y-m');
$mesP = date('Y-m', strtotime('first day of last month'));
file_put_contents($dirTorre . '/' . $mesA . '-10.json', json_encode(['torre:inicio' => 3, 'torre:piso_1' => 3, 'torre:piso_2' => 2, 'torre:victoria' => 1, 'page:home' => 99]));
file_put_contents($dirTorre . '/' . $mesP . '-20.json', json_encode(['torre:inicio' => 1, 'torre:piso_1' => 1]));
file_put_contents($dirTorre . '/basura.txt', 'no-json');
$fun = resumen_torre($dirTorre);
check($fun['global']['inicio'] === 4 && $fun['global']['victorias'] === 1, 'funnel global: inicios y victorias');
check($fun['global']['alcance'][1] === 4 && $fun['global']['alcance'][2] === 2 && $fun['global']['alcance'][3] === 0, 'funnel global: alcance por piso');
check(($fun['porMes'][$mesA]['alcance'][2] ?? -1) === 2 && ($fun['porMes'][$mesP]['alcance'][2] ?? -1) === 0, 'funnel por mes separado');
check(!isset($fun['porMes'][$mesA]['page:home']), 'funnel ignora otros eventos');
@unlink($dirTorre . '/' . $mesA . '-10.json');
@unlink($dirTorre . '/' . $mesP . '-20.json');
@unlink($dirTorre . '/basura.txt');
@rmdir($dirTorre);

@unlink($log);
echo "\n=== RANKING HUMANA: $ok OK / $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
