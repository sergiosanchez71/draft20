/**
 * Draft 20 — Traduce lang/_source_es.json con el endpoint público de Google
 * Translate (dict-chrome-ex). Guarda caché incremental en
 * lang/_translated.json para poder relanzar sin repetir trabajo.
 *
 * Uso: node tools/i18n_translate.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SOURCE = path.join(ROOT, 'lang', '_source_es.json');
const CACHE = path.join(ROOT, 'lang', '_translated.json');

const SKIP_KEYS = new Set(['href', 'fecha', 'id', 'enlace', 'slug', 'url', 'tipo']);
const source = JSON.parse(fs.readFileSync(SOURCE, 'utf8'));
let cache = {};
if (fs.existsSync(CACHE)) {
    try { cache = JSON.parse(fs.readFileSync(CACHE, 'utf8')) || {}; } catch { cache = {}; }
}

function hojas(v, key, out) {
    if (Array.isArray(v)) {
        v.forEach((x) => hojas(x, key, out));
        return;
    }
    if (v && typeof v === 'object') {
        for (const [k, x] of Object.entries(v)) hojas(x, typeof k === 'string' ? k : key, out);
        return;
    }
    if (typeof v !== 'string' || !v.trim() || SKIP_KEYS.has(key)) return;
    if (/^https?:\/\//i.test(v.trim())) return;
    if (/^[\d\s.,:;/%+-]+$/.test(v)) return;
    out.add(v);
}

const textos = new Set();
hojas(source, '', textos);
const pendientes = [...textos].filter((t) => !cache[t]);
console.log(`Cadenas: ${textos.size} · en caché: ${textos.size - pendientes.length} · pendientes: ${pendientes.length}`);

const URL_BASE = 'https://clients5.google.com/translate_a/single?client=dict-chrome-ex&sl=es&tl=en&dt=t&q=';

function trocear(texto, max = 4200) {
    if (texto.length <= max) return [texto];
    const partes = [];
    let resto = texto;
    while (resto.length > max) {
        let corte = resto.lastIndexOf('. ', max);
        if (corte < max * 0.5) corte = resto.lastIndexOf(' ', max);
        if (corte < 1) corte = max;
        partes.push(resto.slice(0, corte + 1));
        resto = resto.slice(corte + 1);
    }
    if (resto) partes.push(resto);
    return partes;
}

async function traducir(texto) {
    const partes = trocear(texto);
    const out = [];
    for (const parte of partes) {
        const r = await fetch(URL_BASE + encodeURIComponent(parte), {
            headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36' },
        });
        if (!r.ok) {
            const err = new Error('HTTP ' + r.status);
            err.status = r.status;
            throw err;
        }
        const data = await r.json();
        if (!Array.isArray(data) || !Array.isArray(data[0])) throw new Error('Respuesta inesperada');
        out.push(data[0].map((s) => (Array.isArray(s) ? (s[0] || '') : '')).join(''));
    }
    return out.join('');
}

function dormir(ms) { return new Promise((r) => setTimeout(r, ms)); }

let guardado = 0;
function guardar() {
    fs.writeFileSync(CACHE, JSON.stringify(cache, null, 2) + '\n');
}

let hechas = 0;
let fallos = 0;

async function trabajador(lista, idxRef) {
    while (true) {
        const i = idxRef.i++;
        if (i >= lista.length) return;
        const texto = lista[i];
        let ok = false;
        for (let intento = 0; intento < 5 && !ok; intento++) {
            try {
                cache[texto] = await traducir(texto);
                ok = true;
            } catch (e) {
                if (intento === 4) {
                    fallos++;
                    console.log(`FALLO: ${e.message} :: ${texto.slice(0, 70)}`);
                } else {
                    await dormir(1200 * Math.pow(2, intento));
                }
            }
        }
        hechas++;
        if (hechas % 20 === 0 || hechas === lista.length) {
            guardar();
            console.log(`${hechas}/${lista.length} · fallos ${fallos}`);
        }
        await dormir(160);
    }
}

const idxRef = { i: 0 };
const workers = Array.from({ length: 3 }, () => trabajador(pendientes, idxRef));
await Promise.all(workers);
guardar();
console.log(`Terminado: ${hechas} nuevas · ${fallos} fallos · caché ${Object.keys(cache).length}`);
if (fallos > 0) process.exitCode = 2;
