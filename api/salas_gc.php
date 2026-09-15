<?php
/**
 * Draft 20 — GC oportunista de salas.
 *
 * Borra los JSON de api/salas/ con más de SALA_GC_EDAD_S segundos sin
 * escrituras (filemtime). Una partida activa escribe en cada poll (~1/s),
 * así que nunca se toca; las salas huérfanas (esperando/finalizada/abandonada)
 * se limpian al iniciar una partida nueva.
 *
 * Es best-effort: el caller debe envolverlo en try/catch. Usa flock no
 * bloqueante para no borrar una sala que otro request está usando.
 */
declare(strict_types=1);

const SALA_GC_EDAD_S = 3600; // 1 hora sin actividad

if (!function_exists('limpiar_salas_antiguas')) {
    /**
     * Elimina salas inactivas. Devuelve cuántas borró.
     */
    function limpiar_salas_antiguas(int $maxAgeS = SALA_GC_EDAD_S): int {
        $dir = __DIR__ . '/salas/';
        $archivos = glob($dir . '*.json');
        if ($archivos === false || $archivos === []) return 0;

        $ahora = time();
        $borradas = 0;
        foreach ($archivos as $path) {
            $mtime = @filemtime($path);
            if ($mtime === false || ($ahora - $mtime) <= $maxAgeS) continue;

            $fp = @fopen($path, 'r+b');
            if ($fp === false) continue;
            if (!@flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); continue; }

            // Doble check tras el lock (pudo refrescarse entre el stat y el lock).
            clearstatcache(true, $path);
            $mtime2 = @filemtime($path);
            if ($mtime2 !== false && ($ahora - $mtime2) > $maxAgeS) {
                fclose($fp);
                if (@unlink($path)) $borradas++;
            } else {
                @flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
        return $borradas;
    }
}
