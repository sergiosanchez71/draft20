<?php
/**
 * Draft 20 — API: revancha
 *
 * Gestiona la propuesta de revancha entre dos jugadores al terminar una
 * partida. El proponente ya ha creado la sala nueva (api/crear_sala.php) y
 * aquí se registra en la sala vieja para que el rival pueda aceptarla.
 *
 * --- Petición ---
 *   POST  application/json
 *   Body: { "codigo": "A8F3X", "jugador_id": "j1_<uuid>", "accion": "proponer",
 *           "codigo_nuevo": "B9G4Y", "tematica": "pizza" }
 *   Body: { "codigo": "A8F3X", "jugador_id": "j1_<uuid>", "accion": "rechazar" }
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "sala": { ... estado completo ... } }
 *
 * --- Errores ---
 *   400 → parámetros inválidos
 *   403 → jugador_id no pertenece a la sala
 *   404 → sala (vieja o nueva) no encontrada
 *   409 → partida no finalizada, propuesta ya existente, o nada que rechazar
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/sala_publica.php';
require_once __DIR__ . '/../inc/rate_limit.php';

// ============================================================================
//  Config & constantes
// ============================================================================

const CHARSET        = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const SALAS_DIR      = __DIR__ . '/salas/';
const REVANCHA_TTL_S = 600; // 10 min: pasado ese tiempo la propuesta caduca

if (!function_exists('responder')) {
    function responder(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('leer_input_json')) {
    function leer_input_json(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}
if (!function_exists('leer_sala_bloqueado_sh')) {
    function leer_sala_bloqueado_sh(string $codigo): ?array {
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

function slot_de_jugador(array $sala, string $jugadorId): ?int {
    foreach ($sala['jugadores'] as $i => $j) {
        if (isset($j['id']) && $j['id'] === $jugadorId) return $i;
    }
    return null;
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
if (!in_array($accion, ['proponer', 'rechazar'], true)) {
    responder(['ok' => false, 'error' => 'Acción inválida.'], 400);
}

$codigoNuevo = '';
$idNuevo     = '';
$tematica    = null;
if ($accion === 'proponer') {
    $codigoNuevo = isset($input['codigo_nuevo']) && is_string($input['codigo_nuevo']) ? strtoupper(trim($input['codigo_nuevo'])) : '';
    // Id del proponente EN LA SALA NUEVA (la creó él con crear_sala.php): es la
    // prueba de propiedad; el id de la sala vieja ya no sirve para validarla.
    $idNuevo = isset($input['jugador_id_nuevo']) ? trim((string) $input['jugador_id_nuevo']) : '';
    if ($codigoNuevo === '' || !preg_match($regexCodigo, $codigoNuevo)) {
        responder(['ok' => false, 'error' => 'Código de la sala nueva inválido.'], 400);
    }
    if ($idNuevo === '' || !preg_match($regexJugador, $idNuevo)) {
        responder(['ok' => false, 'error' => 'Falta el jugador_id de la sala nueva.'], 400);
    }
    if (!is_file(SALAS_DIR . $codigoNuevo . '.json')) {
        responder(['ok' => false, 'error' => 'La sala nueva no existe.'], 404);
    }
    if ($codigoNuevo === $codigo) {
        responder(['ok' => false, 'error' => 'La sala nueva no puede ser la misma.'], 400);
    }
    if (isset($input['tematica']) && is_string($input['tematica'])) {
        $tematica = trim($input['tematica']);
        if (!preg_match('/^[a-z0-9_]+$/', $tematica)) {
            responder(['ok' => false, 'error' => 'Temática inválida.'], 400);
        }
    }
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

    if ($accion === 'proponer') {
        if ($fresca && (int) ($actual['por'] ?? -1) !== $miSlot) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'Ya hay una propuesta de revancha pendiente.'], 409);
        }
        // La sala nueva debe ser del proponente y seguir disponible:
        // - existe y pertenece a $idNuevo (el id que devolvió crear_sala),
        // - sigue en 'esperando' y con el slot J2 libre.
        $salaNueva = leer_sala_bloqueado_sh($codigoNuevo);
        if ($salaNueva === null || slot_de_jugador($salaNueva, $idNuevo) === null) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'La sala nueva no pertenece a este jugador.'], 403);
        }
        if (($salaNueva['estado'] ?? '') !== 'esperando' || ($salaNueva['jugadores'][1]['id'] ?? null) !== null) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'La sala nueva ya está en juego.'], 409);
        }
        $estado['revancha'] = [
            'por'          => $miSlot,
            'codigo_nuevo' => $codigoNuevo,
            'tematica'     => $tematica,
            'ts'           => time(),
        ];
    } else { // rechazar
        if (!$fresca) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'No hay propuesta de revancha pendiente.'], 409);
        }
        if ((int) ($actual['por'] ?? -1) === $miSlot) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'No puedes rechazar tu propia propuesta.'], 409);
        }
        $estado['revancha'] = null;
    }

    $estado['actualizado_en'] = time();
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);

    responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);

} catch (RuntimeException $e) {
    if (isset($fp) && is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    responder(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    responder(['ok' => false, 'error' => 'Error interno.'], 500);
}
