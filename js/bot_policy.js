/* Draft 20 - Política del bot de práctica.
 *
 * Función pura y testeable (sin DOM): decide la acción del bot a partir del
 * estado de la sala. Dificultades:
 *   - facil:   sin valoración, puja poco y se retira pronto.
 *   - normal:  valoración secreta (hash del id), presupuesto equilibrado.
 *   - dificil: conoce los valores reales (se los pasa el caller) y es agresivo.
 *
 * Se carga como script normal (window.DraftBot) y también como módulo Node
 * para los tests (module.exports).
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.DraftBot = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const CONFIG = {
        facil:   { usaValorReal: false, factor: 0.60, retirada: 0.40, delayMs: [900, 1600], inc3: 0.10, deadlockMinDinero: 3, deadlockMinVal: 8 },
        normal:  { usaValorReal: false, factor: 1.20, retirada: 0.12, delayMs: [700, 1800], inc3: 0.30, deadlockMinDinero: 2, deadlockMinVal: 5 },
        dificil: { usaValorReal: true,  factor: 1.50, retirada: 0.05, delayMs: [500, 1200], inc3: 0.50, deadlockMinDinero: 1, deadlockMinVal: 4 },
    };

    function hashId(id) {
        let h = 5381;
        for (let i = 0; i < id.length; i++) h = (((h << 5) + h) ^ id.charCodeAt(i)) >>> 0;
        return h;
    }

    // Valoración "intuida" (2..10) sin conocer el valor real del ítem.
    function valoracionSecreta(id) { return 2 + (hashId(id) % 9); }

    function valorEfectivo(itemId, cfg, valorReal) {
        if (cfg.usaValorReal && valorReal && valorReal[itemId] != null) {
            const v = Number(valorReal[itemId]);
            if (v >= 1 && v <= 10) return v;
        }
        return valoracionSecreta(itemId);
    }

    function config(dificultad) { return CONFIG[dificultad] || CONFIG.normal; }

    /**
     * Decide la acción del bot.
     * @param {object} sala      - estado completo de la sala
     * @param {number} botSlot   - 0 | 1
     * @param {string} dificultad
     * @param {function} rng     - generador aleatorio [0,1) (inyectable en tests)
     * @param {object|null} valorReal - mapa id->valor (solo para 'dificil')
     * @returns {{accion:string, incremento?:number, destino?:number, precio?:number}|null}
     */
    function decidir(sala, botSlot, dificultad, rng, valorReal) {
        rng = rng || Math.random;
        const cfg = config(dificultad);
        const item = sala && sala.item_actual;
        if (!sala || sala.estado !== 'jugando' || !item) return null;

        const yo = sala.jugadores && sala.jugadores[botSlot];
        if (!yo) return null;
        const dinero = yo.dinero || 0;
        const misItems = (yo.items_ganados || []).length;

        // Decisión pendiente de deadlock: quedárselo por 1 o regalarlo.
        if (sala.decision_pendiente && sala.decision_pendiente.para === botSlot) {
            const val = valorEfectivo(item.id, cfg, valorReal);
            if (dinero >= cfg.deadlockMinDinero && val >= cfg.deadlockMinVal) {
                return { accion: 'asignar_rival', destino: botSlot, precio: 1 };
            }
            return { accion: 'asignar_rival', destino: sala.decision_pendiente.sobre, precio: 0 };
        }

        if (item.turno_de !== botSlot) return null;

        // Capped: cualquier acción dispara el cap-handler del server (ítem al rival a 0).
        if (misItems >= 4) {
            return { accion: (item.ultimo_pujo !== null && item.ultimo_pujo !== undefined) ? 'bajar' : 'pasar_deadlock' };
        }

        // Sin dinero en un ítem fresco: pasar (deadlock).
        if (dinero <= 0 && (item.precio_actual || 0) === 0 && item.ultimo_pujo === null) {
            return { accion: 'pasar_deadlock' };
        }

        const val = valorEfectivo(item.id, cfg, valorReal);
        const cupo = Math.max(1, 4 - misItems);
        const medio = Math.floor(dinero / cupo);
        let maxPuja = Math.round(medio * (val / 6) * cfg.factor);
        maxPuja = Math.max(1, Math.min(maxPuja, dinero));
        if (dificultad !== 'dificil') {
            // Reserva al menos 1 moneda por ronda restante.
            maxPuja = Math.min(maxPuja, Math.max(1, dinero - (cupo - 1)));
        }
        if (maxPuja < 1) maxPuja = 1;

        const pujas = Array.isArray(item.pujas) ? item.pujas.length : 0;
        const inc = (rng() < cfg.inc3) ? 3 : 1;
        const precio = item.precio_actual || 0;
        const puede = function (n) { return precio + n <= Math.min(maxPuja, dinero); };

        const hayPuja = item.ultimo_pujo !== null && item.ultimo_pujo !== undefined;
        const ultimoEsMio = item.ultimo_pujo === botSlot;

        if (hayPuja && !ultimoEsMio) {
            // Retirada anticipada (no determinista) para no ser predecible.
            if (pujas >= 1 && rng() < cfg.retirada) return { accion: 'bajar' };
            if (puede(inc)) return { accion: 'pujar', incremento: inc };
            if (puede(1)) return { accion: 'pujar', incremento: 1 };
            return { accion: 'bajar' };
        }

        // Caso raro: el turno apunta al bot pero la última puja es suya.
        if (hayPuja && ultimoEsMio) return { accion: 'bajar' };

        // Ítem fresco: abrir la puja (a veces +3 si el ítem le encanta).
        if (puede(1)) {
            const apertura = (val >= 8 && puede(3) && rng() < 0.6) ? 3 : 1;
            return { accion: 'pujar', incremento: apertura };
        }
        if (dinero <= 0) return { accion: 'pasar_deadlock' };
        return { accion: 'bajar' };
    }

    return { decidir: decidir, config: config, valoracionSecreta: valoracionSecreta };
}));
