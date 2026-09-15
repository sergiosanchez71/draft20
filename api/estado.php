<?php
/**
 * Draft 20 — API: estado
 *
 * Devuelve el estado completo de una sala. El cliente lo consulta cada ~1s
 * (short polling) para mantener la UI sincronizada entre jugadores.
 *
 * Si llega `jugador_id`, actualiza `last_seen[slot]` y comprueba si el OTRO
 * jugador lleva > ABANDON_TIMEOUT_S sin actividad → marca la sala como
 * 'abandonada' (abandono por timeout sin click explícito de "Salir").
 *
 * --- Petición ---
 *   GET  ?codigo=XXXXX[&jugador_id=j1_<uuid>]
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "sala": { ... estado completo ... } }
 *
 * --- Respuestas de error ---
 *   400 → código inválido
 *   404 → sala no encontrada o expirada por TTL
 *   500 → error interno
 */

declare(strict_types=1);

// ============================================================================
//  Config & constantes
// ============================================================================

const CHARSET          = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const SALAS_DIR        = __DIR__ . '/salas/';
const TTL_SEGUNDOS     = 86400; // 24h → red de seguridad pasiva (el GC activo borra a 1h)
const RIVAL_AUSENTE_S  = 6;      // 6s sin poll → se avisa "rival desconectado" (sin abandonar)
const ABANDON_TIMEOUT_S = 45;    // 45s sin poll → abandono definitivo

// ============================================================================
//  Helpers (con guard por si se carga junto a crear_sala.php)
// ============================================================================

if (!function_exists('responder')) {
    function responder(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

/**
 * Comprueba si el OTRO jugador lleva demasiado tiempo sin aparecer.
 * Si es así y la sala está 'jugando', la marca como 'abandonada'.
 * Devuelve true si se acaba de marcar un abandono en esta llamada.
 */
function check_and_trigger_abandon(array &$sala, int $callingSlot): bool {
    if (($sala['estado'] ?? '') !== 'jugando') return false;
    if (!isset($sala['last_seen']) || !is_array($sala['last_seen'])) return false;
    $other = 1 - $callingSlot;
    if (empty($sala['jugadores'][$other]['id'])) return false; // J2 no unido todavía
    $otherSeen = $sala['last_seen'][$other] ?? null;
    if ($otherSeen === null) return false;
    if ((time() - (int) $otherSeen) <= ABANDON_TIMEOUT_S) return false;
    $sala['estado'] = 'abandonada';
    $sala['abandono_por'] = $other;
    $sala['actualizado_en'] = time();
    return true;
}

// ============================================================================
//  Validación
// ============================================================================

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
}

$codigo    = isset($_GET['codigo'])     ? strtoupper(trim((string) $_GET['codigo'])) : '';
$jugadorId = isset($_GET['jugador_id']) ? trim((string) $_GET['jugador_id']) : '';

$regexCodigo  = '/^[' . CHARSET . ']{5}$/';
$regexJugador = '/^j[12]_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/';

if ($codigo === '' || !preg_match($regexCodigo, $codigo)) {
    responder(['ok' => false, 'error' => 'Código de sala inválido.'], 400);
}
if ($jugadorId !== '' && !preg_match($regexJugador, $jugadorId)) {
    responder(['ok' => false, 'error' => 'jugador_id inválido.'], 400);
}

// ============================================================================
//  Lectura + opcional: actualizar last_seen y comprobar timeout
// ============================================================================

$path = SALAS_DIR . $codigo . '.json';
if (!is_file($path)) {
    responder(['ok' => false, 'error' => 'Sala no encontrada o expirada.'], 404);
}

$fp = fopen($path, 'c+b');
if ($fp === false) {
    responder(['ok' => false, 'error' => 'No se pudo abrir la sala.'], 500);
}
if (!flock($fp, LOCK_EX)) { fclose($fp); responder(['ok' => false, 'error' => 'No LOCK_EX.'], 500); }

$raw = stream_get_contents($fp);
$estado = json_decode($raw, true);
if (!is_array($estado)) {
    flock($fp, LOCK_UN); fclose($fp);
    responder(['ok' => false, 'error' => 'Sala malformada.'], 500);
}

// TTL: si lleva más de TTL_SEGUNDOS sin moverse, la borramos y respondemos 404.
$ultimaAct = isset($estado['actualizado_en']) ? (int) $estado['actualizado_en'] : 0;
if ($ultimaAct > 0 && (time() - $ultimaAct) > TTL_SEGUNDOS) {
    flock($fp, LOCK_UN); fclose($fp);
    @unlink($path);
    responder(['ok' => false, 'error' => 'Sala no encontrada o expirada.'], 404);
}

// Inicializar last_seen por compatibilidad con salas antiguas.
if (!isset($estado['last_seen']) || !is_array($estado['last_seen'])) {
    $estado['last_seen'] = [null, null];
}
if (!array_key_exists('abandono_por', $estado)) {
    $estado['abandono_por'] = null;
}

// Si llega jugador_id, resolver slot y actualizar last_seen.
$miSlot = null;
$rivalAusente = null;
if ($jugadorId !== '') {
    foreach ($estado['jugadores'] as $i => $j) {
        if (isset($j['id']) && $j['id'] === $jugadorId) {
            $miSlot = $i;
            break;
        }
    }
    if ($miSlot !== null) {
        $estado['last_seen'][$miSlot] = time();

        // Segundos que lleva el rival sin dar señales (aviso blando en UI).
        $other = 1 - $miSlot;
        if (!empty($estado['jugadores'][$other]['id'])) {
            $otherSeen = $estado['last_seen'][$other] ?? null;
            if ($otherSeen !== null) {
                $rivalAusente = max(0, time() - (int) $otherSeen);
            }
        }

        // Comprobar timeout del OTRO jugador (abandono definitivo).
        $triggered = check_and_trigger_abandon($estado, $miSlot);
        if ($triggered) {
            $estado['actualizado_en'] = time();
        }
    }
}

$estado['actualizado_en'] = time();

// Persistir (hemos podido tocar last_seen o estado).
ftruncate($fp, 0); rewind($fp);
fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN); fclose($fp);

responder(['ok' => true, 'sala' => $estado, 'rival_ausente' => $rivalAusente], 200);
