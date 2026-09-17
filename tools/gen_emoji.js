/**
 * Draft 20 — Generador de iconos (Fluent Emoji, Microsoft, MIT).
 *
 * Descarga en SVG el estilo "Color" de los emojis usados en el catálogo y los
 * deja en img/emoji/<codepoints>.svg. Genera además manifest.json y LICENSE.
 *
 * Uso:  node tools/gen_emoji.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.dirname(__dirname);
const OUT_DIR = path.join(ROOT, 'img', 'emoji');
const REPO = 'microsoft/fluentui-emoji';
const BRANCH = 'main';
const RAW = `https://raw.githubusercontent.com/${REPO}/${BRANCH}/`;
const TREE_API = `https://api.github.com/repos/${REPO}/git/trees/${BRANCH}?recursive=1`;
const CACHE = path.join(require('os').tmpdir(), 'draft20_emoji_meta_cache.json');
const CONCURRENCY = 24;

/** Slug estable: codepoints en hex sin FE0F, unidos por '-'. */
function slugDeEmoji(emoji) {
    const cps = [];
    for (const ch of emoji) {
        const cp = ch.codePointAt(0);
        if (cp === 0xFE0F) continue;
        cps.push(cp.toString(16));
    }
    return cps.join('-');
}

function emojisDelCatalogo() {
    const set = new Set();
    const dir = path.join(ROOT, 'tematicas');
    for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.json'))) {
        const data = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
        for (const it of data.items || []) {
            if (it.emoji) set.add(it.emoji);
        }
    }
    const cat = fs.readFileSync(path.join(ROOT, 'tematicas_catalogo.php'), 'utf8');
    for (const m of cat.matchAll(/'emoji'\s*=>\s*'([^']+)'/g)) {
        if (m[1]) set.add(m[1]);
    }
    return [...set];
}

async function conPool(items, fn, size) {
    const out = [];
    let i = 0;
    const workers = Array.from({ length: size }, async () => {
        while (i < items.length) {
            const idx = i++;
            out[idx] = await fn(items[idx], idx);
        }
    });
    await Promise.all(workers);
    return out;
}

async function descargarMeta(folder) {
    const url = RAW + 'assets/' + encodeURIComponent(folder) + '/metadata.json';
    for (let intento = 0; intento < 4; intento++) {
        try {
            const r = await fetch(url);
            if (r.ok) {
                const j = await r.json();
                return { folder, unicode: (j.unicode || '').toLowerCase(), cldr: j.cldr || '' };
            }
            if (r.status !== 429 && r.status < 500) return null; // 404 real
            const espera = Number(r.headers.get('retry-after') || 0) * 1000 || (400 * (intento + 1));
            await new Promise((res) => setTimeout(res, espera));
        } catch (e) {
            await new Promise((res) => setTimeout(res, 400 * (intento + 1)));
        }
    }
    return null;
}

(async function main() {
    fs.mkdirSync(OUT_DIR, { recursive: true });

    const emojis = emojisDelCatalogo();
    const slugs = [...new Set(emojis.map(slugDeEmoji))];
    console.log(`Emojis en catálogo: ${emojis.length} (únicos: ${slugs.length})`);

    // 1) Árbol del repo → rutas de los SVG de estilo Color
    console.log('Descargando árbol del repo…');
    const tree = await (await fetch(TREE_API)).json();
    if (!tree.tree) throw new Error('No se pudo leer el árbol del repo');
    const colorPorFolder = new Map();
    for (const node of tree.tree) {
        // Los emojis con tonos de piel viven en assets/<Name>/Default/Color/
        const m = /^assets\/([^/]+)\/(Default\/)?Color\/(.+)\.svg$/.exec(node.path);
        if (m) {
            const folder = m[1];
            const esDefault = !!m[2];
            if (!colorPorFolder.has(folder) || esDefault) {
                colorPorFolder.set(folder, node.path);
            }
        }
    }
    console.log(`Carpetas con SVG Color: ${colorPorFolder.size}`);

    // 2) Metadata por carpeta (caché incremental en temp: reintenta solo las que falten)
    let metas = [];
    if (fs.existsSync(CACHE)) {
        metas = JSON.parse(fs.readFileSync(CACHE, 'utf8'));
    }
    const carpetas = [...colorPorFolder.keys()];
    const yaVistas = new Set(metas.map((m) => m.folder));
    const pendientes = carpetas.filter((f) => !yaVistas.has(f));
    console.log(`Metadatos: ${metas.length} en caché, ${pendientes.length} por leer (de ${carpetas.length} carpetas)`);
    if (pendientes.length) {
        const nuevas = (await conPool(pendientes, descargarMeta, 8)).filter(Boolean);
        metas = metas.concat(nuevas);
        fs.writeFileSync(CACHE, JSON.stringify(metas));
        console.log(`Metadatos leídos ahora: ${nuevas.length} (total ${metas.length})`);
    }

    // 3) unicode normalizado → path del SVG Color (el metadata separa con espacios)
    const porUnicode = new Map();
    for (const m of metas) {
        const key = m.unicode.split(/[\s-]+/).filter((x) => x && x !== 'fe0f').join('-');
        const p = colorPorFolder.get(m.folder);
        if (key && p) porUnicode.set(key, p);
    }

    // 4) Descargar los SVG que faltan
    const faltan = [];
    const tareas = slugs.filter((s) => !fs.existsSync(path.join(OUT_DIR, s + '.svg')));
    console.log(`Por descargar: ${tareas.length}`);
    await conPool(tareas, async (slug) => {
        const p = porUnicode.get(slug);
        if (!p) {
            faltan.push(slug);
            return;
        }
        const url = RAW + p.split('/').map(encodeURIComponent).join('/');
        const r = await fetch(url);
        if (!r.ok) {
            faltan.push(slug);
            return;
        }
        fs.writeFileSync(path.join(OUT_DIR, slug + '.svg'), await r.text());
    }, CONCURRENCY);

    // 5) Manifest y licencia
    const manifest = slugs.filter((s) => fs.existsSync(path.join(OUT_DIR, s + '.svg')));
    fs.writeFileSync(path.join(OUT_DIR, 'manifest.json'), JSON.stringify(manifest, null, 2));
    fs.writeFileSync(path.join(OUT_DIR, 'LICENSE'), [
        'Fluent Emoji — https://github.com/microsoft/fluentui-emoji',
        '',
        'MIT License',
        '',
        'Copyright (c) Microsoft Corporation.',
        '',
        'Permission is hereby granted, free of charge, to any person obtaining a copy',
        'of this software and associated documentation files (the "Software"), to deal',
        'in the Software without restriction, including without limitation the rights',
        'to use, copy, modify, merge, publish, distribute, sublicense, and/or sell',
        'copies of the Software, and to permit persons to whom the Software is',
        'furnished to do so, subject to the following conditions:',
        '',
        'The above copyright notice and this permission notice shall be included in all',
        'copies or substantial portions of the Software.',
        '',
        'THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR',
        'IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,',
        'FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE',
        'AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER',
        'LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,',
        'OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE',
        'SOFTWARE.',
        '',
    ].join('\n'));

    console.log(`\nSVG en img/emoji: ${manifest.length}/${slugs.length}`);
    if (faltan.length) {
        console.log('Sin versión Fluent (se usará el emoji de texto): ' + faltan.join(', '));
    }
})();
