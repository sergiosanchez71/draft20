<?php
/**
 * Draft 20 — API: revancha
 *
 * Gestiona la revancha SIN cambiar de sala: se reinicia la misma partida
 * (mismo código y mismos jugadores/sesiones), así no hay que volver a
 * compartir el enlace. Alterna quién empieza y lleva la cuenta en `partida_n`.
 *
 * Acciones:
 *   - proponer : registra {por, tematica, ts} en la sala (cualquiera de los dos).
 *   - rechazar : el rival rechaza la propuesta pendiente.
 *   - cancelar : el proponente retira su propia propuesta.
 *   - aceptar  : el rival acepta y la sala se reinicia (estado 'jugando').
 *   - reiniciar: salas de práctica contra bot; el dueño humano reinicia ya.
 *
 * --- Petición (POST JSON) ---
 *   { "codigo": "A8F3X", "jugador_id": "j1_<uuid>", "accion": "proponer", "tematica": "pizza" }
 *   { "codigo": "A8F3X", "jugador_id": "j1_<uuid>", "accion": "aceptar" }
 *   { "codigo": "A8F3X", "jugador_id": "j1_<uuid>", "accion": "reiniciar", "tematica": "pizza" }
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "sala": { ... estado completo ... } }
 *
 * --- Errores ---
 *   400 → parámetros inválidos / temática inexistente
 *   403 → jugador_id no pertenece a la sala
 *   404 → sala no encontrada
 *   409 → partida no finalizada o acción sin propuesta aplicable
 */

declare(strict_types=1);

define('SALA_CORE_ONLY', true);
require_once __DIR__ . '/crear_sala.php';
require_once __DIR__ . '/../inc/sala_publica.php';

// ============================================================================
//  Config & constantes
// ============================================================================

const REVANCHA_TTL_S = 600; // 10 min: pasado ese tiempo la propuesta caduca

if (!function_exists('leer_sala_bloqueado_sh')) {
    function leer_sala_bloqueado_sh(string $codigo): ?array
    {
        $path = SALAS_DIR . $codigo . '.json';
        if (!is_file($path)) return null;
        $fp = fopen($path, 'rb');
        if ($fp === false) throw new RuntimeException('No se pudo abrir la sala.');
        if (!flock($fp, LOCK_SH)) { fclose($fp); throw new RuntimeException('No LOCK_SH.'); }
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN); fclose($fp);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('Sala malformada.');
        $ult = isset($data['actualizado_en']) ? (int) $data['actualizado_en'] : 0;
        if ($ult > 0 && (time() - $ult) > 86400) { @unlink($path); return null; }
        return $data;
    }
}

if (!function_exists('slot_de_jugador')) {
    function slot_de_jugador(array $sala, string $jugadorId): ?int {
        foreach ($sala['jugadores'] as $i => $j) {
            if (isset($j['id']) && $j['id'] === $jugadorId) return $i;
        }
        return null;
    }
}

// ============================================================================
//  Validación de entrada
// ============================================================================

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
}

api_guard_origen();
rl_guard('revancha', 60, 3600);

$input     = leer_input_json();
$codigo    = isset($input['codigo']) && is_string($input['codigo']) ? strtoupper(trim($input['codigo'])) : '';
$jugadorId = isset($input['jugador_id']) && is_string($input['jugador_id']) ? trim($input['jugador_id']) : '';
$accion    = isset($input['accion']) && is_string($input['accion']) ? trim($input['accion']) : '';

$regexCodigo  = '/^[' . CHARSET . ']{5}$/';
$regexJugador = '/^j[12]_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/';

if ($codigo === '' || !preg_match($regexCodigo, $codigo)) {
    responder(['ok' => false, 'error' => 'Código de sala inválido.'], 400);
}
if ($jugadorId === '' || !preg_match($regexJugador, $jugadorId)) {
    responder(['ok' => false, 'error' => 'jugador_id inválido.'], 400);
}
if (!in_array($accion, ['proponer', 'rechazar', 'cancelar', 'aceptar', 'reiniciar'], true)) {
    responder(['ok' => false, 'error' => 'Acción inválida.'], 400);
}

$tematica = null;
if (in_array($accion, ['proponer', 'reiniciar'], true) && isset($input['tematica']) && is_string($input['tematica'])) {
    $tematica = trim($input['tematica']);
    if ($tematica === '' || !preg_match('/^[a-z0-9_]+$/', $tematica)) {
        responder(['ok' => false, 'error' => 'Temática inválida.'], 400);
    }
}
if ($accion === 'reiniciar' && $tematica === null) {
    responder(['ok' => false, 'error' => 'Falta la temática.'], 400);
}

// ============================================================================
//  Lógica
// ============================================================================

try {
    $estado = leer_sala_bloqueado_sh($codigo);
    if ($estado === null) {
        responder(['ok' => false, 'error' => 'Sala no encontrada o expirada.'], 404);
    }
    if (($estado['estado'] ?? '') !== 'finalizada') {
        responder(['ok' => false, 'error' => 'La partida no ha terminado.'], 409);
    }

    $path = SALAS_DIR . $codigo . '.json';
    $fp = fopen($path, 'c+b');
    if ($fp === false) throw new RuntimeException('No se pudo abrir la sala para bloquear.');
    if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('No LOCK_EX.'); }
    $raw = stream_get_contents($fp);
    if ($raw === false || $raw === '') {
        flock($fp, LOCK_UN); fclose($fp);
        @unlink($path);
        responder(['ok' => false, 'error' => 'Sala no encontrada o expirada.'], 404);
    }
    $estado = json_decode($raw, true);
    if (!is_array($estado)) {
        flock($fp, LOCK_UN); fclose($fp);
        throw new RuntimeException('Sala malformada al bloquear.');
    }

    // Re-validar tras el lock.
    if (($estado['estado'] ?? '') !== 'finalizada') {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => 'La partida no ha terminado.'], 409);
    }
    $miSlot = slot_de_jugador($estado, $jugadorId);
    if ($miSlot === null) {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => 'No perteneces a esta sala.'], 403);
    }

    if (!array_key_exists('revancha', $estado)) $estado['revancha'] = null;
    $actual = is_array($estado['revancha']) ? $estado['revancha'] : null;
    $fresca = $actual !== null && (time() - (int) ($actual['ts'] ?? 0)) <= REVANCHA_TTL_S;

    $responderError = static function (string $error, int $code) use ($fp): void {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => $error], $code);
    };

    if ($accion === 'proponer') {
        if ($fresca && (int) ($actual['por'] ?? -1) !== $miSlot) {
            $responderError('Tu rival ya ha propuesto revancha.', 409);
        }
        $temaProp = $tematica ?? (string) ($estado['tematica'] ?? '');
        cargar_tematica($temaProp); // valida que existe (400 si no)
        $estado['revancha'] = [
            'por'      => $miSlot,
            'tematica' => $temaProp,
            'ts'       => time(),
        ];
    } elseif ($accion === 'rechazar') {
        if (!$fresca) {
            $responderError('No hay propuesta de revancha pendiente.', 409);
        }
        if ((int) ($actual['por'] ?? -1) === $miSlot) {
            $responderError('No puedes rechazar tu propia propuesta.', 409);
        }
        $estado['revancha'] = null;
    } elseif ($accion === 'cancelar') {
        if (!$fresca || (int) ($actual['por'] ?? -1) !== $miSlot) {
            $responderError('No tienes una propuesta pendiente.', 409);
        }
        $estado['revancha'] = null;
    } elseif ($accion === 'aceptar') {
        if (!$fresca) {
            $responderError('No hay ninguna propuesta de revancha.', 409);
        }
        if ((int) ($actual['por'] ?? -1) === $miSlot) {
            $responderError('No puedes aceptar tu propia propuesta.', 409);
        }
        $temaAceptar = (string) ($actual['tematica'] ?? '');
        if ($temaAceptar === '') {
            $temaAceptar = (string) ($estado['tematica'] ?? '');
        }
        $estado = reiniciar_sala($estado, $temaAceptar);
    } else { // reiniciar (solo salas de práctica contra bot; el humano reinicia ya)
        $botSlot = $estado['bot_slot'] ?? null;
        if ($botSlot === null) {
            $responderError('Esta sala no es de práctica.', 409);
        }
        if ((int) $botSlot === $miSlot) {
            $responderError('El bot no puede reiniciar la partida.', 403);
        }
        $estado = reiniciar_sala($estado, (string) $tematica);
    }

    $estado['actualizado_en'] = time();
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);

    responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);

} catch (InvalidArgumentException $e) {
    if (isset($fp) && is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    responder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    if (isset($fp) && is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    responder(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    if (isset($fp) && is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    responder(['ok' => false, 'error' => 'Error interno.'], 500);
}
