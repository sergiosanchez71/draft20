<?php
/**
 * Draft 20 — API: accion
 *
 * Procesa una acción de un jugador sobre la partida activa.
 * Acciones válidas: "pujar" (suma al precio, pasa turno), "bajar" (asigna el
 * ítem al último pujador al precio actual y avanza al siguiente),
 * "pasar_deadlock", "asignar_rival", "abandonar" y "emote".
 *
 * Mecánica del cap:
 *   - Cada jugador puede tener un MÁXIMO de MAX_ITEMS_POR_JUGADOR ítems (4).
 *   - Si al asignar un ítem el ganador llega a 4 → `asignacion_forzada_a`
 *     se rellena con el índice del rival.
 *   - En la siguiente acción (o en la misma respuesta), los ítems restantes
 *     se asignan automáticamente al jugador forzado a precio=0 sin subasta.
 *   - Si el jugador forzado también llega a 4 con un ítem auto-asignado,
 *     el flag alterna al otro. Esto preserva el cap en la mayoría de casos
 *     (8 ítems totales, 4 por jugador = split exacto en juego normal).
 *
 * --- Petición ---
 *   POST  application/json
 *   Body: { "codigo": "A8F3X", "jugador_id": "j1_<uuid>", "accion": "pujar"|"bajar" }
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "sala": { ... estado completo ... } }
 *
 * --- Respuestas de error ---
 *   400 → parámetros inválidos, bajar sin puja previa, sin dinero
 *   403 → jugador_id no presente en la sala
 *   405 → método incorrecto
 *   404 → sala no encontrada / expirada
 *   409 → estado inválido, no es tu turno, ya estás en el cap
 *   500 → error interno
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/sala_publica.php';
require_once __DIR__ . '/../inc/rate_limit.php';

// ============================================================================
//  Config & constantes
// ============================================================================

const CHARSET          = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const SALAS_DIR        = __DIR__ . '/salas/';
const TEMATICAS_DIR    = __DIR__ . '/../tematicas/';
const DINERO_INICIAL   = 20;
const MAX_ITEMS_POR_JUGADOR = 4;   // cap duro por jugador
const INCREMENTO_PUJA  = 1;          // +1 por puja (UI tiene también botón +3 vía "pujar3")
const ABANDON_TIMEOUT_S = 45;        // 45s sin poll → abandono definitivo
const EMOTES_MAX       = 10;         // máximo de emotes guardados en la sala
const EMOTES_VALIDOS   = ['👍', '😂', '🔥', '😭', '🤝', '😱'];

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

/**
 * Busca el índice (0 ó 1) de un jugador por su id dentro de la sala.
 * Devuelve null si no está.
 */
function slot_de_jugador(array $sala, string $jugadorId): ?int {
    foreach ($sala['jugadores'] as $i => $j) {
        if (isset($j['id']) && $j['id'] === $jugadorId) return $i;
    }
    return null;
}

/**
 * Carga los metadatos de una temática desde /tematicas/<id>.json.
 * Devuelve ['emoji' => [id=>emoji], 'valor' => [id=>valor]].
 * Valida formato seguro.
 */
function cargar_tematica_meta(string $id): array {
    $empty = ['emoji' => [], 'valor' => []];
    if (!preg_match('/^[a-z0-9_]+$/', $id)) return $empty;
    $path = TEMATICAS_DIR . $id . '.json';
    if (!is_file($path)) return $empty;
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['items'])) return $empty;
    $emoji = [];
    $valor = [];
    foreach ($data['items'] as $it) {
        if (empty($it['id']) || empty($it['emoji'])) continue;
        $emoji[$it['id']] = $it['emoji'];
        $valor[$it['id']] = isset($it['valor']) ? max(1, (int) $it['valor']) : 1;
    }
    return ['emoji' => $emoji, 'valor' => $valor];
}

/**
 * Helper: añade un ítem al inventario de un jugador.
 */
function push_item(array &$jugador, string $itemId, string $emoji, int $valor, int $precio): void {
    $jugador['items_ganados'][] = [
        'id'     => $itemId,
        'emoji'  => $emoji,
        'valor'  => $valor,
        'precio' => $precio,
    ];
}

/**
 * Avanza $sala al siguiente ítem (paso por referencia).
 * Si no quedan ítems, marca item_actual = null (no cambia estado aquí;
 * el caller decide si finalizar).
 *
 * El siguiente round lo empieza el OTRO jugador (alterna J1 ↔ J2).
 */
function avanzar_a_siguiente_item(array &$sala, array $emojiMap): void {
    $sala['indice_item']++;
    if ($sala['indice_item'] >= count($sala['items_mezclados'])) {
        $sala['item_actual'] = null;
        return;
    }
    $nextId   = $sala['items_mezclados'][$sala['indice_item']];
    $newStart = 1 - $sala['turno_inicial_ronda'];
    $sala['item_actual'] = [
        'id'            => $nextId,
        'emoji'         => $emojiMap[$nextId] ?? '·',
        'precio_actual' => 0,
        'turno_de'      => $newStart,
        'ultimo_pujo'   => null,
        'auto_asignado' => false,
        'pujas'         => [],
    ];
    $sala['ronda']++;
    $sala['turno_inicial_ronda'] = $newStart;
}

/**
 * Asigna el ítem actual a un ganador (con cap pivot), cobra el precio,
 * avanza al siguiente ítem, consume auto-asignaciones y finaliza si toca.
 * La usa bajar() para no duplicar la mecánica.
 */
function asignar_item_ganador(array &$sala, int $winner, int $price, array $emojiMap, array $valorMap): void {
    // Cap pivot: si el destino ya está en el cap, el ítem va al otro a precio 0.
    if (count($sala['jugadores'][$winner]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
        $winner = 1 - $winner;
        $price  = 0;
    }

    $itemId    = $sala['item_actual']['id'];
    $itemEmoji = $sala['item_actual']['emoji'];
    $itemValor = $valorMap[$itemId] ?? 1;

    $sala['jugadores'][$winner]['dinero'] -= $price;
    push_item($sala['jugadores'][$winner], $itemId, $itemEmoji, $itemValor, $price);

    // Cap cascade: si llega al máximo, forzar al rival los restantes.
    if (count($sala['jugadores'][$winner]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
        $sala['asignacion_forzada_a'] = 1 - $winner;
    }

    avanzar_a_siguiente_item($sala, $emojiMap);

    if ($sala['asignacion_forzada_a'] !== null) {
        procesar_auto_asignaciones($sala, $emojiMap, $valorMap);
    }
    if ($sala['item_actual'] === null) {
        $sala['estado'] = 'finalizada';
        registrar_ultimo_item($sala);
    }
}

/**
 * Procesa todas las auto-asignaciones pendientes (mientras
 * asignacion_forzada_a !== null y queden ítems). Al acabar,
 * si no quedan ítems, marca estado='finalizada'.
 */
function procesar_auto_asignaciones(array &$sala, array $emojiMap, array $valorMap): void {
    while ($sala['asignacion_forzada_a'] !== null && $sala['item_actual'] !== null) {
        $forced    = $sala['asignacion_forzada_a'];
        $itemId    = $sala['item_actual']['id'];
        $itemEmoji = $sala['item_actual']['emoji'];
        $itemValor = $valorMap[$itemId] ?? 1;

        push_item($sala['jugadores'][$forced], $itemId, $itemEmoji, $itemValor, 0);
        // Sin deducción de dinero (auto-asignación).

        // Toggle: si el forzado también llega al cap, pasa al otro.
        if (count($sala['jugadores'][$forced]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
            $sala['asignacion_forzada_a'] = 1 - $forced;
        } else {
            $sala['asignacion_forzada_a'] = null;
        }
        avanzar_a_siguiente_item($sala, $emojiMap);
    }
    if ($sala['item_actual'] === null) {
        $sala['estado'] = 'finalizada';
        registrar_ultimo_item($sala);
    }
}

/**
 * Guarda en la sala el último ítem de la partida (quién se lo llevó y por
 * cuánto) justo al finalizar. El 8º ítem se asigna en la misma acción que
 * cierra la partida, así que la carta nunca llega a pintarlo: el cliente lo
 * muestra en un popup leyendo este campo.
 */
function registrar_ultimo_item(array &$sala): void {
    if (empty($sala['items_mezclados'])) return;
    $ultimoId = (string) $sala['items_mezclados'][count($sala['items_mezclados']) - 1];
    foreach ($sala['jugadores'] as $slot => $jugador) {
        foreach (($jugador['items_ganados'] ?? []) as $it) {
            if (($it['id'] ?? null) !== $ultimoId) continue;
            $sala['ultimo_item'] = [
                'id'      => $ultimoId,
                'emoji'   => (string) ($it['emoji'] ?? ''),
                'valor'   => (int) ($it['valor'] ?? 0),
                'precio'  => (int) ($it['precio'] ?? 0),
                'ganador' => (int) $slot,
            ];
            return;
        }
    }
}

// ============================================================================
//  Validación de entrada
// ============================================================================

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
}

$input     = leer_input_json();
$codigo    = isset($input['codigo']) && is_string($input['codigo']) ? strtoupper(trim($input['codigo'])) : '';
$jugadorId = isset($input['jugador_id']) && is_string($input['jugador_id']) ? trim($input['jugador_id']) : '';
$accion    = isset($input['accion']) && is_string($input['accion']) ? trim($input['accion']) : '';
$incremento= isset($input['incremento']) ? (int) $input['incremento'] : INCREMENTO_PUJA;

$regexCodigo = '/^[' . CHARSET . ']{5}$/';
$regexJugador = '/^j[12]_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/';

if ($codigo === '' || !preg_match($regexCodigo, $codigo)) {
    responder(['ok' => false, 'error' => 'Código de sala inválido.'], 400);
}
if ($jugadorId === '' || !preg_match($regexJugador, $jugadorId)) {
    responder(['ok' => false, 'error' => 'jugador_id inválido.'], 400);
}
if (!in_array($accion, ['pujar', 'bajar', 'pasar_deadlock', 'asignar_rival', 'abandonar', 'emote'], true)) {
    responder(['ok' => false, 'error' => 'Acción inválida.'], 400);
}
if ($accion === 'pujar' && !in_array($incremento, [1, 3], true)) {
    responder(['ok' => false, 'error' => 'Incremento de puja inválido (usa 1 o 3).'], 400);
}
if ($accion === 'emote') {
    $emoteVal = isset($input['emote']) && is_string($input['emote']) ? $input['emote'] : '';
    if (!in_array($emoteVal, EMOTES_VALIDOS, true)) {
        responder(['ok' => false, 'error' => 'Emote inválido.'], 400);
    }
}
if ($accion === 'asignar_rival') {
    if (!isset($input['destino']) || !in_array((int) $input['destino'], [0, 1], true)) {
        responder(['ok' => false, 'error' => 'destino inválido.'], 400);
    }
    if (!isset($input['precio']) || !in_array((int) $input['precio'], [0, 1], true)) {
        responder(['ok' => false, 'error' => 'precio inválido.'], 400);
    }
}

api_guard_origen();
rl_guard($accion === 'emote' ? 'emote' : 'accion', $accion === 'emote' ? 60 : 240, 60);

// ============================================================================
//  Lógica
// ============================================================================

try {
    // Primero leemos con LOCK_SH para chequear estado y turno.
    $estado = leer_sala_bloqueado_sh($codigo);
    if ($estado === null) {
        responder(['ok' => false, 'error' => 'Sala no encontrada o expirada.'], 404);
    }
    if (($estado['estado'] ?? '') !== 'jugando') {
        responder(['ok' => false, 'error' => 'La partida no está en curso.'], 409);
    }

    $miSlot = slot_de_jugador($estado, $jugadorId);
    if ($miSlot === null) {
        responder(['ok' => false, 'error' => 'No perteneces a esta sala.'], 403);
    }

    // Bloqueo exclusivo + re-leo para evitar carrera entre el SH y el EX.
    $path = SALAS_DIR . $codigo . '.json';
    $fp = fopen($path, 'c+b');
    if ($fp === false) throw new RuntimeException('No se pudo abrir la sala para bloquear.');
    if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('No LOCK_EX.'); }
    $raw = stream_get_contents($fp);
    // Fichero recreado vacío (carrera con GC): sala no encontrada.
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

    // Re-validar tras el lock (pudo haber cambiado).
    if (($estado['estado'] ?? '') !== 'jugando') {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => 'La partida no está en curso.'], 409);
    }
    $miSlot = slot_de_jugador($estado, $jugadorId);
    if ($miSlot === null) {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => 'No perteneces a esta sala.'], 403);
    }

    // Compatibilidad: salas antiguas sin last_seen/abandono_por/emotes/pujas.
    if (!isset($estado['last_seen']) || !is_array($estado['last_seen'])) {
        $estado['last_seen'] = [null, null];
    }
    if (!array_key_exists('abandono_por', $estado)) {
        $estado['abandono_por'] = null;
    }
    if (!array_key_exists('revancha', $estado)) {
        $estado['revancha'] = null;
    }
    if (!isset($estado['emotes']) || !is_array($estado['emotes'])) {
        $estado['emotes'] = [];
    }
    if (is_array($estado['item_actual'])) {
        if (!isset($estado['item_actual']['pujas']) || !is_array($estado['item_actual']['pujas'])) {
            $estado['item_actual']['pujas'] = [];
        }
    }

    // 0) Acciones que no dependen del turno ni del cap. Se procesan aquí para
    //    que el handler de cap (1b) no se las trague (p. ej. salir estando capped).
    if ($accion === 'abandonar') {
        $estado['estado']         = 'abandonada';
        $estado['abandono_por']   = $miSlot;
        $estado['actualizado_en'] = time();
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);
    }
    if ($accion === 'emote') {
        $estado['emotes'][] = [
            'por'  => $miSlot,
            'code' => (string) $input['emote'],
            'ts'   => (int) round(microtime(true) * 1000),
        ];
        if (count($estado['emotes']) > EMOTES_MAX) {
            $estado['emotes'] = array_slice($estado['emotes'], -EMOTES_MAX);
        }
        $estado['actualizado_en'] = time();
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);
    }

    // Actualizar nuestro last_seen (también nos protege si el otro revisa).
    $estado['last_seen'][$miSlot] = time();

    // Si el OTRO jugador lleva > ABANDON_TIMEOUT_S sin aparecer y la partida
    // está en curso, marcamos su abandono. Los bots no pollean: se excluyen.
    $other = 1 - $miSlot;
    $otherEsBot = isset($estado['bot_slot']) && (int) $estado['bot_slot'] === $other;
    if (!$otherEsBot && !empty($estado['jugadores'][$other]['id'])) {
        $otherSeen = $estado['last_seen'][$other] ?? null;
        if ($otherSeen !== null
            && (time() - (int) $otherSeen) > ABANDON_TIMEOUT_S
        ) {
            $estado['estado'] = 'abandonada';
            $estado['abandono_por'] = $other;
            $estado['actualizado_en'] = time();
            ftruncate($fp, 0); rewind($fp);
            fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);
        }
    }

    // Necesitamos el mapa de emoji + valor de la temática para avanzar ítems
    // y para anotar el valor intrínseco de cada ítem al asignarlo.
    $meta    = cargar_tematica_meta($estado['tematica'] ?? '');
    $emojiMap = $meta['emoji'];
    $valorMap = $meta['valor'];

    // 1) Procesar auto-asignaciones pendientes ANTES de validar turno.
    //    Si al entrar ya estaba en modo forzado, el primer cliente en actuar
    //    desbloquea la cola. (La UI también podría mostrar un botón "recibir"
    //    pero por simplicidad lo resolvemos server-side en cada acción.)
    if ($estado['asignacion_forzada_a'] !== null) {
        procesar_auto_asignaciones($estado, $emojiMap, $valorMap);
    }

    // Si tras las auto-asignaciones no queda ítem, la partida terminó.
    if ($estado['item_actual'] === null) {
        $estado['actualizado_en'] = time();
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);
    }

    // 1b) Si el jugador del turno actual está en el cap, el sistema le
    //     "regala" el ítem al rival a precio=0 y avanza. Evita quedarse
    //     atascado cuando al capped le toca actuar.
    $turnoActual = $estado['item_actual']['turno_de'];
    if (count($estado['jugadores'][$turnoActual]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
        $capped = $turnoActual;
        $other  = 1 - $capped;
        $itemIdCap     = $estado['item_actual']['id'];
        $itemEmojiCap  = $estado['item_actual']['emoji'];
        $itemValorCap  = $valorMap[$itemIdCap] ?? 1;
        push_item($estado['jugadores'][$other], $itemIdCap, $itemEmojiCap, $itemValorCap, 0);
        if (count($estado['jugadores'][$other]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
            $estado['asignacion_forzada_a'] = $capped;
        }
        avanzar_a_siguiente_item($estado, $emojiMap);
        if ($estado['asignacion_forzada_a'] !== null) {
            procesar_auto_asignaciones($estado, $emojiMap, $valorMap);
        }
        if ($estado['item_actual'] === null) {
            $estado['estado'] = 'finalizada';
            registrar_ultimo_item($estado);
        }
        $estado['actualizado_en'] = time();
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);
    }

    // 2) Validar turno.
    //    Excepciones:
    //    - 'asignar_rival': el decisor pendiente puede actuar aunque no sea su turno.
    //    - 'abandonar': cualquiera puede salir en cualquier momento de la partida.
    //    - 'emote': los emotes no respetan turno.
    $turnoActual = $estado['item_actual']['turno_de'];
    $esDecisor = $accion === 'asignar_rival'
        && is_array($estado['decision_pendiente'] ?? null)
        && (int) ($estado['decision_pendiente']['para'] ?? -1) === $miSlot;
    $esAbandono = $accion === 'abandonar';
    $esEmote = $accion === 'emote';
    if ($turnoActual !== $miSlot && !$esDecisor && !$esAbandono && !$esEmote) {
        flock($fp, LOCK_UN); fclose($fp);
        responder(['ok' => false, 'error' => 'No es tu turno.'], 409);
    }

    // 3) Procesar la acción.
    if ($accion === 'pasar_deadlock') {
        // El jugador en turno, sin dinero, en un ítem fresco, cede el ítem
        // al rival para que este decida (quedárselo por 1 o regalarlo por 0).
        // Validaciones (algunas ya cubiertas por el check de turno arriba):
        if ($turnoActual !== $miSlot) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'No es tu turno para pasar.'], 409);
        }
        if ((int) $estado['item_actual']['precio_actual'] !== 0
            || $estado['item_actual']['ultimo_pujo'] !== null) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'Solo puedes pasar en un ítem fresco.'], 409);
        }
        if ((int) $estado['jugadores'][$miSlot]['dinero'] !== 0) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'Aún tienes dinero; no necesitas pasar.'], 409);
        }
        if (is_array($estado['decision_pendiente'] ?? null)) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'Ya hay una decisión pendiente.'], 409);
        }
        $estado['decision_pendiente'] = [
            'para'   => 1 - $miSlot,
            'sobre'  => $miSlot,
            'motivo' => 'sin_dinero',
        ];
    }
    else if ($accion === 'pujar') {
        // Nota: NO comprobamos cap aquí. Si un jugador ya está en cap y pujas,
        // puede pasar que termine "ganando" más ítems si el rival baja después.
        // La defensa real está en bajar(): si el ganador está capped, el ítem
        // pivota al rival a precio=0.
        //
        // Validación de presupuesto: la puja propuesta no puede superar el
        // dinero disponible. Si lo hace, devolvemos 400 sin mutar estado.
        $myDinero    = (int) $estado['jugadores'][$miSlot]['dinero'];
        $nuevoPrecio = (int) $estado['item_actual']['precio_actual'] + $incremento;
        if ($nuevoPrecio > $myDinero) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'No tienes dinero suficiente para esa puja.'], 400);
        }
        $estado['item_actual']['precio_actual'] += $incremento;
        $estado['item_actual']['ultimo_pujo']   = $miSlot;
        $estado['item_actual']['turno_de']      = 1 - $miSlot;
        $estado['item_actual']['pujas'][] = [
            'por'        => $miSlot,
            'incremento' => $incremento,
            'precio'     => (int) $estado['item_actual']['precio_actual'],
            'ts'         => time(),
        ];
    }
    else if ($accion === 'asignar_rival') {
        // El rival con monedas decide el destino del ítem cedido.
        if (!is_array($estado['decision_pendiente'] ?? null)) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'No hay decisión pendiente.'], 409);
        }
        if ((int) ($estado['decision_pendiente']['para'] ?? -1) !== $miSlot) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'No te toca decidir.'], 409);
        }

        $destino = (int) $input['destino'];
        $precio  = (int) $input['precio'];
        $sobre   = (int) $estado['decision_pendiente']['sobre'];

        // Solo hay dos decisiones legales: quedártelo tú por 1 🪙 o regalarle el
        // ítem (0 🪙) a quien lo cedió. Cualquier otra pareja es un exploit.
        $legal = ($destino === $miSlot && $precio === 1)
            || ($destino === $sobre && $precio === 0);
        if (!$legal) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'Decisión inválida.'], 400);
        }

        // Validaciones económicas: si precio=1, el ganador debe poder pagar 1.
        if ($precio === 1) {
            if ((int) $estado['jugadores'][$destino]['dinero'] < 1) {
                flock($fp, LOCK_UN); fclose($fp);
                responder(['ok' => false, 'error' => 'No hay dinero suficiente para ese precio.'], 400);
            }
        }

        $itemId    = $estado['item_actual']['id'];
        $itemEmoji = $estado['item_actual']['emoji'];
        $itemValor = $valorMap[$itemId] ?? 1;

        // Cap pivot: si el destino ya está en el cap, el ítem va al otro.
        if (count($estado['jugadores'][$destino]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
            $destino = 1 - $destino;
            $precio  = 0;
        }

        $estado['jugadores'][$destino]['dinero'] -= $precio;
        push_item($estado['jugadores'][$destino], $itemId, $itemEmoji, $itemValor, $precio);

        // Cap cascade: si llega al cap, forzar al rival los restantes.
        if (count($estado['jugadores'][$destino]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
            $estado['asignacion_forzada_a'] = 1 - $destino;
        }

        // Limpiar decisión pendiente.
        $estado['decision_pendiente'] = null;

        // Avanzar ítem.
        avanzar_a_siguiente_item($estado, $emojiMap);

        if ($estado['asignacion_forzada_a'] !== null) {
            procesar_auto_asignaciones($estado, $emojiMap, $valorMap);
        }
        if ($estado['item_actual'] === null) {
            $estado['estado'] = 'finalizada';
            registrar_ultimo_item($estado);
        }
    }
    else { // bajar
        $ultimo = $estado['item_actual']['ultimo_pujo'] ?? null;
        if ($ultimo === null) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'Primero alguien debe pujar.'], 400);
        }
        $winner = (int) $ultimo;
        $price  = (int) $estado['item_actual']['precio_actual'];

        // Si el ganador (último pujador) ya tiene el cap, el ítem va al rival
        // a precio=0 (validación previa; el helper lo re-aplica de forma idempotente).
        if (count($estado['jugadores'][$winner]['items_ganados']) >= MAX_ITEMS_POR_JUGADOR) {
            $winner = 1 - $winner;
            $price  = 0;
        }

        if ((int) $estado['jugadores'][$winner]['dinero'] < $price) {
            flock($fp, LOCK_UN); fclose($fp);
            responder(['ok' => false, 'error' => 'No tienes dinero suficiente para pagar.'], 400);
        }

        asignar_item_ganador($estado, $winner, $price, $emojiMap, $valorMap);
    }

    $estado['actualizado_en'] = time();

    // Persistir y responder.
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);

    responder(['ok' => true, 'sala' => sala_publica($estado, $miSlot)], 200);

} catch (RuntimeException $e) {
    if (isset($fp) && is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); }
    responder(['ok' => false, 'error' => $e->getMessage()], 500);
}