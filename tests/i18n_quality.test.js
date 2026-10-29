/* Calidad de la traducción EN y metadatos por idioma.
 *
 * Uso:  node tests/i18n_quality.test.js
 *
 * Cubre tres frentes:
 *  - Diccionarios generados: nombres de temáticas/ítems, términos prohibidos,
 *    puntuación y mensajes de error de API.
 *  - HTML servido por PHP: JSON-LD, OG, hreflang y CTA de juego con ?lang=en.
 *  - Comportamiento en cliente: detección de idioma en juego.php y fallback
 *    inglés de los errores de API no mapeados.
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawn, execSync } = require('child_process');
const { JSDOM } = require('jsdom');

const ROOT = path.join(__dirname, '..');
const PORT = 9500 + (process.pid % 200);
const BASE = 'http://127.0.0.1:' + PORT;

const TEMAS = {
    dragon_ball: 'Dragon Ball',
    harry_potter: 'Harry Potter',
    acampada: 'Camping',
    true_crime: 'True Crime',
    la_velada: 'La Velada',
    helado: 'Ice Cream',
    atraco: 'Heist',
    series: 'TV Series',
    moviles: 'Iconic Phones',
    barbacoa: 'Sunday BBQ',
    postres: 'Viral Desserts',
    programas_tv: 'TV Shows',
    futbol_historicos: 'Historic Soccer',
};
// Marcas que se escriben en minúscula a propósito.
const ESTILIZADOS = ['j-hope', 'aespa', 'xQc', 's1mple', 'ZywOo', 'eSports', 'iPhone', 'iPad', 'iPod', 'iMac', 'macOS', 'iOS'];
const PROHIBIDOS = [
    'football', 'catalogue', "doctor's queue", 'brick-and-mortar', 'battle roosters',
    'cockfights', 'most funny', 'O.K. duel. Corral', 'Iconic Mobiles', 'Historical Football',
];
const PROPIOS_TRAS_PUNTO_Y_COMA = ['Game', 'Modern', 'Draft'];

const leerJson = (rel) => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'));
const es = leerJson('lang/es.json');
const en = leerJson('lang/en.json');
const contenido = leerJson('lang/contenido_en.json');
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

function esEstilizado(nombre) {
    return ESTILIZADOS.some(function (p) { return nombre === p || nombre.indexOf(p + ' ') === 0; });
}

function incidencias(cadena, patron) {
    const re = new RegExp(patron, 'gi');
    return cadena.match(re) || [];
}

function planas(obj) {
    let out = '';
    (function walk(v) {
        if (typeof v === 'string') {
            out += '\n' + v;
        } else if (Array.isArray(v)) {
            v.forEach(walk);
        } else if (v && typeof v === 'object') {
            Object.keys(v).forEach(function (k) { walk(v[k]); });
        }
    })(obj);
    return out;
}

function ldJson(html) {
    const re = /<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g;
    const out = [];
    let m;
    while ((m = re.exec(html))) out.push(JSON.parse(m[1]));
    return out;
}

function porTipo(lds, tipo) {
    return lds.filter(function (x) { return x['@type'] === tipo; });
}

function selectorHrefs(html) {
    const out = {};
    const re = /<a class="d20-lang__link[^"]*"[^>]*href="([^"]+)"[^>]*hreflang="(es|en)"/g;
    let m;
    while ((m = re.exec(html))) out[m[2]] = m[1].replace(/&amp;/g, '&');
    return out;
}

let ok = 0, fail = 0;
const problemas = [];

function check(nombre, fn) {
    try {
        fn();
        ok++;
        console.log('OK   ' + nombre);
    } catch (e) {
        fail++;
        console.log('FAIL ' + nombre);
        problemas.push('  ' + nombre + ' → ' + e.message);
    }
}

async function checkAsync(nombre, fn) {
    try {
        await fn();
        ok++;
        console.log('OK   ' + nombre);
    } catch (e) {
        fail++;
        console.log('FAIL ' + nombre);
        problemas.push('  ' + nombre + ' → ' + e.message);
    }
}

(async function () {
    // =================== diccionarios ===================
    check('temáticas EN con nombres aprobados', function () {
        Object.keys(TEMAS).forEach(function (id) {
            assert.strictEqual(en.tematicas && en.tematicas[id], TEMAS[id],
                'tematicas.' + id + ' = ' + JSON.stringify(en.tematicas && en.tematicas[id]));
        });
    });

    check('temáticas EN capitalizadas', function () {
        Object.keys(en.tematicas || {}).forEach(function (id) {
            const nombre = en.tematicas[id];
            assert.ok(/^\p{Lu}/u.test(nombre) || esEstilizado(nombre),
                'temática sin mayúscula inicial: ' + id + ' = ' + JSON.stringify(nombre));
        });
    });

    check('ítems visibles EN traducidos y capitalizados', function () {
        let total = 0;
        fs.readdirSync(path.join(ROOT, 'tematicas')).forEach(function (f) {
            if (!f.endsWith('.json')) return;
            const tema = JSON.parse(fs.readFileSync(path.join(ROOT, 'tematicas', f), 'utf8'));
            (tema.items || []).forEach(function (it) {
                total++;
                const nombre = en.items && en.items[it.id];
                assert.strictEqual(typeof nombre, 'string', 'falta en.items.' + it.id);
                assert.ok(/^[\p{Lu}0-9]/u.test(nombre) || esEstilizado(nombre),
                    'ítem sin mayúscula inicial: ' + it.id + ' = ' + JSON.stringify(nombre));
            });
        });
        assert.ok(total >= 1000, 'se esperaban los ítems del catálogo, encontrados: ' + total);
    });

    check('sin términos prohibidos en el inglés generado', function () {
        const todo = planas(en) + '\n' + planas(contenido);
        PROHIBIDOS.forEach(function (term) {
            const n = incidencias(todo, term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).length;
            assert.strictEqual(n, 0, term + ' aparece ' + n + ' veces');
        });
        const bidd = incidencias(todo, '\\bbidd\\b', '');
        assert.strictEqual(bidd.length, 0, 'bidd aislado aparece ' + bidd.length + ' veces');
    });

    check('puntuación: sin mayúscula tras punto y coma salvo nombres propios', function () {
        const texto = planas(contenido);
        const re = /;\s+([A-ZÁÉÍÓÚÑ][a-záéíóúñ])/g;
        let m;
        const malos = [];
        while ((m = re.exec(texto))) {
            const cola = texto.slice(m.index + 2);
            const esPropio = PROPIOS_TRAS_PUNTO_Y_COMA.some(function (p) {
                return cola.indexOf(p + ' ') === 0 || cola.indexOf(p + '.') === 0;
            });
            if (!esPropio) malos.push(m[0]);
        }
        assert.deepStrictEqual(malos, [], 'casos: ' + malos.slice(0, 8).join(' | '));
    });

    check('errores de API con fallback inglés', function () {
        assert.strictEqual(typeof en.ui.api_errors.generico, 'string');
        assert.strictEqual(typeof en.ui.api_errors.solicitud_invalida, 'string');
        assert.ok(en.ui.api_errors['Acción inválida.']);
        assert.ok(en.ui.api_errors['Demasiadas peticiones. Inténtalo en un rato.']);
        assert.ok(en.ui.api_errors['Temática no encontrada:']);
    });

    check('OG genérica EN generada', function () {
        const ruta = path.join(ROOT, 'og', 'en', 'og-image.png');
        assert.ok(fs.existsSync(ruta), 'falta og/en/og-image.png');
        assert.ok(fs.statSync(ruta).size > 5000, 'og/en/og-image.png parece vacía');
    });

    // =================== fuentes ===================
    check('juego.php incluye detección de idioma', function () {
        const src = fs.readFileSync(path.join(ROOT, 'juego.php'), 'utf8');
        assert.ok(src.indexOf('deteccion_idioma_script()') !== -1, 'falta la llamada en <head>');
    });

    check('tematica.php construye la URL de juego con ?lang', function () {
        const src = fs.readFileSync(path.join(ROOT, 'tematica.php'), 'utf8');
        assert.ok(src.indexOf("$juegoBase = url_lang('/juego.php');") !== -1, 'juegoBase no usa url_lang');
        assert.strictEqual((src.match(/var juegoUrl = function/g) || []).length, 2, 'faltan los dos helpers juegoUrl');
        assert.ok(src.indexOf('juegoBase + encodeURIComponent') === -1, 'queda el bug de concatenación');
    });

    // =================== HTML servido ===================
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

    try {
        await checkAsync('juego.php sirve detección de idioma en el head', async function () {
            const r = await fetch(BASE + '/juego.php?codigo=ABCDE');
            assert.strictEqual(r.status, 200);
            const html = await r.text();
            assert.ok(/<html lang="es"/.test(html), 'el SSR debe ser español sin ?lang');
            assert.ok(html.indexOf('navigator.language') !== -1, 'falta navigator.language');
            assert.ok(html.indexOf('searchParams.set("lang","en")') !== -1, 'falta la redirección a ?lang=en');
            assert.ok(html.indexOf('d20_lang') !== -1, 'falta la cookie d20_lang');
        });

        await checkAsync('ficha EN: CTA de juego con ?lang=en y helper codigo', async function () {
            const html = await (await fetch(BASE + '/tematica.php?id=cerveza&lang=en')).text();
            assert.strictEqual((html.match(/id="btnJugarTema"/g) || []).length, 1);
            assert.strictEqual((html.match(/id="btnBotTema"/g) || []).length, 1);
            const base = html.match(/var juegoBase = "([^"]+)"/);
            assert.ok(base, 'no se encontró juegoBase');
            assert.strictEqual(base[1].replace(/\\\//g, '/'), '/juego.php?lang=en');
            assert.ok(/juegoBase\.indexOf\("\?"\) === -1 \? "\?" : "&"/.test(html), 'falta el separador ?/&');
            assert.ok(/juegoUrl\(r\.codigo\)/.test(html), 'no se usa juegoUrl(codigo)');
            assert.strictEqual((html.match(/juegoBase \+ encodeURIComponent/g) || []).length, 0,
                'queda la concatenación rota del P0');
        });

        await checkAsync('JSON-LD EN: URLs e imágenes por idioma', async function () {
            const home = await (await fetch(BASE + '/index.php?lang=en')).text();
            const ldsHome = ldJson(home);
            const org = porTipo(ldsHome, 'Organization')[0];
            const web = porTipo(ldsHome, 'WebSite')[0];
            const app = porTipo(ldsHome, 'WebApplication')[0];
            assert.ok(org && org.url.endsWith('?lang=en'), 'Organization.url sin ?lang=en');
            assert.ok(web && web.url.endsWith('?lang=en'), 'WebSite.url sin ?lang=en');
            assert.ok(app && app.url.endsWith('?lang=en'), 'WebApplication.url sin ?lang=en');

            const cat = await (await fetch(BASE + '/categoria.php?id=comida&lang=en')).text();
            const col = porTipo(ldJson(cat), 'CollectionPage')[0];
            assert.ok(col && Array.isArray(col.mainEntity && col.mainEntity.itemListElement),
                'CollectionPage sin itemListElement');
            const urls = col.mainEntity.itemListElement.map(function (x) { return x.url; });
            assert.ok(urls.length > 0, 'ItemList vacío');
            urls.forEach(function (u) {
                assert.ok(u.indexOf('/tematica/') !== -1 && u.indexOf('lang=en') !== -1,
                    'URL de temática sin ?lang=en: ' + u);
            });

            const articulos = [
                ['/como_jugar.php?lang=en', '/og/en/seccion/como-jugar.png'],
                ['/guia.php?slug=draft-de-20-monedas&lang=en', '/og/en/guia/draft-de-20-monedas.png'],
                ['/preguntas-frecuentes.php?lang=en', '/og/en/og-image.png'],
                ['/estrategia.php?lang=en', '/og/en/og-image.png'],
            ];
            for (const [ruta, imagen] of articulos) {
                const art = porTipo(ldJson(await (await fetch(BASE + ruta)).text()), 'Article')[0];
                assert.ok(art && art.image === 'https://draft20.es' + imagen,
                    ruta + ': image = ' + (art && art.image));
            }
        });

        await checkAsync('OG, canonical y hreflang EN/ES', async function () {
            const enHtml = await (await fetch(BASE + '/index.php?lang=en')).text();
            assert.ok(/<meta property="og:image" content="https:\/\/draft20\.es\/og\/en\/og-image\.png">/.test(enHtml),
                'og:image EN incorrecta');
            assert.ok(/<link rel="canonical" href="https:\/\/draft20\.es\/\?lang=en">/.test(enHtml),
                'canonical EN incorrecta');
            assert.ok(/hreflang="es" href="https:\/\/draft20\.es\/"/.test(enHtml), 'hreflang es incorrecto');
            assert.ok(/hreflang="x-default" href="https:\/\/draft20\.es\/"/.test(enHtml), 'x-default incorrecto');

            const esHtml = await (await fetch(BASE + '/index.php')).text();
            assert.ok(/<meta property="og:image" content="https:\/\/draft20\.es\/og-image\.png">/.test(esHtml),
                'og:image ES incorrecta');
            assert.ok(/<link rel="canonical" href="https:\/\/draft20\.es\/">/.test(esHtml),
                'canonical ES incorrecta');
        });

        await checkAsync('selector: español siempre fija ?lang=es', async function () {
            const home = await (await fetch(BASE + '/?lang=en')).text();
            const homeSel = selectorHrefs(home);
            assert.strictEqual(homeSel.es, '/?lang=es', 'el selector ES debe llevar ?lang=es');
            assert.strictEqual(homeSel.en, '/?lang=en', 'el selector EN debe llevar ?lang=en');

            const tema = await (await fetch(BASE + '/tematica.php?id=cerveza&lang=en')).text();
            const temaSel = selectorHrefs(tema);
            assert.strictEqual(temaSel.es, '/tematica.php?id=cerveza&lang=es',
                'el selector ES debe conservar la query: ' + temaSel.es);

            const redir = await fetch(BASE + '/?lang=es', { redirect: 'manual' });
            assert.strictEqual(redir.status, 302, '?lang=es debe redirigir a la URL limpia');
            assert.strictEqual(redir.headers.get('location'), '/');
            assert.ok(/no-store/.test(redir.headers.get('cache-control') || ''),
                'el 302 con cookie no debe cachearse');
            const cookies = typeof redir.headers.getSetCookie === 'function'
                ? redir.headers.getSetCookie().join('; ')
                : (redir.headers.get('set-cookie') || '');
            assert.ok(/d20_lang=es/.test(cookies), '?lang=es debe guardar la cookie es: ' + cookies);

            const redirTema = await fetch(BASE + '/tematica.php?id=cerveza&lang=es', { redirect: 'manual' });
            assert.strictEqual(redirTema.status, 302);
            assert.strictEqual(redirTema.headers.get('location'), '/tematica.php?id=cerveza');
        });

        await checkAsync('tApi: fallback inglés sin filtrar español', async function () {
            const core = fs.readFileSync(path.join(ROOT, 'js', 'app.core.js'), 'utf8');
            const domEn = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
                url: BASE + '/', runScripts: 'outside-only',
            });
            domEn.window.LANG = en;
            domEn.window.D20_LANG = 'en';
            domEn.window.eval(core);
            const tApiEn = domEn.window.DraftApp.tApi;
            assert.strictEqual(tApiEn('Acción inválida.', 400), 'Invalid action.');
            assert.strictEqual(tApiEn('Temática no encontrada: pizza', 400), 'Theme not found: pizza');
            assert.strictEqual(tApiEn('Temática no encontrada: pizza', 400).indexOf('Temática'), -1,
                'el mensaje dinámico no debe filtrar español');
            assert.strictEqual(tApiEn('Mensaje no mapeado.', 400), en.ui.api_errors.solicitud_invalida);
            assert.strictEqual(tApiEn('Mensaje no mapeado.', 500), en.ui.api_errors.generico);
            domEn.window.close();

            const domEs = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
                url: BASE + '/', runScripts: 'outside-only',
            });
            domEs.window.LANG = es;
            domEs.window.D20_LANG = 'es';
            domEs.window.eval(core);
            assert.strictEqual(domEs.window.DraftApp.tApi('Mensaje no mapeado.', 400), 'Mensaje no mapeado.');
            domEs.window.close();
        });
    } finally {
        matarServidor(proc);
    }

    if (problemas.length) {
        console.log('\nProblemas de i18n:');
        problemas.forEach(function (p) { console.log(p); });
    }
    console.log('\n=== I18N QUALITY: ' + ok + ' OK / ' + fail + ' FAIL ===');
    process.exit(fail > 0 ? 1 : 0);
})();
