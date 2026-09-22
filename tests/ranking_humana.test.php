<?php
/**
 * Draft 20 — Test del registro anónimo de humanas (CLI, sin servidor).
 *
 * Uso:  php tests/ranking_humana.test.php
 *
 * Usa un fichero temporal como log: simula 3 finales reales (privada,
 * rápida y revancha con empate), 1 abandono (no debe loguearse), 1 sala
 * de bot (tampoco) y 1 doble llamada (idempotencia). Luego verifica el
 * ranking agregado sobre lo registrado.
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

// 6) Doble llamada → una sola línea (idempotencia).
registrar_partida_humana($s1, $log);

$lineas = array_values(array_filter(explode("\n", trim((string) @file_get_contents($log))), static fn(string $l): bool => $l !== ''));
check(count($lineas) === 4, '4 líneas en el log (3 humanas + 1 bot, sin abandono/doble)');
$rs = array_map(static fn(string $l): array => json_decode($l, true), $lineas);
check($rs[0]['tematica'] === 'pizza' && $rs[0]['resultado'] === 'j1' && $rs[0]['tipo'] === 'privada' && $rs[0]['visibles'] === 0, 'línea 1: pizza privada j1 oculta');
check($rs[1]['tematica'] === 'futbol' && $rs[1]['resultado'] === 'j2' && $rs[1]['tipo'] === 'rapida' && $rs[1]['visibles'] === 1, 'línea 2: futbol rápida j2 visible');
check($rs[2]['resultado'] === 'empate' && $rs[2]['tipo'] === 'revancha' && $rs[2]['rondas'] === 8 && $rs[2]['durSeg'] >= 290, 'línea 3: revancha empate con rondas y duración');
check($rs[3]['tipo'] === 'bot' && $rs[3]['tematica'] === 'pizza', 'línea 4: bot con distinción');
check(isset($rs[0]['ts']) && $rs[0]['ts'] > 0, 'línea con hora (ts)');

// 7) Agregado compartido CLI+HTTP.
$agg = ranking_humanas($log, 30);
check($agg['total'] === 4, 'agregado total 4');
check(($agg['porTematica']['pizza'] ?? 0) === 2 && ($agg['porTematica']['futbol'] ?? 0) === 1, 'agregado por temática');
check(($agg['porTipo']['bot'] ?? 0) === 1 && ($agg['porTipo']['privada'] ?? 0) === 1, 'agregado por tipo con bot');

@unlink($log);
echo "\n=== RANKING HUMANA: $ok OK / $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
