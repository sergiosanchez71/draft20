<?php
/**
 * Draft 20 — Agregado del ranking de partidas (CLI + HTTP).
 *
 * Sin top-level ejecutable: solo define ranking_humanas(). La usan
 * tools/ranking.php (CLI) y api/ranking.php (JSON con clave).
 */
declare(strict_types=1);

if (!function_exists('ranking_humanas')) {
    /**
     * Agrega api/datos/partidas_humanas.jsonl.
     *
     * @return array{total:int,porTematica:array<string,int>,porModo:array<string,int>,porTipo:array<string,int>,porResultado:array<string,int>,porFranja:array<string,int>}
     */
    function ranking_humanas(string $logPath, int $dias): array
    {
        $desde = time() - max(1, $dias) * 86400;
        $out = [
            'total' => 0,
            'porTematica' => [],
            'porModo' => ['visible' => 0, 'oculto' => 0],
            'porTipo' => ['privada' => 0, 'rapida' => 0, 'revancha' => 0, 'bot' => 0],
            'porResultado' => ['j1' => 0, 'j2' => 0, 'empate' => 0],
            'porFranja' => ['00-06' => 0, '06-12' => 0, '12-18' => 0, '18-24' => 0],
        ];
        if (!is_file($logPath)) return $out;
        $fp = @fopen($logPath, 'rb');
        if ($fp === false) return $out;
        if (flock($fp, LOCK_SH)) {
            while (($l = fgets($fp)) !== false) {
                $r = json_decode(trim($l), true);
                if (!is_array($r) || (int) ($r['ts'] ?? 0) < $desde) continue;
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
                $h = (int) date('G', (int) $r['ts']);
                if ($h < 6) $out['porFranja']['00-06']++;
                elseif ($h < 12) $out['porFranja']['06-12']++;
                elseif ($h < 18) $out['porFranja']['12-18']++;
                else $out['porFranja']['18-24']++;
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        arsort($out['porTematica']);
        return $out;
    }
}
