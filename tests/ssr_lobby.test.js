/* Paridad SSR ↔ JS del lobby inicial (index.php).
 *
 * Uso:  node tests/ssr_lobby.test.js
 *
 * El lobby se pinta en servidor (inc/lobby_inicial.php) para no tener CLS y la
 * hidratación lo reconstruye con renderInitialView() (js/app.core.js). Este
 * test carga la home servida por PHP en jsdom, ejecuta app.core.js y compara
 * el árbol del #app antes y después: si divergen, el salto visual vuelve.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawn, execSync } = require('child_process');
const { JSDOM } = require('jsdom');

const ROOT = path.join(__dirname, '..');
const PORT = 9800 + (process.pid % 150);
const BASE = 'http://127.0.0.1:' + PORT;

const RUTAS = ['/index.php', '/index.php?tematica=hamburguesa'];
const IDS = [
    'nameRapida', 'nameCreate', 'codeJoin', 'nameJoin', 'btnRapida', 'btnCreate',
    'btnJoin', 'btnPractice', 'btnGuiada', 'tematicaSelector', 'tematicaBotSelector',
    'colaInfo', 'lobbyError',
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

/** Firma estructural del DOM: etiquetas, atributos (clase y selección normalizadas) y texto. */
function firma(nodo) {
    const attrs = {};
    Array.from(nodo.attributes || []).forEach(function (a) {
        if (a.name === 'selected') return;
        if (a.name === 'class') {
            attrs.class = a.value.trim().split(/\s+/).filter(Boolean).sort().join(' ');
            return;
        }
        attrs[a.name] = a.value;
    });
    const texto = Array.from(nodo.childNodes)
        .filter(function (n) { return n.nodeType === 3; })
        .map(function (n) { return n.nodeValue; })
        .join('')
        .trim();
    return {
        tag: nodo.tagName,
        attrs: attrs,
        texto: texto || null,
        hijos: Array.from(nodo.children || []).map(firma),
    };
}

function valorSeleccionado(doc, id) {
    const sel = doc.querySelector('#' + id + ' option[selected]');
    return sel ? sel.getAttribute('value') : '__random__';
}

(async function () {
    const proc = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', ROOT], {
        cwd: ROOT, stdio: 'ignore', shell: process.platform === 'win32',
    });

    let listo = false;
    for (let i = 0; i < 40 && !listo; i++) {
        await esperar(250);
        try {
            listo = (await fetch(BASE + '/index.php')).ok;
        } catch (e) { /* reintento */ }
    }
    if (!listo) {
        console.log('FAIL: el servidor PHP no arrancó');
        matarServidor(proc);
        process.exit(1);
    }

    const core = fs.readFileSync(path.join(ROOT, 'js', 'app.core.js'), 'utf8');
    let ok = 0, fail = 0;
    const problemas = [];

    try {
        for (const ruta of RUTAS) {
            const html = await (await fetch(BASE + ruta)).text();
            const dom = new JSDOM(html, { url: BASE + ruta, runScripts: 'outside-only' });
            const doc = dom.window.document;
            const app = doc.getElementById('app');

            try {
                assert.ok(app, ruta + ': falta #app');
                IDS.forEach(function (id) {
                    assert.ok(doc.getElementById(id), ruta + ': falta #' + id + ' en el SSR');
                });
                assert.ok(firma(app).hijos.length > 0, ruta + ': #app se sirve vacío (SSR ausente)');

                const esperado = ruta.indexOf('tematica=') !== -1 ? 'hamburguesa' : '__random__';
                assert.strictEqual(valorSeleccionado(doc, 'tematicaSelector'), esperado,
                    ruta + ': el selector de crear sala no refleja ?tematica=');
                assert.strictEqual(valorSeleccionado(doc, 'tematicaBotSelector'), '__random__',
                    ruta + ': el selector de práctica debe empezar en aleatoria');

                const antes = firma(app);
                const bootstrap = Array.from(doc.querySelectorAll('script:not([src])'))
                    .find(function (s) { return s.textContent.indexOf('window.LANG =') !== -1; });
                assert.ok(bootstrap, ruta + ': no se encontró el bootstrap de LANG');
                dom.window.eval(bootstrap.textContent);
                dom.window.eval(core);
                await dom.window.__init(null);

                assert.deepStrictEqual(firma(app), antes,
                    ruta + ': el DOM hidratado difiere del servido (reaparecería CLS)');
                ok++;
                console.log('OK   SSR ' + ruta);
            } catch (e) {
                fail++;
                console.log('FAIL SSR ' + ruta);
                problemas.push('  ' + ruta + ' → ' + e.message.split('\n').slice(0, 12).join('\n      '));
            }
            dom.window.close();
        }
    } finally {
        matarServidor(proc);
    }

    if (problemas.length) {
        console.log('\nDiferencias SSR ↔ JS:');
        problemas.forEach(function (p) { console.log(p); });
    }
    console.log('\n=== SSR LOBBY: ' + ok + ' OK / ' + fail + ' FAIL ===');
    process.exit(fail > 0 ? 1 : 0);
})();
