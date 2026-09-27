<?php
/**
 * Draft 20 — Agregado del ranking de partidas (CLI + HTTP).
 *
 * Sin top-level ejecutable: solo define ranking_humanas(). La usan
 * tools/ranking.php (CLI) y api/ranking.php (JSON con clave).
 *
 * $dias <= 0 = todo el histórico sin filtro de fecha.
 */
declare(strict_types=1);

if (!function_exists('ranking_humanas')) {
    /**
     * Agrega api/datos/partidas_humanas.jsonl.
     *
     * @return array{total:int,porTematica:array<string,int>,porModo:array<string,int>,porTipo:array<string,int>,porResultado:array<string,int>,porFranja:array<string,int>,porMes:array<string,array{total:int,topTematica:string,top:array<int,string>,porTipo:array<string,int>}>}
     */
    function ranking_humanas(string $logPath, int $dias): array
    {
        $desde = $dias <= 0 ? 0 : time() - $dias * 86400;
        $out = [
            'total' => 0,
            'porTematica' => [],
            'porModo' => ['visible' => 0, 'oculto' => 0],
            'porTipo' => ['privada' => 0, 'rapida' => 0, 'revancha' => 0, 'revancha_humano' => 0, 'revancha_bot' => 0, 'bot' => 0],
            'porResultado' => ['j1' => 0, 'j2' => 0, 'empate' => 0],
            'porFranja' => ['00-06' => 0, '06-12' => 0, '12-18' => 0, '18-24' => 0],
            'porMes' => [],
        ];
        if (!is_file($logPath)) return $out;
        $fp = @fopen($logPath, 'rb');
        if ($fp === false) return $out;
        if (flock($fp, LOCK_SH)) {
            while (($l = fgets($fp)) !== false) {
                $r = json_decode(trim($l), true);
                if (!is_array($r)) continue;
                $ts = (int) ($r['ts'] ?? 0);
                if ($ts < $desde) continue;
                $out['total']++;
                $t = (string) ($r['tematica'] ?? 'desconocida');
                $out['porTematica'][$t] = ($out['porTematica'][$t] ?? 0) + 1;
                $out['porModo'][!empty($r['visibles']) ? 'visible' : 'oculto']++;
                $tipo = (string) ($r['tipo'] ?? 'privada');
                if (!isset($out['porTipo'][$tipo])) $out['porTipo'][$tipo] = 0;
                $out['porTipo'][$tipo]++;
                $res = (string) ($r['resultado'] ?? 'empate');
                if (!isset($out['porResultado'][$res])) $out['porResultado'][$res] = 0;
                $out['porResultado'][$res]++;
                $h = (int) date('G', $ts);
                if ($h < 6) $out['porFranja']['00-06']++;
                elseif ($h < 12) $out['porFranja']['06-12']++;
                elseif ($h < 18) $out['porFranja']['12-18']++;
                else $out['porFranja']['18-24']++;
                $mes = date('Y-m', $ts);
                if (!isset($out['porMes'][$mes])) {
                    $out['porMes'][$mes] = ['total' => 0, 'topTematica' => '', 'porTipo' => [], 'porTematica' => []];
                }
                $out['porMes'][$mes]['total']++;
                $mt = &$out['porMes'][$mes]['porTipo'];
                if (!isset($mt[$tipo])) $mt[$tipo] = 0;
                $mt[$tipo]++;
                $mtem = &$out['porMes'][$mes]['porTematica'];
                $mtem[$t] = ($mtem[$t] ?? 0) + 1;
                unset($mt, $mtem);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        // Orden: más partidas primero; a igualdad, id alfabético (ranking estable).
        $ordenar = static function (array $conteo): array {
            $pares = [];
            foreach ($conteo as $id => $c) $pares[] = [$id, $c];
            usort($pares, static fn(array $a, array $b): int => $b[1] <=> $a[1] ?: $a[0] <=> $b[0]);
            return array_column($pares, 0);
        };
        $ordenadas = $ordenar($out['porTematica']);
        $out['porTematica'] = array_replace(array_fill_keys($ordenadas, 0), $out['porTematica']);
        ksort($out['porMes']);
        foreach ($out['porMes'] as $mes => $datos) {
            $ordenadasMes = $ordenar($datos['porTematica']);
            $top = $ordenadasMes[0] ?? null;
            $out['porMes'][$mes] = [
                'total' => $datos['total'],
                'topTematica' => is_string($top) ? $top : '',
                'top' => array_slice($ordenadas, 0, 10),
                'porTipo' => $datos['porTipo'],
            ];
        }
        return $out;
    }
}
