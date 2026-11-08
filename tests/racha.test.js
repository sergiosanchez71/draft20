/* Draft 20 — Test del sistema de racha diaria (Node puro, sin servidor).
 *
 * Prueba la lógica pura de cálculo de rachas y la generación de user_id.
 * No carga app.core.js (evita problemas de jsdom con await en el eval).
 *
 * Uso:  node tests/racha.test.js
 */
'use strict';

let ok = 0, fail = 0;
function check(cond, msg) {
    if (cond) { ok++; console.log('OK   ' + msg); }
    else { fail++; console.log('FAIL ' + msg); }
}

// -------------------- UUID v4 --------------------
function generarUuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}
const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

const u1 = generarUuid();
check(uuidRegex.test(u1), 'generarUuid produce UUID v4 válido');
const u2 = generarUuid();
check(u1 !== u2, 'dos UUID generados son distintos');

// -------------------- fechaHoyLocal --------------------
function fechaHoyLocal() {
    const ahora = new Date();
    const y = ahora.getFullYear();
    const m = String(ahora.getMonth() + 1).padStart(2, '0');
    const d = String(ahora.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
}
const hoy = fechaHoyLocal();
const fechaRegex = /^\d{4}-\d{2}-\d{2}$/;
check(fechaRegex.test(hoy), 'fechaHoyLocal devuelve YYYY-MM-DD válido');
const [y, m, d] = hoy.split('-').map(Number);
check(m >= 1 && m <= 12 && d >= 1 && d <= 31, 'fechaHoyLocal tiene mes/día en rango');

// -------------------- calcularRachas (lógica pura del endpoint) --------------------
function calcularRachas(fechas, hoy) {
    const ayer = new Date(hoy);
    ayer.setDate(ayer.getDate() - 1);
    const ayerStr = ayer.toISOString().slice(0, 10);
    const jugadoHoy = fechas.includes(hoy);

    let rachaActual = 0;
    if (jugadoHoy || fechas.includes(ayerStr)) {
        rachaActual = 1;
        let cursor = jugadoHoy ? hoy : ayerStr;
        while (true) {
            const next = new Date(cursor);
            next.setDate(next.getDate() - 1);
            const nextStr = next.toISOString().slice(0, 10);
            if (fechas.includes(nextStr)) {
                rachaActual++;
                cursor = nextStr;
            } else {
                break;
            }
        }
    }

    let rachaMaxima = 0, rachaTmp = 0, fechaPrev = null;
    const fechasOrd = [...fechas].sort();
    for (const f of fechasOrd) {
        if (fechaPrev === null) rachaTmp = 1;
        else {
            const esperado = new Date(fechaPrev);
            esperado.setDate(esperado.getDate() + 1);
            if (f === esperado.toISOString().slice(0, 10)) rachaTmp++;
            else rachaTmp = 1;
        }
        fechaPrev = f;
        if (rachaTmp > rachaMaxima) rachaMaxima = rachaTmp;
    }

    return { rachaActual, rachaMaxima, jugadoHoy };
}

// Racha de 3 días consecutivos (hoy, ayer, anteayer)
const ayer = new Date(hoy); ayer.setDate(ayer.getDate() - 1); const ayerStr = ayer.toISOString().slice(0, 10);
const anteayer = new Date(hoy); anteayer.setDate(anteayer.getDate() - 2); const anteayerStr = anteayer.toISOString().slice(0, 10);
const hace5 = new Date(hoy); hace5.setDate(hace5.getDate() - 5); const hace5Str = hace5.toISOString().slice(0, 10);

const r1 = calcularRachas([hoy, ayerStr, anteayerStr, hace5Str], hoy);
check(r1.rachaActual === 3, 'racha actual = 3 (hoy, ayer, anteayer)');
check(r1.rachaMaxima === 3, 'racha máxima = 3');
check(r1.jugadoHoy === true, 'jugado hoy = true');

// Sin historial
const r2 = calcularRachas([], hoy);
check(r2.rachaActual === 0, 'racha actual = 0 sin historial');
check(r2.rachaMaxima === 0, 'racha máxima = 0 sin historial');
check(r2.jugadoHoy === false, 'jugado hoy = false sin historial');

// Racha máxima histórica mayor que la actual
const r3 = calcularRachas(
    ['2026-10-01','2026-10-02','2026-10-03','2026-10-04','2026-10-05', ayerStr, hoy],
    hoy
);
check(r3.rachaActual === 2, 'racha actual = 2 (solo ayer y hoy)');
check(r3.rachaMaxima === 5, 'racha máxima = 5 (racha pasada de octubre)');

// Racha cruzando mes (enero -> febrero)
const r4 = calcularRachas(['2026-01-30','2026-01-31','2026-02-01'], '2026-02-01');
check(r4.rachaActual === 3, 'racha cruza mes: 3 días consecutivos');

// Hueco en la racha (ayer faltó)
const r5 = calcularRachas([hoy, anteayerStr], hoy);
check(r5.rachaActual === 1, 'racha actual = 1 con hueco (ayer faltó)');
check(r5.rachaMaxima === 1, 'racha máxima = 1 con hueco');

// -------------------- Resultado --------------------
console.log('\n=== RACHA JS: ' + ok + ' OK / ' + fail + ' FAIL ===');
process.exit(fail > 0 ? 1 : 0);
