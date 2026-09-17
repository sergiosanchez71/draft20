<?php
/**
 * Draft 20 — API: partida rápida (matchmaking sin enlace).
 *
 * Empareja al jugador con el primero que también pulse "Partida rápida" y
 * tenga SU MISMO modo de "⭐ Valores visibles" (emparejamiento estricto):
 *   - Si hay alguien esperando con el mismo modo → se une a su sala.
 *   - Si no → crea una sala con temática aleatoria y su modo, y espera en la
 *     cola (puede haber una entrada esperando por cada modo).
 *
 * La cola vive en api/datos/cola_rapida.json (directorio bloqueado por HTTP)
 * y se purga en cada llamada: salas inexistentes, ya empezadas, con rival, o
 * cuyo creador lleva > COLA_STALE_S sin pollear (cerró la pestaña).
 *
 * --- Petición ---
 *   POST  { "nombre": "Opcional", "mostrar_valores": false }
 *   POST  { "accion": "cancelar", "codigo": "ABCDE", "jugador_id": "j1_..." }
 *   POST  { "accion": "cola", "jugador_id": "j1_..." }   // listado saneado
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "rol": "creador"|"rival", "codigo": "ABCDE",
 *     "jugador_id": "...", "mostrar_valores": false }
 */
declare(strict_types=1);

define('SALA_CORE_ONLY', true);
require_once __DIR__ . '/crear_sala.php';

const COLA_ARCHIVO   = __DIR__ . '/datos/cola_rapida.json';
const COLA_MAX       = 50;  // máximo de entradas en espera
const COLA_STALE_S   = 60;  // sin poll del creador → entrada muerta (tolera móvil en 2º plano)

if (!function_exists('cola_sh')) {
    /** Lee una sala con lock compartido (solo lectura). */
    function cola_sh(string $codigo): ?array
    {
        $path = SALAS_DIR . $codigo . '.json';
        if (!is_file($path)) {
            return null;
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('cola_abrir')) {
    function cola_abrir()
    {
        $dir = dirname(COLA_ARCHIVO);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo preparar el directorio de datos.');
        }
        $fp = @fopen(COLA_ARCHIVO, 'c+b');
        if ($fp === false) {
            throw new RuntimeException('No se pudo abrir la cola.');
        }
        return $fp;
    }
}

if (!function_exists('cola_leer')) {
    function cola_leer($fp): array
    {
        $raw = stream_get_contents($fp);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !is_array($data['espera'] ?? null)) {
            return ['espera' => []];
        }
        return $data;
    }
}

if (!function_exists('cola_guardar')) {
    function cola_guardar($fp, array $cola): void
    {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($cola, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
    }
}

if (!function_exists('cola_vivas')) {
    /**
     * Entradas vivas con metadatos de su sala (nombre del creador, modo ⭐ y
     * segundos esperando). Se usa para purgar, emparejar por modo y listar.
     */
    function cola_vivas(array $espera): array
    {
        $vivas = [];
        foreach ($espera as $e) {
            $codigo = (string) ($e['codigo'] ?? '');
            if ($codigo === '') {
                continue;
            }
            $sala = cola_sh($codigo);
            if ($sala === null) {
                continue;
            }
            if (($sala['estado'] ?? '') !== 'esperando') {
                continue;
            }
            if (($sala['jugadores'][1]['id'] ?? null) !== null) {
                continue;
            }
            // Creador vivo: usa su último poll; si aún no ha polleado, la hora de
            // creación (evita descartar una entrada recién creada).
            $seen = $sala['last_seen'][0] ?? null;
            $referencia = $seen !== null ? (int) $seen : (int) ($sala['creado_en'] ?? 0);
            if ($referencia <= 0 || (time() - $referencia) > COLA_STALE_S) {
                continue;
            }
            $vivas[] = [
                'codigo'     => $codigo,
                'jugador_id' => (string) ($e['jugador_id'] ?? ''),
                'nombre'     => (string) ($sala['jugadores'][0]['nombre'] ?? ''),
                'visible'    => !empty($sala['mostrar_valores']),
                'espera_s'   => max(0, time() - $referencia),
            ];
        }
        return $vivas;
    }
}

if (!function_exists('cola_purgar')) {
    /** Descarta entradas muertas: sala inexistente, empezada, ocupada o sin poll. */
    function cola_purgar(array $espera): array
    {
        return array_map(static function (array $v): array {
            return [
                'codigo'     => $v['codigo'],
                'jugador_id' => $v['jugador_id'],
                'creado_en'  => time() - (int) $v['espera_s'],
            ];
        }, cola_vivas($espera));
    }
}

if (!function_exists('tema_aleatoria')) {
    function tema_aleatoria(): string
    {
        $cats = require __DIR__ . '/../tematicas_catalogo.php';
        $ids = [];
        foreach ($cats as $cat) {
            foreach ($cat['tematicas'] as $tm) {
                $ids[] = (string) $tm['id'];
            }
        }
        if ($ids === []) {
            return 'hamburguesa';
        }
        return $ids[random_int(0, count($ids) - 1)];
    }
}

// ============================================================================
//  Validación
// ============================================================================

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
}

api_guard_origen();

$input  = leer_input_json();
$accion = isset($input['accion']) && is_string($input['accion']) ? $input['accion'] : 'buscar';

$regexCodigo  = '/^[' . CHARSET . ']{5}$/';
$regexJugador = '/^j[12]_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/';

// ============================================================================
//  Ver quién espera (saneado: sin código ni jugador_id)
// ============================================================================

if ($accion === 'cola') {
    rl_guard('cola', 600, 3600);
    $yoId = isset($input['jugador_id']) && is_string($input['jugador_id']) ? trim($input['jugador_id']) : '';
    if ($yoId !== '' && !preg_match($regexJugador, $yoId)) {
        responder(['ok' => false, 'error' => 'jugador_id inválido.'], 400);
    }
    try {
        $fp = cola_abrir();
        $vivas = [];
        if (flock($fp, LOCK_EX)) {
            $cola = cola_leer($fp);
            $vivas = cola_vivas($cola['espera']);
            $cola['espera'] = array_map(static function (array $v): array {
                return [
                    'codigo'     => $v['codigo'],
                    'jugador_id' => $v['jugador_id'],
                    'creado_en'  => time() - (int) $v['espera_s'],
                ];
            }, $vivas);
            cola_guardar($fp, $cola);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        $lista = [];
        foreach ($vivas as $v) {
            if ($yoId !== '' && $v['jugador_id'] === $yoId) {
                continue; // no te listes a ti mismo
            }
            $lista[] = [
                'nombre'   => $v['nombre'] !== '' ? $v['nombre'] : 'Jugador',
                'visible'  => (bool) $v['visible'],
                'espera_s' => (int) $v['espera_s'],
            ];
        }
        responder(['ok' => true, 'espera' => $lista], 200);
    } catch (Throwable $e) {
        responder(['ok' => false, 'error' => 'Error interno.'], 500);
    }
}

// ============================================================================
//  Cancelar búsqueda
// ============================================================================

if ($accion === 'cancelar') {
    rl_guard('cancelar', 120, 3600);
    $codigo    = isset($input['codigo']) && is_string($input['codigo']) ? strtoupper(trim($input['codigo'])) : '';
    $jugadorId = isset($input['jugador_id']) && is_string($input['jugador_id']) ? trim($input['jugador_id']) : '';
    if (!preg_match($regexCodigo, $codigo) || !preg_match($regexJugador, $jugadorId)) {
        responder(['ok' => false, 'error' => 'Parámetros inválidos.'], 400);
    }

    try {
        $fp = cola_abrir();
        $esMia = false;
        if (flock($fp, LOCK_EX)) {
            $cola = cola_leer($fp);
            $resto = [];
            foreach ($cola['espera'] as $e) {
                if ((string) ($e['codigo'] ?? '') === $codigo) {
                    // Solo el dueño de la entrada puede quitarla.
                    if ((string) ($e['jugador_id'] ?? '') === $jugadorId) {
                        $esMia = true;
                        continue;
                    }
                    $resto[] = $e;
                    continue;
                }
                $resto[] = $e;
            }
            $cola['espera'] = $resto;
            cola_guardar($fp, $cola);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        // Si ya no hay entrada (p. ej. te emparejaron), basta con ser el J1.
        $sala = cola_sh($codigo);
        if (!$esMia && ($sala['jugadores'][0]['id'] ?? null) !== $jugadorId) {
            responder(['ok' => false, 'error' => 'Esa búsqueda no es tuya.'], 403);
        }

        // Borra la sala solo si sigue esperando y es de quien cancela.
        if ($sala !== null
            && ($sala['estado'] ?? '') === 'esperando'
            && ($sala['jugadores'][0]['id'] ?? null) === $jugadorId
            && ($sala['jugadores'][1]['id'] ?? null) === null) {
            @unlink(SALAS_DIR . $codigo . '.json');
        }
        responder(['ok' => true], 200);
    } catch (Throwable $e) {
        responder(['ok' => false, 'error' => 'Error interno.'], 500);
    }
}

// ============================================================================
//  Buscar rival
// ============================================================================

$nombre = isset($input['nombre']) && is_string($input['nombre']) ? trim($input['nombre']) : '';
if (mb_strlen($nombre, 'UTF-8') > 20) {
    responder(['ok' => false, 'error' => 'Nombre demasiado largo (máx 20 caracteres).'], 400);
}
$mvBuscador = !empty($input['mostrar_valores']);

rl_guard('rapida', 30, 3600);

try {
    $fp = cola_abrir();
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        responder(['ok' => false, 'error' => 'No se pudo bloquear la cola.'], 500);
    }

    $cola = cola_leer($fp);
    $vivas = cola_vivas($cola['espera']);
    $vivasFmt = array_map(static function (array $v): array {
        return [
            'codigo'     => $v['codigo'],
            'jugador_id' => $v['jugador_id'],
            'creado_en'  => time() - (int) $v['espera_s'],
        ];
    }, $vivas);

    // 1) Hay alguien esperando CON TU MISMO modo de ⭐ → nos unimos a su sala.
    //    Emparejamiento estricto: si el que espera tiene otro modo, no se
    //    empareja (se crea sala propia y cada modo espera al suyo).
    $elegida = null;
    foreach ($vivas as $v) {
        if ((bool) $v['visible'] === $mvBuscador) {
            $elegida = $v;
            break;
        }
    }

    if ($elegida !== null) {
        $cola['espera'] = array_values(array_filter($vivasFmt, static function (array $v) use ($elegida): bool {
            return $v['codigo'] !== $elegida['codigo'];
        }));
        cola_guardar($fp, $cola);

        $codigo = (string) $elegida['codigo'];
        $path = SALAS_DIR . $codigo . '.json';
        $fpSala = @fopen($path, 'c+b');
        if ($fpSala === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            responder(['ok' => false, 'error' => 'La sala ya no está disponible. Vuelve a intentarlo.'], 409);
        }
        flock($fpSala, LOCK_EX);
        $rawSala = stream_get_contents($fpSala);
        $sala = ($rawSala === false || $rawSala === '') ? null : json_decode($rawSala, true);

        if (!is_array($sala)
            || ($sala['estado'] ?? '') !== 'esperando'
            || ($sala['jugadores'][1]['id'] ?? null) !== null) {
            flock($fpSala, LOCK_UN);
            fclose($fpSala);
            flock($fp, LOCK_UN);
            fclose($fp);
            responder(['ok' => false, 'error' => 'La sala ya no está disponible. Vuelve a intentarlo.'], 409);
        }

        $jugadorId = 'j2_' . uuid_v4();
        $sala['jugadores'][1] = [
            'id'            => $jugadorId,
            'nombre'        => $nombre !== '' ? $nombre : 'Jugador 2',
            'dinero'        => DINERO_INICIAL,
            'items_ganados' => [],
        ];
        $sala['estado'] = 'jugando';
        $sala['actualizado_en'] = time();
        if (!isset($sala['last_seen']) || !is_array($sala['last_seen'])) {
            $sala['last_seen'] = [null, null];
        }
        $sala['last_seen'][1] = time();
        ftruncate($fpSala, 0);
        rewind($fpSala);
        fwrite($fpSala, json_encode($sala, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fpSala);
        flock($fpSala, LOCK_UN);
        fclose($fpSala);
        flock($fp, LOCK_UN);
        fclose($fp);

        try { limpiar_salas_antiguas(); } catch (Throwable $e) { /* best-effort */ }

        responder(['ok' => true, 'rol' => 'rival', 'codigo' => $codigo, 'jugador_id' => $jugadorId,
            'mostrar_valores' => !empty($sala['mostrar_valores'])], 200);
    }

    // 2) No hay nadie → creamos sala con temática aleatoria y esperamos.
    $cola['espera'] = $vivasFmt;
    if (count($cola['espera']) >= COLA_MAX) {
        flock($fp, LOCK_UN);
        fclose($fp);
        responder(['ok' => false, 'error' => 'Hay demasiada gente esperando. Prueba en un minuto.'], 503);
    }

    $res = crear_sala_nueva(tema_aleatoria(), $nombre, ['rapida' => true, 'mostrar_valores' => $mvBuscador]);
    $cola['espera'][] = [
        'codigo'     => $res['codigo'],
        'jugador_id' => $res['jugador_id'],
        'creado_en'  => time(),
    ];
    cola_guardar($fp, $cola);
    flock($fp, LOCK_UN);
    fclose($fp);

    responder(['ok' => true, 'rol' => 'creador', 'codigo' => $res['codigo'], 'jugador_id' => $res['jugador_id'],
        'mostrar_valores' => $mvBuscador], 200);

} catch (InvalidArgumentException $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    responder(['ok' => false, 'error' => 'Error interno.'], 500);
}
