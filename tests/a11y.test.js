/* Accesibilidad de las páginas SEO (axe-core sobre el HTML servido por PHP).
 *
 * Uso:  node tests/a11y.test.js
 *
 * Levanta el servidor embebido de PHP, descarga las páginas principales, las
 * analiza con axe-core (WCAG 2.0/2.1 A-AA) y falla si hay violaciones
 * serious/critical. color-contrast se omite: jsdom no calcula estilos.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { spawn, execSync } = require('child_process');
const { JSDOM } = require('jsdom');
const axe = require('axe-core');

const ROOT = path.join(__dirname, '..');
const PORT = 9700 + (process.pid % 200);
const BASE = 'http://127.0.0.1:' + PORT;

const PAGINAS = [
    '/index.php',
    '/como-jugar',
    '/glosario',
    '/tematica/futbol',
    '/categoria/comida',
    '/guia/draft-de-20-monedas',
    '/privacidad',
];

const esperar = (ms) => new Promise(function (r) { setTimeout(r, ms); });

function matarServidor(proc) {
    try {
        if (process.platform === 'win32') {
            execSync('taskkill /F /T /PID ' + proc.pid + ' 2>NUL', { stdio: 'ignore' });
        } else {
            proc.kill('SIGKILL');
        }
    } catch (e) { /* ignore */ }
}

(async function () {
    const proc = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', ROOT], {
        cwd: ROOT, stdio: 'ignore', shell: process.platform === 'win32',
    });

    let listo = false;
    for (let i = 0; i < 40 && !listo; i++) {
        await esperar(250);
        try {
            const r = await fetch(BASE + '/index.php');
            listo = r.ok;
        } catch (e) { /* reintento */ }
    }
    if (!listo) {
        console.log('FAIL: el servidor PHP no arrancó');
        matarServidor(proc);
        process.exit(1);
    }

    let ok = 0, fail = 0;
    const problemas = [];

    try {
        for (const ruta of PAGINAS) {
            const html = await (await fetch(BASE + ruta)).text();
            const dom = new JSDOM(html, { url: BASE + ruta, runScripts: 'outside-only' });
            dom.window.eval(axe.source);
            const res = await dom.window.axe.run(dom.window.document, {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
                rules: { 'color-contrast': { enabled: false } },
                resultTypes: ['violations'],
            });
            const graves = res.violations.filter(function (v) {
                return v.impact === 'serious' || v.impact === 'critical';
            });
            if (graves.length === 0) {
                ok++;
                console.log('OK   a11y ' + ruta);
            } else {
                fail++;
                console.log('FAIL a11y ' + ruta);
                graves.forEach(function (v) {
                    const nodo = v.nodes[0];
                    problemas.push('  ' + ruta + ' → [' + v.impact + '] ' + v.id + ': ' + v.help
                        + '\n      ' + String(nodo && nodo.html || '').slice(0, 140));
                });
            }
            dom.window.close();
        }
    } finally {
        matarServidor(proc);
    }

    if (problemas.length) {
        console.log('\nViolaciones serious/critical:');
        problemas.forEach(function (p) { console.log(p); });
    }
    console.log('\n=== A11Y: ' + ok + ' OK / ' + fail + ' FAIL ===');
    process.exit(fail > 0 ? 1 : 0);
})();
