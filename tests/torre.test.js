/* Torre de Batalla: lógica pura y persistencia (node, sin DOM).
 *
 * Uso:  node tests/torre.test.js
 */
'use strict';

const assert = require('assert');

// localStorage en memoria para el módulo.
const memoria = {};
global.localStorage = {
    getItem: (k) => (k in memoria ? memoria[k] : null),
    setItem: (k, v) => { memoria[k] = String(v); },
    removeItem: (k) => { delete memoria[k]; },
};

const Torre = require('../js/torre.js');

let ok = 0, fail = 0;
function check(cond, msg) {
    if (cond) { ok++; console.log('OK   ' + msg); return; }
    fail++;
    console.log('FAIL ' + msg);
}

// 1) Run nuevo y schema.
const r0 = Torre.empezar();
check(r0.enProgreso === true && r0.pisoActual === 1, 'empezar: piso 1 en progreso');
check(r0.vidasRestantes === Torre.MAX_REVIVES, 'empezar: revives llenos');
check(Torre.REVIVES_ACTIVOS === false, 'flag de revives apagado sin anuncios');
const r0b = Torre.cargar();
check(r0b.enProgreso === true && r0b.pisoActual === 1, 'cargar: persiste el run');

// 2) Tema sin repetir en 10 pisos.
const todas = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'];
const vistas = [];
for (let i = 0; i < 10; i++) {
    const t = Torre.temaParaPiso(vistas, todas);
    check(!vistas.includes(t), 'piso ' + (i + 1) + ': tema no repetido (' + t + ')');
    vistas.push(t);
}
check(Torre.temaParaPiso([], []) === 'hamburguesa', 'sin catálogo: fallback hamburguesa');

// 3) Resultado con regla del juego.
check(Torre.resultadoPiso(30, 20, 0, 0) === 'win', 'más estrellas gana');
check(Torre.resultadoPiso(20, 30, 9, 0) === 'loss', 'menos estrellas pierde');
check(Torre.resultadoPiso(25, 25, 5, 2) === 'win', 'empate a estrellas: más monedas gana');
check(Torre.resultadoPiso(25, 25, 1, 4) === 'loss', 'empate a estrellas: menos monedas pierde');
check(Torre.resultadoPiso(25, 25, 3, 3) === 'tablas', 'empate total: tablas (= derrota en torre)');

// 4) Superar piso acumula y avanza (solo entre partidas).
Torre.superarPiso(r0, 'pizza', 12);
check(r0.partidasGanadas === 1 && r0.pisoActual === 2, 'superar: avanza al piso 2');
check(r0.gastado === 8 && r0.tematicasJugadas.includes('pizza'), 'superar: gasto y tema registrados');
check(r0.codigoSala === null, 'superar: limpia la sala del piso');

// 5) Revives con flag apagado.
check(Torre.consumirRevive(r0) === false, 'flag off: no se consume revive');
check(r0.vidasRestantes === Torre.MAX_REVIVES, 'flag off: vidas intactas');
check(Torre.revivesUsados(r0) === 0, 'revives usados = 0');

// 6) Logros por hitos.
const r3 = Object.assign({}, r0, { partidasGanadas: 3 });
check(Torre.logrosGanados(r3, false).includes('torre_piso3'), 'logro piso 3');
const r7 = Object.assign({}, r0, { partidasGanadas: 7 });
check(Torre.logrosGanados(r7, false).includes('torre_piso7'), 'logro piso 7');
const r10 = Object.assign({}, r0, { partidasGanadas: 10, vidasRestantes: 3 });
check(Torre.logrosGanados(r10, true).includes('torre_piso10'), 'logro piso 10');
check(Torre.logrosGanados(r10, true).includes('torre_intocable'), 'intocable sin revives');
const r10b = Object.assign({}, r0, { partidasGanadas: 10, vidasRestantes: 2 });
check(!Torre.logrosGanados(r10b, true).includes('torre_intocable'), 'con revive no hay intocable');
check(!Torre.logrosGanados(r10, false).includes('torre_piso10'), 'piso 10 exige victoria');

// 7) Desglose del piso para la pantalla de resultados.
const itv = (v, p) => ({ id: 'x', valor: v, precio: p });
const dz1 = Torre.resumenPiso([itv(9, 6), itv(7, 4)], [itv(8, 8), itv(5, 5)], 10, 7);
check(dz1.resultado === 'win' && dz1.miScore === 16 && dz1.rivalScore === 13, 'desglose: gana por estrellas');
check(dz1.miGastado === 10 && dz1.rivalGastado === 13 && dz1.desempate === false, 'desglose: gasto por dinero restante');
const dz2 = Torre.resumenPiso([itv(6, 4)], [itv(6, 9)], 12, 5);
check(dz2.resultado === 'win' && dz2.desempate === true, 'desglose: desempate por monedas');
const dz3 = Torre.resumenPiso([itv(6, 1)], [itv(6, 1)], 8, 8);
check(dz3.resultado === 'tablas' && dz3.desempate === false, 'desglose: tablas exacta');

// 8) Anti-reproceso: salas ya resueltas (volver atrás/recarga).
check(Torre.pisoHecho('ZZZZZ') === false, 'sala nueva no procesada');
Torre.marcarPisoHecho('ZZZZZ');
check(Torre.pisoHecho('ZZZZZ') === true, 'sala marcada como procesada');
Torre.marcarPisoHecho('ZZZZZ');
check(Torre.pisoHecho('ZZZZZ') === true, 'marcar dos veces no duplica');

// 9) Victoria y texto para compartir.
check(Torre.victoria(r10) === true, 'victoria con 10 ganadas');
check(Torre.victoria(r7) === false, 'sin victoria con 7');
const txt = Torre.textoCompartir(r10, 'Sergio');
check(txt.includes('10/10') && txt.includes('https://draft20.es/'), 'texto para compartir con enlace');

// 9) Terminar limpia.
Torre.terminar();
const rFin = Torre.cargar();
check(rFin.enProgreso === false && rFin.pisoActual === 1, 'terminar: run limpio');

// 10) Mock de anuncio recompensado (3 s y éxito; sin red).
let mockOk = false;
Torre.mostrarAnuncioRecompensado(() => { mockOk = true; }, () => {});
setTimeout(() => {
    check(mockOk === true, 'mock: éxito tras ~3 s');
    let cancelOk = false;
    const ctl = Torre.mostrarAnuncioRecompensado(() => {}, () => { cancelOk = true; });
    ctl.cancelar();
    setTimeout(() => {
        check(cancelOk === true, 'mock: cancelar dispara onCancelado');
        console.log('\n=== TORRE: ' + ok + ' OK / ' + fail + ' FAIL ===');
        process.exit(fail > 0 ? 1 : 0);
    }, 100);
}, 3200);
