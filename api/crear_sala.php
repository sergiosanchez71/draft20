<?php
/**
 * Draft 20 — API: crear_sala
 *
 * Crea una sala nueva, genera un código único de 5 caracteres,
 * baraja los ítems de la temática elegida, hace slice a ITEMS_POR_PARTIDA (8)
 * y la deja en estado "esperando" con el Jugador 1 ya inicializado.
 *
 * Sistema i18n: el archivo de temática contiene SOLO {id, emoji}.
 * El nombre visible de cada ítem lo provee el frontend cruzando el id
 * con /lang/es.json. La sala se guarda por id (agnóstico de idioma).
 *
 * Cap por jugador = MAX_ITEMS_POR_JUGADOR (4). Si un jugador llega al cap,
 * los ítems restantes se asignan automáticamente al rival a precio=0
 * (campo `asignacion_forzada_a` en la sala). La mecánica concreta
 * vive en api/accion.php (Fase 2).
 *
 * --- Petición ---
 *   POST  application/json
 *   Body: { "tematica": "hamburguesa", "nombre": "Opcional" }
 *
 * --- Respuesta 200 ---
 *   { "ok": true, "codigo": "A8F3X", "jugador_id": "j1_<uuid>" }
 *
 * --- Respuestas de error ---
 *   400 → parámetros inválidos
 *   405 → método incorrecto
 *   500 → error interno (p. ej. filesystem)
 */

declare(strict_types=1);

// ============================================================================
//  Config & constantes
// ============================================================================

// Charset sin 0, O, I, 1, L → evita confusión visual en móvil.
const CHARSET               = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const SALAS_DIR             = __DIR__ . '/salas/';
const TEMATICAS_DIR         = __DIR__ . '/../tematicas/';
const DINERO_INICIAL        = 20;
const MAX_INTENTOS_CODIGO   = 25;

// Mecánica de la partida: 4 ítems por persona (cap duro).
const ITEMS_POR_PARTIDA     = 8;   // = 2 × MAX_ITEMS_POR_JUGADOR (4 por jugador)
const MAX_ITEMS_POR_JUGADOR = 4;   // tope duro; al llegar, el rival recibe los restantes a precio=0

// ============================================================================
//  Helpers (con guard function_exists por si se carga junto a estado.php)
// ============================================================================

if (!function_exists('responder')) {
    /**
     * Emite una respuesta JSON y termina la ejecución.
     */
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

if (!function_exists('leer_input_json')) {
    /**
     * Lee el body de la petición y lo decodifica como array.
     */
    function leer_input_json(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('uuid_v4')) {
    /**
     * Genera un UUID v4 conforme a RFC 4122 usando random_bytes (CSPRNG).
     */
    function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // versión 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variante RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

if (!function_exists('generar_codigo_unico')) {
    /**
     * Genera un código de $longitud caracteres que NO exista ya como sala.
     */
    function generar_codigo_unico(int $longitud = 5): string {
        $charset = CHARSET;
        $max = strlen($charset) - 1;

        for ($i = 0; $i < MAX_INTENTOS_CODIGO; $i++) {
            $codigo = '';
            for ($j = 0; $j < $longitud; $j++) {
                $codigo .= $charset[random_int(0, $max)];
            }
            if (!file_exists(SALAS_DIR . $codigo . '.json')) {
                return $codigo;
            }
        }
        throw new RuntimeException(
            'No se pudo generar un código único tras ' . MAX_INTENTOS_CODIGO . ' intentos.'
        );
    }
}

if (!function_exists('cargar_tematica')) {
    /**
     * Carga una temática desde /tematicas/<id>.json de forma segura.
     * Valida el id para evitar path traversal y verifica que cada ítem
     * tenga {id (slug regex), emoji (no vacío)}. Los nombres de los ítems
     * NO viven aquí: el sistema i18n los resuelve en frontend desde
     * /lang/es.json cruzando por id.
     */
    function cargar_tematica(string $id): array {
        if (!preg_match('/^[a-z0-9_]+$/', $id)) {
            throw new InvalidArgumentException('Identificador de temática inválido.');
        }
        $path = TEMATICAS_DIR . $id . '.json';
        if (!is_file($path)) {
            throw new InvalidArgumentException('Temática no encontrada: ' . $id);
        }
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new RuntimeException('No se pudo abrir la temática.');
        }
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            throw new RuntimeException('No se pudo bloquear la temática para lectura.');
        }
        $raw  = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($raw, true);
        if (!is_array($data)
            || !isset($data['id']) || !is_string($data['id']) || $data['id'] === ''
            || !isset($data['items']) || !is_array($data['items'])
            || count($data['items']) < ITEMS_POR_PARTIDA
        ) {
            throw new RuntimeException('Temática malformada.');
        }

        // Validar cada ítem: {id (slug), emoji (no vacío)} + unicidad de id.
        $idsVistos = [];
        foreach ($data['items'] as $idx => $it) {
            if (!is_array($it)
                || !isset($it['id']) || !is_string($it['id']) || !preg_match('/^[a-z0-9_]+$/', $it['id'])
                || !isset($it['emoji']) || !is_string($it['emoji']) || $it['emoji'] === ''
            ) {
                throw new RuntimeException('Temática malformada: ítem #' . $idx . ' inválido.');
            }
            if (isset($idsVistos[$it['id']])) {
                throw new RuntimeException('Temática malformada: id duplicado "' . $it['id'] . '".');
            }
            $idsVistos[$it['id']] = true;
        }

        return $data;
    }
}

if (!function_exists('barajar')) {
    /**
     * Baraja un array en su lugar (Fisher–Yates interno de shuffle()).
     */
    function barajar(array $items): array {
        shuffle($items);
        return $items;
    }
}

if (!function_exists('escribir_sala_bloqueado')) {
    /**
     * Escribe el JSON de una sala con lock exclusivo (LOCK_EX).
     * Garantiza atomicidad frente a lecturas/escrituras concurrentes.
     */
    function escribir_sala_bloqueado(string $codigo, array $data): void {
        $path = SALAS_DIR . $codigo . '.json';
        $fp = fopen($path, 'c+b');
        if ($fp === false) {
            throw new RuntimeException('No se pudo abrir la sala para escritura.');
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('No se pudo bloquear la sala (LOCK_EX).');
        }
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ============================================================================
//  Validación de entrada
// ============================================================================

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
}

$input      = leer_input_json();
$tematicaId = isset($input['tematica']) ? (string) $input['tematica'] : '';
$nombreJ1   = isset($input['nombre'])   ? trim((string) $input['nombre']) : '';

if ($tematicaId === '') {
    responder(['ok' => false, 'error' => 'Falta el campo "tematica".'], 400);
}
if (strlen($nombreJ1) > 20) {
    responder(['ok' => false, 'error' => 'Nombre demasiado largo (máx 20 caracteres).'], 400);
}

// ============================================================================
//  Lógica: crear sala
// ============================================================================

try {
    $tematica = cargar_tematica($tematicaId);

    // Barajamos los ítems y hacemos slice a ITEMS_POR_PARTIDA (8 = 4 por persona).
    // Guardamos SOLO los IDs en la sala → agnóstico de idioma (i18n).
    $itemsBarajados = barajar($tematica['items']);
    $itemsPool      = array_slice($itemsBarajados, 0, ITEMS_POR_PARTIDA);
    $itemsMezclados = array_map(
        static fn(array $it): string => (string) $it['id'],
        $itemsPool
    );

    $primerItem = $itemsPool[0];
    $codigo     = generar_codigo_unico(5);
    $jugadorId  = 'j1_' . uuid_v4();
    $ahora      = time();

    $estado = [
        'codigo'              => $codigo,
        'estado'              => 'esperando',     // esperando | jugando | finalizada
        'tematica'            => $tematica['id'],
        'items_mezclados'     => $itemsMezclados, // 8 IDs barajados (no nombres)
        'indice_item'         => 0,
        'item_actual'         => [
            'id'            => $primerItem['id'],
            'emoji'         => $primerItem['emoji'],
            'precio_actual' => 0,          // precio base de salida de cada puja
            'turno_de'      => 0,          // 0 = J1, 1 = J2
            'ultimo_pujo'   => null,       // índice del último jugador que pujó (null al inicio)
            'auto_asignado' => false,      // true cuando se asigna sin puja (rival ya completó cap)
        ],
        'jugadores' => [
            [
                'id'             => $jugadorId,
                'nombre'         => $nombreJ1 !== '' ? $nombreJ1 : 'Jugador 1',
                'dinero'         => DINERO_INICIAL,
                'items_ganados'  => [],      // se rellena en api/accion.php con {id, emoji}
            ],
            // Slot J2: lo rellena api/unirse_sala.php (Fase 2).
            [
                'id'             => null,
                'nombre'         => 'Jugador 2',
                'dinero'         => DINERO_INICIAL,
                'items_ganados'  => [],
            ],
        ],
        'turno_inicial_ronda' => 0, // J1 empieza la primera ronda
        'ronda'               => 1,
        'asignacion_forzada_a' => null, // 0 | 1 cuando un jugador llega al cap; el otro recibe el resto
        'decision_pendiente'   => null, // {para, sobre, motivo} cuando un jugador sin dinero cede el ítem al rival
        'last_seen'            => [null, null], // UNIX ts por slot; el que tenga last_seen[other] > ABANDON_TIMEOUT_S se da por abandonado
        'abandono_por'         => null, // 0 | 1 cuando un jugador abandona (explícito o por timeout)
        'creado_en'           => $ahora,
        'actualizado_en'      => $ahora,
    ];

    escribir_sala_bloqueado($codigo, $estado);

    responder([
        'ok'         => true,
        'codigo'     => $codigo,
        'jugador_id' => $jugadorId,
    ], 200);

} catch (InvalidArgumentException $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 500);
}
