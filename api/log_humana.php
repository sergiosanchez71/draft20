<?php
/**
 * Draft 20 — Registro anónimo de partidas humanas terminadas (solo fichero).
 *
 * Sin top-level ejecutable: este fichero solo define
 * registrar_partida_humana() y se carga con require_once desde
 * api/accion.php (los 4 puntos donde una partida queda 'finalizada').
 *
 * Cada partida terminada (humanas y contra la CPU) añade UNA línea JSON a
 * api/datos/partidas_humanas.jsonl:
 *   {ts, tematica, visibles, tipo, rondas, resultado, durSeg}
 * donde tipo es privada|rapida|revancha|bot. Sin nombres, IDs de jugador,
 * códigos de sala ni IPs. Las partidas de bot también dejan su detalle
 * de calibración vía api/registrar_partida.php (propósito distinto).
 * Rotación como partidas.jsonl: al superar 5 MB se conserva la mitad
 * más reciente. Idempotente por sala (flag log_humana, que
 * reiniciar_sala() limpia al empezar la revancha).
 */
declare(strict_types=1);

if (!function_exists('registrar_partida_humana')) {
    /**
     * @param array<string,mixed> $sala Estado de la sala (por referencia: marca log_humana).
     * @param string $logPath Ruta del jsonl (solo para tests; por defecto la de producción).
     */
    function registrar_partida_humana(array &$sala, string $logPath = ''): void
    {
        if (!empty($sala['log_humana'])) return;
        if (($sala['estado'] ?? '') !== 'finalizada') return;

        $tematica = strtolower((string) ($sala['tematica'] ?? ''));
        $tematica = (string) preg_replace('/[^a-z0-9_]/', '', $tematica);
        if ($tematica === '' || strlen($tematica) > 40) $tematica = 'desconocida';

        // Ganador: suma de ⭐; empate a ⭐ lo rompe quien conserve más monedas.
        $v = [0, 0];
        $d = [0, 0];
        foreach ([0, 1] as $slot) {
            $jug = $sala['jugadores'][$slot] ?? [];
            foreach ((array) ($jug['items_ganados'] ?? []) as $it) {
                $v[$slot] += is_array($it) ? (int) ($it['valor'] ?? 0) : 0;
            }
            $d[$slot] = (int) ($jug['dinero'] ?? 0);
        }
        if ($v[0] !== $v[1]) {
            $resultado = $v[0] > $v[1] ? 'j1' : 'j2';
        } elseif ($d[0] !== $d[1]) {
            $resultado = $d[0] > $d[1] ? 'j1' : 'j2';
        } else {
            $resultado = 'empate';
        }

        $ahora = time();
        $registro = [
            'ts'        => $ahora,
            'tematica'  => $tematica,
            'visibles'  => !empty($sala['mostrar_valores']) ? 1 : 0,
            'tipo'      => ($sala['bot_slot'] ?? null) !== null
                ? 'bot'
                : (!empty($sala['rapida'])
                    ? 'rapida'
                    : (((int) ($sala['partida_n'] ?? 1)) > 1 ? 'revancha' : 'privada')),
            'rondas'    => max(0, (int) ($sala['ronda'] ?? 0)),
            'resultado' => $resultado,
            'durSeg'    => max(0, $ahora - (int) ($sala['creado_en'] ?? $ahora)),
        ];

        if ($logPath === '') {
            $dir = __DIR__ . '/datos/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $logPath = $dir . 'partidas_humanas.jsonl';
        }
        $fp = @fopen($logPath, 'c+b');
        if ($fp !== false) {
            if (flock($fp, LOCK_EX)) {
                fseek($fp, 0, SEEK_END);
                fwrite($fp, json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                clearstatcache(true, $logPath);
                $tam = ftell($fp);
                if ($tam !== false && $tam > 5 * 1024 * 1024) {
                    rewind($fp);
                    $todo = (string) stream_get_contents($fp);
                    $lineas = array_values(array_filter(
                        explode("\n", trim($todo)),
                        static fn(string $l): bool => $l !== ''
                    ));
                    $mitad = array_slice($lineas, intdiv(count($lineas), 2));
                    ftruncate($fp, 0);
                    rewind($fp);
                    if ($mitad !== []) fwrite($fp, implode("\n", $mitad) . "\n");
                }
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
        $sala['log_humana'] = true;
    }
}
