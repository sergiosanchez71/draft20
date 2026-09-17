<?php
/**
 * Draft 20 — API: unirse_sala
 *
 * Une al Jugador 2 a una sala en estado "esperando". Rellena el slot J2,
 * genera su jugador_id, cambia el estado a "jugando" y devuelve la sala
 * completa para que el cliente entre directo a la partida.
 *
 * --- Petición ---
 *   POST  application/json
 *   Body: { "codigo": "A8F3X", "nombre": "Sergi" }
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "jugador_id": "j2_<uuid>", "sala": { ... estado completo ... } }
 *
 * --- Respuestas de error ---
 *   400 → parámetros inválidos
 *   405 → método incorrecto
 *   404 → sala no encontrada / expirada
 *   409 → sala ya empezada o slot J2 ocupado
 *   500 → error interno
 */

declare(strict_types=1);

require_once __DIR__ . '/salas_gc.php';
require_once __DIR__ . '/../inc/sala_publica.php';
require_once __DIR__ . '/../inc/rate_limit.php';

// ============================================================================
//  Config & constantes (idénticas al resto de endpoints — guards evitan colisión)
// ============================================================================

const CHARSET          = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const SALAS_DIR        = __DIR__ . '/salas/';
const DINERO_INICIAL   = 20;
const MAX_INTENTOS     = 25;

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
if (!function_exists('uuid_v4')) {
    function uuid_v4(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
if (!function_exists('leer_sala_bloqueado_sh')) {
    /**
     * Lectura con LOCK_SH. TTL de 24h → si está expirada la borra y devuelve null.
     */
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
if (!function_exists('escribir_sala_bloqueado_ex')) {
    /**
     * Escritura atómica con LOCK_EX. Trunca y reescribe.
     */
    function escribir_sala_bloqueado_ex(string $codigo, array $data): void {
        $path = SALAS_DIR . $codigo . '.json';
        $fp = fopen($path, 'c+b');
        if ($fp === false) throw new RuntimeException('No se pudo abrir la sala para escritura.');
        if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('No LOCK_EX.'); }
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
    }
}

// ============================================================================
//  Validación
// ============================================================================

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
}

api_guard_origen();
rl_guard('unirse', 60, 3600);

$input    = leer_input_json();
$codigo   = isset($input['codigo']) && is_string($input['codigo']) ? strtoupper(trim($input['codigo'])) : '';
$nombreJ2 = isset($input['nombre']) && is_string($input['nombre']) ? trim($input['nombre']) : '';

// Unión marcada como bot (partida de práctica): el slot no pollea, así que
// no debe contar para abandono ni para el aviso de "rival desconectado".
// Solo el creador de la sala puede sentar un bot: hay que probar el id de J1
// (si no, cualquiera podría marcarse como bot y ver ⭐/ítems ocultos).
$esBot = isset($input['bot'])
    && ($input['bot'] === true || $input['bot'] === 1 || $input['bot'] === '1' || $input['bot'] === 'true');
$creadorId = isset($input['creador_id']) && is_string($input['creador_id']) ? trim($input['creador_id']) : '';

$regexCodigo = '/^[' . CHARSET . ']{5}$/';
if ($codigo === '' || !preg_match($regexCodigo, $codigo)) {
    responder(['ok' => false, 'error' => 'Código de sala inválido.'], 400);
}
if (mb_strlen($nombreJ2, 'UTF-8') > 20) {
    responder(['ok' => false, 'error' => 'Nombre demasiado largo (máx 20 caracteres).'], 400);
}

// ============================================================================
//  Lógica
// ============================================================================

try {
    // Leemos para validar estado antes de bloquear para escritura.
    $estado = leer_sala_bloqueado_sh($codigo);
    if ($estado === null) {
        responder(['ok' => false, 'error' => 'Sala no encontrada o expirada.'], 404);
    }
    if (($estado['estado'] ?? '') !== 'esperando') {
        responder(['ok' => false, 'error' => 'La sala ya empezó o está finalizada.'], 409);
    }
    if (!isset($estado['jugadores'][1]) || ($estado['jugadores'][1]['id'] ?? null) !== null) {
        responder(['ok' => false, 'error' => 'El slot del segundo jugador ya está ocupado.'], 409);
    }
    if ($esBot && ($creadorId === '' || $creadorId !== ($estado['jugadores'][0]['id'] ?? null))) {
        responder(['ok' => false, 'error' => 'Solo el creador de la sala puede añadir un bot.'], 403);
    }

    // Bloqueo exclusivo y re-leo por si hubo carrera entre SH y EX.
    $path = SALAS_DIR . $codigo . '.json';
    $fp = fopen($path, 'c+b');
    if ($fp === false) throw new RuntimeException('No se pudo abrir la sala para bloquear.');
    if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('No LOCK_EX.'); }
    $raw = stream_get_contents($fp);
    // Sala borrada/recreada vacía entre el SH y el lock.
    if ($raw === false || $raw === '') {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => 'Sala no encontrada o expirada.'], 404);
    }
    $estado = json_decode($raw, true);
    if (!is_array($estado)) {
        flock($fp, LOCK_UN); fclose($fp);
        throw new RuntimeException('Sala malformada al bloquear.');
    }
    // Doble-check tras el lock.
    if (($estado['estado'] ?? '') !== 'esperando' || ($estado['jugadores'][1]['id'] ?? null) !== null) {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => 'La sala ya empezó o el slot está ocupado.'], 409);
    }

    $jugadorIdJ2 = 'j2_' . uuid_v4();
    $estado['jugadores'][1] = [
        'id'            => $jugadorIdJ2,
        'nombre'        => $nombreJ2 !== '' ? $nombreJ2 : 'Jugador 2',
            'dinero'        => DINERO_INICIAL,
        'items_ganados' => [],
    ];
    $estado['estado']         = 'jugando';
    $estado['actualizado_en'] = time();
    // El reloj de abandono arranca al unirse (aunque aún no haya polleado).
    if (!isset($estado['last_seen']) || !is_array($estado['last_seen'])) {
        $estado['last_seen'] = [null, null];
    }
    $estado['last_seen'][1] = time();
    if ($esBot) {
        $estado['bot_slot'] = 1;
    }

    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);

    // GC oportunista: se ejecuta al iniciar partida de verdad (J2 entra).
    try { limpiar_salas_antiguas(); } catch (Throwable $e) { /* best-effort */ }

    responder([
        'ok'         => true,
        'jugador_id' => $jugadorIdJ2,
        'sala'       => sala_publica($estado, 1),
    ], 200);

} catch (RuntimeException $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    responder(['ok' => false, 'error' => 'Error interno.'], 500);
}