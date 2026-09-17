/**
 * Tests de la política del bot (js/bot_policy.js). Sin DOM ni navegador.
 * Uso: node test_bot.js
 */
const Bot = require('../js/bot_policy.js');

let ok = 0, fail = 0;
function ass(esperado, actual, msg) {
    const e = JSON.stringify(esperado), a = JSON.stringify(actual);
    if (e === a) { ok++; return; }
    fail++;
    console.log('  FAIL ' + msg + ': esperado=' + e + ' actual=' + a);
}
function seq(valores) {
    let i = 0;
    return function () { return valores[Math.min(i++, valores.length - 1)]; };
}
function sala(over) {
    return Object.assign({
        estado: 'jugando',
        decision_pendiente: null,
        tematica: 'test',
        item_actual: { id: 'test_item', turno_de: 1, precio_actual: 0, ultimo_pujo: null, pujas: [] },
        jugadores: [
            { dinero: 20, items_ganados: [] },
            { dinero: 20, items_ganados: [] },
        ],
    }, over);
}
function itemCon(over) {
    return Object.assign({ id: 'test_item', turno_de: 1, precio_actual: 0, ultimo_pujo: null, pujas: [] }, over);
}

// --- fácil: abre la puja con +1 ---
ass({ accion: 'pujar', incremento: 1 }, Bot.decidir(sala({}), 1, 'facil', seq([0.99, 0.99])), 'facil abre con +1');

// --- fácil: se retira pronto (40%) ---
ass({ accion: 'bajar' }, Bot.decidir(sala({ item_actual: itemCon({ ultimo_pujo: 0, precio_actual: 1, pujas: [{ por: 0, incremento: 1, precio: 1 }] }) }), 1, 'facil', seq([0.99, 0.0])), 'facil se retira por probabilidad');

// --- fácil: no llega a su máximo (precio 3), con un id de valoración baja ---
let idBajoFacil = null;
for (let n = 0; n < 500 && !idBajoFacil; n++) {
    const id = 'item_' + n;
    if (Bot.valoracionSecreta(id) <= 4) idBajoFacil = id;
}
ass(true, !!idBajoFacil, 'encontrado id con valoración baja para el test');
ass({ accion: 'bajar' }, Bot.decidir(sala({ item_actual: itemCon({ id: idBajoFacil, ultimo_pujo: 0, precio_actual: 3, pujas: [{ por: 0, incremento: 3, precio: 3 }] }) }), 1, 'facil', seq([0.99, 0.99, 0.99, 0.99])), 'facil se baja si el precio supera su presupuesto');

// --- normal: contrapuja al humano ---
ass({ accion: 'pujar', incremento: 1 }, Bot.decidir(sala({ item_actual: itemCon({ ultimo_pujo: 0, precio_actual: 1, pujas: [{ por: 0, incremento: 1, precio: 1 }] }) }), 1, 'normal', seq([0.99, 0.99, 0.99])), 'normal contrapuja +1');

// --- normal: se retira cuando el precio supera su máximo ---
ass({ accion: 'bajar' }, Bot.decidir(sala({ item_actual: itemCon({ ultimo_pujo: 0, precio_actual: 18, pujas: [{ por: 0, incremento: 3, precio: 18 }] }) }), 1, 'normal', seq([0.99, 0.99, 0.99])), 'normal se baja si ya no puede pagar su máximo');

// --- normal: reserva 1 moneda por ronda restante (dinero 4, 4 ítems por ganar) ---
// medio = 1, maxPuja = min(round(1*(val/6)*1.2), 4-3=1) = 1 → a precio 1, ya no puede
ass({ accion: 'bajar' }, Bot.decidir(sala({ jugadores: [{ dinero: 4, items_ganados: [] }, { dinero: 4, items_ganados: [] }], item_actual: itemCon({ ultimo_pujo: 0, precio_actual: 1, pujas: [{ por: 0, incremento: 1, precio: 1 }] }) }), 1, 'normal', seq([0.99, 0.99, 0.99])), 'normal reserva monedas para las siguientes rondas');

// --- difícil: usa el valor real y abre con +3 si el ítem vale 10 ---
ass({ accion: 'pujar', incremento: 3 }, Bot.decidir(sala({}), 1, 'dificil', seq([0.99, 0.0]), { test_item: 10 }), 'dificil abre +3 con ítem top');

// --- difícil: con valor real 1 puja lo mínimo ---
ass({ accion: 'pujar', incremento: 1 }, Bot.decidir(sala({}), 1, 'dificil', seq([0.99, 0.99]), { test_item: 1 }), 'dificil puja +1 con ítem malo');

// --- deadlock: difícil con ítem valioso se lo queda por 1 ---
ass({ accion: 'asignar_rival', destino: 1, precio: 1 }, Bot.decidir(sala({ decision_pendiente: { para: 1, sobre: 0 }, item_actual: itemCon({}) }), 1, 'dificil', seq([0.5]), { test_item: 9 }), 'dificil se queda ítem valioso por 1');

// --- deadlock: difícil con ítem malo lo regala ---
ass({ accion: 'asignar_rival', destino: 0, precio: 0 }, Bot.decidir(sala({ decision_pendiente: { para: 1, sobre: 0 }, item_actual: itemCon({}) }), 1, 'dificil', seq([0.5]), { test_item: 1 }), 'dificil regala ítem malo');

// --- deadlock: fácil con poco dinero regala ---
let idMalo = null;
for (let n = 0; n < 200 && !idMalo; n++) {
    const id = 'item_' + n;
    if (Bot.valoracionSecreta(id) < 8) idMalo = id;
}
ass({ accion: 'asignar_rival', destino: 0, precio: 0 }, Bot.decidir(sala({ decision_pendiente: { para: 1, sobre: 0 }, item_actual: itemCon({ id: idMalo }) }), 1, 'facil', seq([0.5])), 'facil regala en deadlock con valoración baja');

// --- cap: con 4 ítems se baja si hay puja ---
ass({ accion: 'bajar' }, Bot.decidir(sala({ jugadores: [{ dinero: 20, items_ganados: [] }, { dinero: 8, items_ganados: [1, 2, 3, 4] }], item_actual: itemCon({ ultimo_pujo: 0, precio_actual: 2, pujas: [{ por: 0, incremento: 2, precio: 2 }] }) }), 1, 'normal', seq([0.5])), 'capped: baja si hay puja');

// --- cap: con 4 ítems y sin puja, pasa ---
ass({ accion: 'pasar_deadlock' }, Bot.decidir(sala({ jugadores: [{ dinero: 20, items_ganados: [] }, { dinero: 8, items_ganados: [1, 2, 3, 4] }] }), 1, 'normal', seq([0.5])), 'capped: pasa si no hay puja');

// --- sin dinero en ítem fresco: pasar_deadlock ---
ass({ accion: 'pasar_deadlock' }, Bot.decidir(sala({ jugadores: [{ dinero: 20, items_ganados: [] }, { dinero: 0, items_ganados: [] }] }), 1, 'normal', seq([0.5])), 'sin dinero: pasa');

// --- no es su turno: null ---
ass(null, Bot.decidir(sala({ item_actual: itemCon({ turno_de: 0 }) }), 1, 'normal', seq([0.5])), 'si no es su turno no decide');

// --- sala no jugando: null ---
ass(null, Bot.decidir(sala({ estado: 'finalizada' }), 1, 'normal', seq([0.5])), 'sala finalizada: no decide');

// --- decisión pendiente que NO es del bot: no actúa (evita 409 en bucle) ---
ass(null, Bot.decidir(sala({ decision_pendiente: { para: 0, sobre: 1 }, jugadores: [{ dinero: 0, items_ganados: [] }, { dinero: 0, items_ganados: [] }] }), 1, 'normal', seq([0.5])), 'decisión de otro: el bot espera');

// --- respaldo: garantiza acción legal en su turno ---
ass({ accion: 'bajar' }, Bot.respaldo(sala({ item_actual: itemCon({ ultimo_pujo: 0, precio_actual: 3, pujas: [{ por: 0, incremento: 3, precio: 3 }] }) }), 1), 'respaldo con puja: baja');
ass({ accion: 'pujar', incremento: 1 }, Bot.respaldo(sala({}), 1), 'respaldo sin puja: puja +1');
ass({ accion: 'pasar_deadlock' }, Bot.respaldo(sala({ jugadores: [{ dinero: 20, items_ganados: [] }, { dinero: 0, items_ganados: [] }] }), 1), 'respaldo sin dinero: pasa');
ass({ accion: 'asignar_rival', destino: 0, precio: 0 }, Bot.respaldo(sala({ decision_pendiente: { para: 1, sobre: 0 } }), 1), 'respaldo decisor: regala');
ass(null, Bot.respaldo(sala({ decision_pendiente: { para: 0, sobre: 1 } }), 1), 'respaldo decisor ajeno: null');
ass(null, Bot.respaldo(sala({ item_actual: itemCon({ turno_de: 0 }) }), 1), 'respaldo fuera de turno: null');
ass({ accion: 'bajar' }, Bot.respaldo(sala({ jugadores: [{ dinero: 20, items_ganados: [] }, { dinero: 0, items_ganados: [1, 2, 3, 4] }], item_actual: itemCon({ ultimo_pujo: 0, precio_actual: 1, pujas: [{ por: 0, incremento: 1, precio: 1 }] }) }), 1), 'respaldo capped con puja: baja');

// --- estadoKey: cambia al aparecer la decisión pendiente ---
const keySinDecision = Bot.estadoKey(sala({}));
const keyConDecision = Bot.estadoKey(sala({ decision_pendiente: { para: 1, sobre: 0 } }));
ass(true, keySinDecision !== keyConDecision, 'estadoKey cambia con decision_pendiente');
ass(keyConDecision, Bot.estadoKey(sala({ decision_pendiente: { para: 1, sobre: 0 } })), 'estadoKey estable con el mismo estado');
ass(true, Bot.estadoKey(sala({})) !== Bot.estadoKey(sala({ item_actual: itemCon({ precio_actual: 1, ultimo_pujo: 0 }) })), 'estadoKey cambia con el precio');
ass('none', Bot.estadoKey(null), 'estadoKey sin sala');

// --- todas las dificultades devuelven una acción válida en ítem fresco ---
['facil', 'normal', 'dificil'].forEach(function (d) {
    const acc = Bot.decidir(sala({}), 1, d, seq([0.99, 0.99]), { test_item: 5 });
    ass(true, acc && acc.accion === 'pujar' && [1, 3].indexOf(acc.incremento) !== -1, d + ': abre con puja válida');
});


// --- fuzz: en cualquier estado generado, decidir/respaldo solo devuelven
//     acciones legales y nunca null cuando le toca al bot ---
const ACCIONES = ['pujar', 'bajar', 'pasar_deadlock', 'asignar_rival'];
function accionValida(a) {
    if (a === null) return true;
    if (typeof a !== 'object' || ACCIONES.indexOf(a.accion) === -1) return false;
    if (a.accion === 'pujar' && [1, 3].indexOf(a.incremento) === -1) return false;
    if (a.accion === 'asignar_rival' && ([0, 1].indexOf(a.destino) === -1 || [0, 1].indexOf(a.precio) === -1)) return false;
    return true;
}
function estadoRandom() {
    const rnd = (n) => Math.floor(Math.random() * n);
    const items = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
    const ganados = (k) => Array.from({ length: k }, (_, i) => ({ id: 'i' + i, emoji: '🪙', valor: 1 + rnd(10), precio: rnd(6) }));
    const dp = rnd(3) === 0 ? { para: rnd(2), sobre: 1 - rnd(2), motivo: 'sin_dinero' } : null;
    const nPujas = rnd(4);
    return {
        estado: 'jugando',
        tematica: 'test',
        items_mezclados: items,
        indice_item: rnd(8),
        ronda: 1 + rnd(8),
        mostrar_valores: rnd(2) === 0,
        decision_pendiente: dp,
        jugadores: [
            { nombre: 'A', dinero: rnd(21), items_ganados: ganados(rnd(5)) },
            { nombre: 'B', dinero: rnd(21), items_ganados: ganados(rnd(5)) },
        ],
        item_actual: {
            id: items[rnd(8)], emoji: '🎲',
            precio_actual: rnd(21), turno_de: rnd(2),
            ultimo_pujo: rnd(2) === 0 ? null : rnd(2),
            pujas: Array.from({ length: nPujas }, (_, i) => ({ por: rnd(2), incremento: 1, precio: i + 1, ts: i })),
        },
    };
}
let fuzzMalos = 0, fuzzNull = 0;
for (let i = 0; i < 2000; i++) {
    const s = estadoRandom();
    const botSlot = rnd2(i);
    const d = Bot.decidir(s, botSlot, ['facil', 'normal', 'dificil', 'extremo'][i % 4], Math.random, null);
    const r = Bot.respaldo(s, botSlot);
    if (!accionValida(d) || !accionValida(r)) fuzzMalos++;
    const esTurno = s.item_actual.turno_de === botSlot;
    const esDecisor = !!(s.decision_pendiente && s.decision_pendiente.para === botSlot);
    // Con decisión pendiente y el bot como "sobre" (el que cedió), null es correcto:
    // el turno sigue apuntándole pero debe esperar al decisor.
    const esperandoDecision = !!s.decision_pendiente && !esDecisor;
    if (((esTurno && !esperandoDecision) || esDecisor) && d === null) fuzzNull++;
}
function rnd2(i) { return i % 2; }
ass(0, fuzzMalos, 'fuzz: 2000 estados → acciones legales o null');
ass(0, fuzzNull, 'fuzz: el bot siempre decide en su turno');
console.log('\n=== RESUMEN BOT: ' + ok + ' OK / ' + fail + ' FAIL ===');
process.exit(fail > 0 ? 1 : 0);
