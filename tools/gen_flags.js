/**
 * Draft 20 — Banderas estilizadas (SVG local) para los emojis regionales.
 *
 * Fluent Emoji no incluye banderas de país, así que se generan versiones
 * simplificadas (bandas + emblema) para que el estilo sea consistente.
 * No sobreescribe archivos existentes.
 *
 * Uso:  node tools/gen_flags.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const OUT_DIR = path.join(path.dirname(__dirname), 'img', 'emoji');
const SIZE = 128;

const C = {
    red: '#e30613', redDark: '#aa151b', redTech: '#d9102f',
    blue: '#0038a8', navy: '#002b7f', lightblue: '#74acdf', sky: '#5b97b1',
    yellow: '#ffd700', gold: '#ffce00', ochre: '#fcd116', orange: '#ff4e12',
    green: '#009246', greenDark: '#006847', greenKE: '#006600', white: '#ffffff',
    black: '#111111', brown: '#8b5a2b', gray: '#d9d9d9',
};

function svg(inner) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${SIZE}" height="${SIZE}" viewBox="0 0 ${SIZE} ${SIZE}">` +
        `<rect width="${SIZE}" height="${SIZE}" rx="14" fill="#ffffff"/>` +
        `<g clip-path="url(#r)">${inner}</g>` +
        `<defs><clipPath id="r"><rect width="${SIZE}" height="${SIZE}" rx="14"/></clipPath></defs>` +
        `<rect x="2" y="2" width="${SIZE - 4}" height="${SIZE - 4}" rx="12" fill="none" stroke="rgba(0,0,0,.12)" stroke-width="3"/>` +
        `</svg>`;
}

/** Bandas horizontales o verticales con pesos. */
function bands(dir, lista) {
    const total = lista.reduce((a, b) => a + b.w, 0);
    let pos = 0;
    let out = '';
    for (const b of lista) {
        const t = (b.w / total) * SIZE;
        out += dir === 'h'
            ? `<rect x="0" y="${pos}" width="${SIZE}" height="${t}" fill="${b.c}"/>`
            : `<rect x="${pos}" y="0" width="${t}" height="${SIZE}" fill="${b.c}"/>`;
        pos += t;
    }
    return out;
}

const circle = (cx, cy, r, c) => `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${c}"/>`;

/** Cruz nórdica: franja vertical a 1/3 y horizontal centrada. */
function nordic(cross, bg, inner) {
    const bar = 18;
    return `<rect width="${SIZE}" height="${SIZE}" fill="${bg}"/>` +
        `<rect x="${SIZE * 0.32}" y="0" width="${bar}" height="${SIZE}" fill="${cross}"/>` +
        `<rect x="0" y="${SIZE / 2 - bar / 2}" width="${SIZE}" height="${bar}" fill="${cross}"/>` +
        (inner
            ? `<rect x="${SIZE * 0.32}" y="0" width="${bar / 3}" height="${SIZE}" fill="${inner}"/>` +
              `<rect x="0" y="${SIZE / 2 - bar / 6}" width="${SIZE}" height="${bar / 3}" fill="${inner}"/>`
            : '');
}

function star(cx, cy, r, c, puntos = 5) {
    const pts = [];
    for (let i = 0; i < puntos * 2; i++) {
        const ang = (Math.PI / puntos) * i - Math.PI / 2;
        const rad = i % 2 === 0 ? r : r * 0.45;
        pts.push(`${(cx + Math.cos(ang) * rad).toFixed(1)},${(cy + Math.sin(ang) * rad).toFixed(1)}`);
    }
    return `<polygon points="${pts.join(' ')}" fill="${c}"/>`;
}

const BANDERAS = {
    '1f1ea-1f1f8': () => bands('h', [{ c: C.redDark, w: 1 }, { c: C.ochre, w: 2 }, { c: C.redDark, w: 1 }]) + circle(46, 64, 10, C.red),
    '1f1eb-1f1f7': () => bands('v', [{ c: C.navy, w: 1 }, { c: C.white, w: 1 }, { c: '#ed2939', w: 1 }]),
    '1f1ee-1f1f9': () => bands('v', [{ c: C.green, w: 1 }, { c: C.white, w: 1 }, { c: '#ce2b37', w: 1 }]),
    '1f1e9-1f1ea': () => bands('h', [{ c: C.black, w: 1 }, { c: '#dd0000', w: 1 }, { c: C.gold, w: 1 }]),
    '1f1f3-1f1f1': () => bands('h', [{ c: '#ae1c28', w: 1 }, { c: C.white, w: 1 }, { c: '#21468b', w: 1 }]),
    '1f1fa-1f1e6': () => bands('h', [{ c: '#0057b7', w: 1 }, { c: C.yellow, w: 1 }]),
    '1f1ef-1f1f5': () => `${circle(64, 64, 34, '#bc002d')}`,
    '1f1fa-1f1f8': () =>
        bands('h', Array.from({ length: 13 }, (_, i) => ({ c: i % 2 ? C.white : '#b22234', w: 1 }))) +
        `<rect width="${SIZE * 0.45}" height="${(SIZE / 13) * 7}" fill="#3c3b6e"/>` +
        Array.from({ length: 9 }, (_, i) => circle(14 + (i % 3) * 14, 14 + Math.floor(i / 3) * 18, 3.4, C.white)).join(''),
    '1f1e7-1f1f7': () =>
        `<rect width="${SIZE}" height="${SIZE}" fill="${C.greenDark}"/>` +
        `<polygon points="64,14 114,64 64,114 14,64" fill="${C.gold}"/>` + circle(64, 64, 26, '#002776'),
    '1f1e6-1f1f7': () =>
        bands('h', [{ c: C.lightblue, w: 1 }, { c: C.white, w: 1 }, { c: C.lightblue, w: 1 }]) + circle(64, 64, 15, C.gold),
    '1f1f5-1f1f9': () =>
        bands('v', [{ c: '#046a38', w: 2 }, { c: '#da291c', w: 3 }]) + circle(64, 64, 20, C.gold) + circle(64, 64, 12, C.white),
    '1f1f5-1f1ed': () =>
        bands('h', [{ c: '#0038a8', w: 1 }, { c: '#ce1126', w: 1 }]) +
        `<polygon points="0,0 74,64 0,128" fill="${C.white}"/>` + circle(24, 64, 12, C.gold),
    '1f1f2-1f1fd': () =>
        bands('v', [{ c: C.greenDark, w: 1 }, { c: C.white, w: 1 }, { c: '#ce1126', w: 1 }]) + circle(64, 64, 14, C.brown),
    '1f1ed-1f1f7': () =>
        bands('h', [{ c: '#ff0000', w: 1 }, { c: C.white, w: 1 }, { c: '#171796', w: 1 }]) +
        circle(64, 64, 20, C.white) + star(64, 64, 14, '#ff0000', 4),
    '1f1f2-1f1e6': () => `<rect width="${SIZE}" height="${SIZE}" fill="#c1272d"/>` + star(64, 64, 30, '#006233'),
    '1f1f8-1f1f2': () => bands('h', [{ c: C.white, w: 1 }, { c: '#5eb6e4', w: 1 }]) + circle(64, 64, 16, C.gold),
    '1f1ec-1f1ee': () => nordic(C.red, C.white),
    '1f1eb-1f1f4': () => nordic(C.red, C.white, '#0055a4'),
    '1f1f1-1f1ee': () => bands('h', [{ c: '#002b7f', w: 1 }, { c: '#ce1126', w: 1 }]) + circle(38, 40, 12, C.gold),
    '1f1e7-1f1f9': () =>
        `<polygon points="0,0 128,0 0,128" fill="#ffcc33"/><polygon points="128,0 128,128 0,128" fill="${C.orange}"/>` + circle(64, 64, 20, C.white),
    '1f1f9-1f1fb': () =>
        `<rect width="${SIZE}" height="${SIZE}" fill="${C.sky}"/>` +
        Array.from({ length: 4 }, (_, i) => circle(88 + (i % 2) * 18, 52 + Math.floor(i / 2) * 20, 4.5, C.yellow)).join('') +
        `<rect width="52" height="44" fill="#00247d"/>` + star(26, 22, 12, C.white, 4),
    '1f1e6-1f1e9': () =>
        bands('v', [{ c: '#10069f', w: 1 }, { c: C.gold, w: 1 }, { c: '#d0103b', w: 1 }]) + circle(64, 64, 14, '#c7b37f'),
    '1f1f0-1f1ea': () =>
        bands('h', [{ c: C.black, w: 1 }, { c: C.white, w: 1 }, { c: '#bb0000', w: 1 }, { c: C.white, w: 1 }, { c: C.greenKE, w: 1 }]) +
        circle(64, 64, 20, C.white) + circle(64, 64, 16, '#bb0000') + star(64, 64, 10, C.white, 4),
    '1f1ec-1f1f7': () =>
        bands('h', Array.from({ length: 9 }, (_, i) => ({ c: i % 2 ? C.white : '#0d5eaf', w: 1 }))) +
        `<rect width="${SIZE / 2}" height="${SIZE * 5 / 9}" fill="#0d5eaf"/>` +
        `<rect x="${SIZE / 4 - 6}" y="0" width="12" height="${SIZE * 5 / 9}" fill="${C.white}"/>` +
        `<rect x="0" y="${SIZE * 5 / 18 - 6}" width="${SIZE / 2}" height="12" fill="${C.white}"/>`,
};

fs.mkdirSync(OUT_DIR, { recursive: true });
let n = 0;
for (const [slug, fn] of Object.entries(BANDERAS)) {
    const file = path.join(OUT_DIR, slug + '.svg');
    if (fs.existsSync(file)) continue;
    fs.writeFileSync(file, svg(fn()));
    n++;
}
console.log(`Banderas generadas: ${n} en ${OUT_DIR}`);
