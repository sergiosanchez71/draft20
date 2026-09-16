/* Draft 20 - Política del bot de práctica.
 *
 * Función pura y testeable (sin DOM): decide la acción del bot a partir del
 * estado de la sala. Dificultades (calibradas por win rate; ver README):
 *   - facil:   intuición muy ruidosa (±8), puja poco y se retira pronto.
 *   - normal:  intuición (±2) y presupuesto equilibrado; partida pareja.
 *   - dificil: valores reales y reparto racional del presupuesto moderado.
 *   - extremo: valores reales, reparto agresivo y selectivo por los mejores.
 * Con "⭐ Valores visibles" todos los niveles ven los valores reales (el
 * humano también), salvo los ajustes `visible` de cada nivel.
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
        facil:   { usaValorReal: false, intuida: true, ruido: 8, visible: { usaValorReal: false }, factor: 0.50, retirada: 0.40, delayMs: [900, 1600], inc3: 0.10, deadlockMinDinero: 3, deadlockMinVal: 8, reserva: true },
        normal:  { usaValorReal: false, intuida: true, factor: 1.30, retirada: 0.12, delayMs: [700, 1800], inc3: 0.30, deadlockMinDinero: 2, deadlockMinVal: 5, reserva: true },
        dificil: { usaValorReal: true,  factor: 1.15, retirada: 0.05, delayMs: [500, 1200], inc3: 0.50, deadlockMinDinero: 1, deadlockMinVal: 4, reserva: false, racional: true, visible: { factor: 1.25 } },
        extremo: { usaValorReal: true,  factor: 2.00, retirada: 0,    delayMs: [350, 900],  inc3: 0.70, deadlockMinDinero: 1, deadlockMinVal: 1, racional: true },
    };

    function hashId(id) {
        let h = 5381;
        for (let i = 0; i < id.length; i++) h = (((h << 5) + h) ^ id.charCodeAt(i)) >>> 0;
        return h;
    }

    // Valoración "intuida" (2..10) sin conocer el valor real del ítem.
    function valoracionSecreta(id) { return 2 + (hashId(id) % 9); }

    function valorEfectivo(itemId, cfg, valorReal) {
        if (valorReal && valorReal[itemId] != null) {
            const v = Number(valorReal[itemId]);
            if (v >= 1 && v <= 10) {
                if (cfg.usaValorReal) return v;
                // Intuición: conoce el valor con ruido determinista (±ruido).
                if (cfg.intuida) {
                    const r = cfg.ruido || 2;
                    return Math.max(1, Math.min(10, v + (hashId('i' + itemId) % (2 * r + 1)) - r));
                }
            }
        }
        return valoracionSecreta(itemId);
    }

    function config(dificultad) { return CONFIG[dificultad] || CONFIG.normal; }

    /**
     * Config efectiva de la partida: con "⭐ Valores visibles" el humano ve los
     * valores reales, así que todos los niveles juegan con ellos (y aplican sus
     * ajustes `visible`). Sin ese modo, cada nivel mantiene su información.
     */
    function configPartida(dificultad, sala) {
        const base = config(dificultad);
        if (!sala || !sala.mostrar_valores) return base;
        const vis = base.visible;
        if (!vis && base.usaValorReal) return base;
        const out = {};
        for (const k in base) out[k] = base[k];
        if (vis) { for (const k in vis) out[k] = vis[k]; }
        if (!vis || vis.usaValorReal !== false) out.usaValorReal = true;
        return out;
    }

    /**
     * Presupuesto racional de "extremo":
     *   - Solo se pelea por los "cupo" ítems de mayor valor que quedan
     *     (incluido el actual); por los mediocres puja el mínimo y se retira.
     *   - Para un objetivo reparte el dinero en proporción a su valor frente
     *     a la suma de los mejores ítems restantes, con un factor de agresión
     *     (el dinero sobrante al final no da puntos) y reservando 1 🪙 por
     *     cada hueco futuro (salvo si es el último que necesita).
     *   - En el último hueco, si aún quedan ítems, no sobrepuja: este ítem
     *     solo aporta su valor frente al mejor de los que quedan.
     */
    function maxPujaRacional(sala, cfg, itemId, val, dinero, cupo, valorReal) {
        const ids = Array.isArray(sala.items_mezclados) ? sala.items_mezclados : null;
        if (cupo <= 1 && ids && (sala.indice_item || 0) + 1 < ids.length) {
            let bestRest = 0;
            for (let i = (sala.indice_item || 0) + 1; i < ids.length; i++) {
                const fv = valorEfectivo(ids[i], cfg, valorReal);
                if (fv > bestRest) bestRest = fv;
            }
            return Math.max(1, Math.min(val - bestRest, dinero));
        }
        let suma = val;
        const futuros = [];
        if (ids && cupo > 1) {
            for (let i = Math.max(0, (sala.indice_item || 0) + 1); i < ids.length; i++) {
                if (ids[i] !== itemId) futuros.push(valorEfectivo(ids[i], cfg, valorReal));
            }
            futuros.sort(function (a, b) { return b - a; });
            for (let i = 0; i < cupo - 1 && i < futuros.length; i++) suma += futuros[i];
            const kth = futuros[Math.min(cupo - 2, futuros.length - 1)] || 0;
            if (kth > 0 && val < kth) return 1;
        }
        let maxPuja = Math.round(dinero * (val / Math.max(1, suma)) * cfg.factor);
        maxPuja = Math.max(1, Math.min(maxPuja, dinero));
        if (cupo > 1) maxPuja = Math.min(maxPuja, Math.max(1, dinero - (cupo - 1)));
        return Math.max(1, Math.min(maxPuja, dinero));
    }

    /**
     * Acción de respaldo segura: garantiza que el bot SIEMPRE responda en su
     * turno (o como decisor) aunque `decidir` devuelva null por un estado raro.
     */
    function respaldo(sala, botSlot) {
        const item = sala && sala.item_actual;
        if (!sala || sala.estado !== 'jugando' || !item) return null;
        const yo = sala.jugadores && sala.jugadores[botSlot];
        if (!yo) return null;
        const dinero = yo.dinero || 0;
        const misItems = (yo.items_ganados || []).length;

        if (sala.decision_pendiente) {
            if (sala.decision_pendiente.para !== botSlot) return null;
            // Regala (0 🪙) para no romper el deadlock.
            return { accion: 'asignar_rival', destino: sala.decision_pendiente.sobre, precio: 0 };
        }
        if (item.turno_de !== botSlot) return null;
        if (misItems >= 4) {
            return { accion: (item.ultimo_pujo !== null && item.ultimo_pujo !== undefined) ? 'bajar' : 'pasar_deadlock' };
        }
        const hayPuja = item.ultimo_pujo !== null && item.ultimo_pujo !== undefined;
        if (hayPuja) return { accion: 'bajar' };
        if (dinero <= 0) return { accion: 'pasar_deadlock' };
        return { accion: 'pujar', incremento: 1 };
    }

    /**
     * Clave de la situación de juego. El bot no re-evalúa mientras la clave no
     * cambie; por eso incluye el estado de decision_pendiente (si no, el bot se
     * quedaría colgado cuando el humano le pasa el ítem sin cambiar el resto).
     */
    function estadoKey(sala) {
        const item = sala && sala.item_actual;
        if (!sala || !item) return 'none';
        const dp = sala.decision_pendiente;
        const dpKey = (dp && typeof dp === 'object')
            ? 'D' + (dp.para === undefined ? '?' : dp.para) + '_' + (dp.sobre === undefined ? '?' : dp.sobre)
            : 'D-';
        const pujas = Array.isArray(item.pujas) ? item.pujas.length : 0;
        return [sala.ronda, item.id, item.precio_actual, item.turno_de, pujas, dpKey].join(':');
    }

    /**
     * Decide la acción del bot.
     * @param {object} sala      - estado completo de la sala
     * @param {number} botSlot   - 0 | 1
     * @param {string} dificultad
     * @param {function} rng     - generador aleatorio [0,1) (inyectable en tests)
     * @param {object|null} valorReal - mapa id->valor (dificultades con usaValorReal)
     * @returns {{accion:string, incremento?:number, destino?:number, precio?:number}|null}
     */
    function decidir(sala, botSlot, dificultad, rng, valorReal) {
        rng = rng || Math.random;
        const cfg = configPartida(dificultad, sala);
        const item = sala && sala.item_actual;
        if (!sala || sala.estado !== 'jugando' || !item) return null;

        const yo = sala.jugadores && sala.jugadores[botSlot];
        if (!yo) return null;
        const dinero = yo.dinero || 0;
        const misItems = (yo.items_ganados || []).length;

        // Decisión pendiente de deadlock: solo actúa el decisor; el resto espera.
        if (sala.decision_pendiente) {
            if (sala.decision_pendiente.para !== botSlot) return null;
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
        let maxPuja;
        if (cfg.racional) {
            maxPuja = maxPujaRacional(sala, cfg, item.id, val, dinero, cupo, valorReal);
        } else {
            const medio = Math.floor(dinero / cupo);
            maxPuja = Math.round(medio * (val / 6) * cfg.factor);
            maxPuja = Math.max(1, Math.min(maxPuja, dinero));
            if (cfg.reserva) {
                // Reserva al menos 1 moneda por ronda restante.
                maxPuja = Math.min(maxPuja, Math.max(1, dinero - (cupo - 1)));
            }
            if (maxPuja < 1) maxPuja = 1;
        }

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

    return { decidir: decidir, respaldo: respaldo, config: config, configPartida: configPartida, valoracionSecreta: valoracionSecreta, estadoKey: estadoKey };
}));
