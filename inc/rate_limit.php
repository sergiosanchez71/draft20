<?php
/**
 * Draft 20 — Guardas de API: rate limiting y comprobación de origen.
 *
 * Rate limit por IP + bucket con ventana fija, guardado en api/datos/rl/
 * (directorio ya bloqueado por HTTP). Los accesos desde loopback (tests y
 * desarrollo con `php -S`) no se limitan.
 */
declare(strict_types=1);

if (!function_exists('rl_client_ip')) {
    function rl_client_ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : '0.0.0.0';
    }
}

if (!function_exists('rl_es_local')) {
    function rl_es_local(): bool
    {
        $ip = rl_client_ip();
        return $ip === '127.0.0.1' || $ip === '::1';
    }
}

if (!function_exists('rl_permitir')) {
    /**
     * Ventana fija: permite hasta $max peticiones por IP y bucket cada $ventana
     * segundos. Devuelve false cuando se supera el límite.
     */
    function rl_permitir(string $bucket, int $max, int $ventana): bool
    {
        if (rl_es_local()) {
            return true;
        }

        $dir = __DIR__ . '/../api/datos/rl/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return true; // sin almacenamiento no bloqueamos (fail-open)
        }

        $file = $dir . $bucket . '_' . substr(hash('sha256', rl_client_ip()), 0, 24) . '.json';
        $fp = @fopen($file, 'c+b');
        if ($fp === false) {
            return true;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }

        $raw = stream_get_contents($fp);
        $data = json_decode((string) $raw, true);
        $ahora = time();
        if (!is_array($data) || ($ahora - (int) ($data['inicio'] ?? 0)) >= $ventana) {
            $data = ['inicio' => $ahora, 'n' => 0];
        }
        $data['n'] = (int) ($data['n'] ?? 0) + 1;
        $permitido = $data['n'] <= $max;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // GC oportunista (1% de las llamadas): borra ventanas caducadas.
        if (random_int(1, 100) === 1) {
            foreach ((array) @glob($dir . '*.json') as $viejo) {
                if (is_file($viejo) && (time() - (int) @filemtime($viejo)) > 7200) {
                    @unlink($viejo);
                }
            }
        }

        return $permitido;
    }
}

if (!function_exists('rl_guard')) {
    /** Corta con 429 si se supera el límite del bucket. */
    function rl_guard(string $bucket, int $max, int $ventana): void
    {
        if (rl_permitir($bucket, $max, $ventana)) {
            return;
        }
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: ' . $ventana);
        echo json_encode(['ok' => false, 'error' => 'Demasiadas peticiones. Inténtalo en un rato.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_guard_origen')) {
    /**
     * Defensa CSRF en profundidad: si el navegador indica que la petición es
     * cross-site, se rechaza. Si no hay cabeceras (clientes antiguos, tests,
     * curl) se permite: la API es JSON y no usa cookies.
     */
    function api_guard_origen(): void
    {
        $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($site === 'cross-site') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Origen no permitido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '') {
            $host  = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $oHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $permitido = $host !== '' && $oHost !== '' && (
                $oHost === $host
                || $oHost === 'www.' . $host
                || 'www.' . $oHost === $host
            );
            if (!$permitido) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Origen no permitido.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
}
