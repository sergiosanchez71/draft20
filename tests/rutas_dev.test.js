/* Rutas limpias bajo el servidor embebido de PHP (`php -S`).
 *
 * Uso:  node tests/rutas_dev.test.js
 *
 * El servidor de desarrollo no lee .htaccess y sirve index.php para las rutas
 * sin extensión. inc/rutas_dev.php replica esas rewrites; esta prueba comprueba
 * que cada enlace de la web abre su página real (y no la home) y mantiene el
 * idioma pedido con ?lang.
 */
'use strict';

const { spawn, execSync } = require('child_process');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const PORT = 9700 + (process.pid % 100);
const BASE = 'http://127.0.0.1:' + PORT;

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

function matarServidor(proc) {
    try {
        if (process.platform === 'win32') {
            execSync('taskkill /F /T /PID ' + proc.pid + ' 2>NUL', { stdio: 'ignore' });
        } else {
            proc.kill('SIGKILL');
        }
    } catch (e) { /* ignore */ }
}

function titulo(html) {
    const m = html.match(/<title>([\s\S]*?)<\/title>/i);
    return m ? m[1].trim() : '';
}

const CASOS = [
    ['/como-jugar', 'Cómo se juega a Draft 20', 'es'],
    ['/como-jugar?lang=en', 'How to play Draft 20', 'en'],
    ['/como-jugar-y-estrategia', 'Cómo jugar a Draft 20', 'es'],
    ['/guias', 'Guías de Draft 20', 'es'],
    ['/guia/draft-de-20-monedas', 'draft de 20 monedas', 'es'],
    ['/tematica/tacos', 'Tacos', 'es'],
    ['/tematica/tacos?lang=en', 'Tacos', 'en'],
    ['/categoria/comida', 'Temáticas de comida', 'es'],
    ['/preguntas-frecuentes', 'Preguntas frecuentes', 'es'],
    ['/ranking', 'Ranking de temáticas', 'es'],
    ['/glosario', 'Glosario de Draft 20', 'es'],
    ['/juegos-de-subasta', 'Juegos de subasta', 'es'],
    ['/acerca', 'Acerca de Draft 20', 'es'],
    ['/privacidad', 'Privacidad', 'es'],
];

(async function () {
    const proc = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', ROOT], {
        cwd: ROOT, stdio: 'ignore', shell: process.platform === 'win32',
    });

    let listo = false;
    for (let i = 0; i < 40 && !listo; i++) {
        await esperar(250);
        try {
            listo = (await fetch(BASE + '/')).ok;
        } catch (e) { /* reintento */ }
    }
    if (!listo) {
        matarServidor(proc);
        console.error('No se pudo arrancar php -S');
        process.exit(1);
    }

    let ok = 0;
    const fallos = [];

    function check(nombre, fn) {
        try {
            fn();
            console.log('OK   ' + nombre);
            ok++;
        } catch (e) {
            console.log('FAIL ' + nombre + ' → ' + e.message);
            fallos.push(nombre);
        }
    }

    for (const [ruta, esperado, lang] of CASOS) {
        const r = await fetch(BASE + ruta, { redirect: 'follow' });
        const html = await r.text();
        check('ruta limpia ' + ruta, function () {
            if (r.status !== 200) throw new Error('status ' + r.status);
            const t = titulo(html);
            if (t.indexOf(esperado) === -1) {
                throw new Error('título "' + t + '" no contiene "' + esperado + '"');
            }
            const m = html.match(/<html lang="([^"]+)"/);
            const actual = m ? m[1] : '';
            if (actual !== lang) throw new Error('html lang=' + actual + ', esperado ' + lang);
        });
    }

    const htmlHome = await (await fetch(BASE + '/')).text();
    check('la home sigue sirviendo la landing', function () {
        const t = titulo(htmlHome);
        if (!/Draft 20/.test(t)) throw new Error('título inesperado: ' + t);
    });

    const r404 = await fetch(BASE + '/ruta-que-no-existe');
    check('ruta desconocida → 404', function () {
        if (r404.status !== 404) throw new Error('status ' + r404.status);
    });

    matarServidor(proc);
    console.log('\n=== RUTAS DEV: ' + ok + ' OK / ' + fallos.length + ' FAIL ===');
    if (fallos.length) {
        console.log('Fallos: ' + fallos.join(', '));
        process.exit(1);
    }
})();
