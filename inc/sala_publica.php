<?php
/**
 * Draft 20 — Vista pública de una sala.
 *
 * Las respuestas de la API nunca deben exponer:
 *  - `jugadores[].id`: es el token de autenticación de cada jugador.
 *  - `last_seen`: metadato interno de presencia.
 *  - En partidas entre humanos, ni la lista de ítems futuros ni sus valores
 *    (el bot local sí los necesita, así que en salas de bot se mantienen).
 *
 * Devuelve una copia con `mi_slot` y `total_items` para el cliente.
 */
declare(strict_types=1);

if (!function_exists('sala_publica')) {
    function sala_publica(array $sala, ?int $miSlot): array
    {
        if (isset($sala['jugadores']) && is_array($sala['jugadores'])) {
            foreach ($sala['jugadores'] as $i => $j) {
                if (is_array($j)) {
                    unset($sala['jugadores'][$i]['id']);
                }
            }
        }
        unset($sala['last_seen']);

        // Temática sorpresa: se oculta mientras la partida está en curso.
        if (!empty($sala['ocultar_tematica'])
            && in_array((string) ($sala['estado'] ?? ''), ['esperando', 'jugando'], true)
            && isset($sala['tematica'])) {
            unset($sala['tematica']);
            $sala['tematica_oculta'] = true;
        }

        $esBot = isset($sala['bot_slot']) && $sala['bot_slot'] !== null;
        $sala['total_items'] = is_array($sala['items_mezclados'] ?? null)
            ? count($sala['items_mezclados'])
            : 8;

        // La lista de ítems futuros solo la necesitan las salas de bot (el bot
        // local juega con ella). Los valores los decide el API que la construye
        // (solo los inyecta con el modo activo o en partidas contra bot).
        if (!$esBot) {
            unset($sala['items_mezclados']);
        }

        $sala['mi_slot'] = $miSlot;
        return $sala;
    }
}
