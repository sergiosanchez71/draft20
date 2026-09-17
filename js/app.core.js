/* Draft 20 - app.core.js
 * Helpers compartidos (i18n, api, toast, vibrate, DOM, modal, stats, sesión)
 * + lobby (crear / unirse / practicar). Expone window.DraftApp para app.game.js.
 */
(function () {
    'use strict';

    const POLL_MS = 1000;
    const POLL_OCULTO_MS = 5000; // lobby en 2º plano: se baja la cadencia, no se corta
    const BOT_MAX_RESPUESTA_MS = 60000; // watchdog del bot: solo corre en su turno

    const state = {
        codigo: null,
        jugadorId: null,
        jugadorSlot: null,
        jugadorNombre: null,
        rivalNombre: null,
        sala: null,
        pollTimer: null,
        isPolling: false,
        pollTerminal: false,
        actionInFlight: false,
        abandonoDetectadoVibrado: false,
        tematicaSeleccionada: null,
        tematicaCreada: null,
        pollFailures: 0,
        pollInFlight: false,
        rivalAusente: null,
        ultimoEmoteEnviado: 0,
        botWaitUntil: 0,
        botWatchStart: 0,
        botKeyDelay: null,
        botValores: null,
        partidaGuardada: false,
        botDificultad: 'normal',
        mostrarValores: false,
        statsRegistradas: false,
        finalRegistrada: false,
        serieFinal: null,
        logrosFinal: null,
        ultimoEmoteTs: 0,
        bot: null,
        botTurnoKey: null,
        lastRenderSig: null,
    };

    // =================== i18n ===================
    function t(key, params) {
        params = params || {};
        const parts = key.split('.');
        let v = window.LANG;
        for (let i = 0; i < parts.length; i++) v = v && v[parts[i]];
        if (typeof v !== 'string') return key;
        return v.replace(/\{(\w+)\}/g, function (_, k) { return params[k] != null ? params[k] : ''; });
    }
    function tItem(id) { return (window.LANG.items && window.LANG.items[id]) || id; }
    function tTematica(id) { return (window.LANG.tematicas && window.LANG.tematicas[id]) || id; }
    function catLabel(id) { return (window.LANG.tematicas_categorias && window.LANG.tematicas_categorias[id]) || id; }
    function categoriasData() { return Array.isArray(window.__CATEGORIAS) ? window.__CATEGORIAS : []; }

    function tematicaEmoji(id) {
        const cats = categoriasData();
        for (let i = 0; i < cats.length; i++) {
            const list = cats[i].tematicas || [];
            for (let j = 0; j < list.length; j++) {
                if (list[j].id === id) return list[j].emoji || '🎲';
            }
        }
        return '🎲';
    }

    // =================== fetch helper ===================
    async function api(method, path, body) {
        const opts = {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        };
        if (body !== undefined) opts.body = JSON.stringify(body);
        try {
            const r = await fetch(path, opts);
            const data = await r.json().catch(function () { return {}; });
            data._status = r.status;
            return data;
        } catch (e) {
            return { ok: false, _status: 0, error: 'network' };
        }
    }

    // =================== session (localStorage) ===================
    function keyFor(codigo) { return 'draft20_' + codigo; }
    function saveSession() {
        if (!state.codigo) return;
        try {
            localStorage.setItem(keyFor(state.codigo), JSON.stringify({
                jugadorId: state.jugadorId,
                jugadorNombre: state.jugadorNombre,
                ts: Date.now(),
            }));
        } catch (e) { /* almacenamiento no disponible (modo privado) */ }
    }
    function loadSession(codigo) {
        try { return JSON.parse(localStorage.getItem(keyFor(codigo)) || 'null'); }
        catch (e) { return null; }
    }
    function clearSession(codigo) { localStorage.removeItem(keyFor(codigo)); }

    // =================== stats locales ===================
    function loadStats() {
        try {
            const raw = JSON.parse(localStorage.getItem('draft20_stats') || 'null');
            if (raw && typeof raw === 'object') return raw;
        } catch (e) { /* ignore */ }
        return { wins: 0, losses: 0, draws: 0, streak: 0, best: 0 };
    }
    function saveStats(s) { try { localStorage.setItem('draft20_stats', JSON.stringify(s)); } catch (e) { /* ignore */ } }
    function registrarResultado(resultado) {
        if (state.statsRegistradas || state.bot) return;
        state.statsRegistradas = true;
        const s = loadStats();
        if (resultado === 'win') {
            s.wins = (s.wins || 0) + 1;
            s.streak = (s.streak || 0) > 0 ? s.streak + 1 : 1;
        } else if (resultado === 'loss') {
            s.losses = (s.losses || 0) + 1;
            s.streak = (s.streak || 0) < 0 ? s.streak - 1 : -1;
        } else {
            s.draws = (s.draws || 0) + 1;
            s.streak = 0;
        }
        s.best = Math.max(s.best || 0, s.streak || 0);
        saveStats(s);
    }

    // =================== url helpers ===================
    function getBasePath() {
        const path = window.location.pathname;
        return path.replace(/\/[^/]*$/, '/');
    }
    function buildLink(codigo) {
        return window.location.origin + getBasePath() + '?sala=' + encodeURIComponent(codigo);
    }

    /** Recuerda el nombre para la creación de sala en 1 clic desde las fichas. */
    function guardarNombre(nombre) {
        try { localStorage.setItem('draft20_nombre', (nombre || '').trim()); } catch (e) { /* ignore */ }
    }

    const LOGROS_IDS = ['coleccionista', 'cazador', 'austero', 'derrochador', 'racha', 'veterano', 'primera_victoria', 'serie_ganada', 'explorador', 'verdugo'];

    /** Slug del icono: codepoints en hex sin FE0F, unidos por '-'. */
    function emojiSlug(emoji) {
        if (!emoji) return '';
        const cps = [];
        for (const ch of emoji) {
            const cp = ch.codePointAt(0);
            if (cp === 0xFE0F) continue;
            cps.push(cp.toString(16));
        }
        return cps.join('-');
    }

    /**
     * Icono Fluent Emoji (SVG, MIT) con fallback automático al emoji de texto.
     * alt vacío = decorativo (el nombre visible va al lado).
     */
    function emojiImg(emoji, clase, alt) {
        const img = el('img', {
            class: clase || '',
            src: '/img/emoji/' + emojiSlug(emoji) + '.svg',
            alt: alt || '',
            loading: 'lazy',
            decoding: 'async',
            width: '128',
            height: '128',
        });
        img.addEventListener('error', function () {
            // Sin asset (o red caída): se pinta el emoji de texto como antes.
            const span = el('span', { class: clase || '' }, emoji);
            if (img.parentNode) img.parentNode.replaceChild(span, img);
        });
        return img;
    }

    function logrosDesbloqueados() {
        try {
            const g = JSON.parse(localStorage.getItem('draft20_logros') || '{}');
            return LOGROS_IDS.filter(function (id) { return g && g[id]; });
        } catch (e) { return []; }
    }

    /** Metadatos de logros para el perfil (el test de cada uno vive en app.game.js). */
    const LOGROS_META = [
        { id: 'coleccionista', icono: '🧺' },
        { id: 'cazador', icono: '🎯' },
        { id: 'austero', icono: '💰' },
        { id: 'derrochador', icono: '💸' },
        { id: 'racha', icono: '🔥' },
        { id: 'veterano', icono: '🎖️' },
        { id: 'primera_victoria', icono: '🥇' },
        { id: 'serie_ganada', icono: '🏆' },
        { id: 'explorador', icono: '🗺️' },
        { id: 'verdugo', icono: '⚔️' },
    ];
    function logroIcono(id) {
        for (let i = 0; i < LOGROS_META.length; i++) {
            if (LOGROS_META[i].id === id) return LOGROS_META[i].icono;
        }
        return '🏅';
    }

    // =================== serie al mejor de 3 ===================
    const SERIE_VIGENCIA_MS = 30 * 60 * 1000;

    function leerSerieGuardada() {
        let serie = null;
        try { serie = JSON.parse(localStorage.getItem('draft20_serie') || 'null'); } catch (e) { /* ignore */ }
        if (!serie || typeof serie !== 'object') return null;
        if ((Date.now() - (serie.ts || 0)) >= SERIE_VIGENCIA_MS) return null;
        return serie;
    }

    /** Serie vigente contra el rival actual (para la cabecera de la partida). */
    function leerSerie() {
        const serie = leerSerieGuardada();
        const rival = state.rivalNombre || '';
        return (serie && rival && serie.rival === rival) ? serie : null;
    }

    /** Suma el resultado a la serie contra el rival actual (se reinicia a los 30 min). */
    function actualizarSerie(resultado) {
        const rival = state.rivalNombre || 'Rival';
        let serie = null;
        try { serie = JSON.parse(localStorage.getItem('draft20_serie') || 'null'); } catch (e) { /* ignore */ }
        const ahora = Date.now();
        const vigente = serie && serie.rival === rival && (ahora - (serie.ts || 0)) < SERIE_VIGENCIA_MS;
        if (!vigente) serie = { rival: rival, mio: 0, rivalPuntos: 0 };
        if (resultado === 'win') serie.mio = (serie.mio || 0) + 1;
        else if (resultado === 'loss') serie.rivalPuntos = (serie.rivalPuntos || 0) + 1;
        serie.ts = ahora;
        serie.ganada = (serie.mio >= 2 || serie.rivalPuntos >= 2) ? (serie.mio >= 2 ? 'mio' : 'rival') : '';
        try { localStorage.setItem('draft20_serie', JSON.stringify(serie)); } catch (e) { /* ignore */ }
        return serie;
    }

    // =================== historial local ===================
    function registrarHistorial(resultado) {
        const s = state.sala || {};
        const entrada = {
            ts: Date.now(),
            tema: s.tematica || '',
            rival: state.rivalNombre || (state.bot ? 'Bot' : 'Rival'),
            r: resultado,
            bot: !!state.bot,
        };
        try {
            const raw = localStorage.getItem('draft20_historial');
            const prev = raw ? JSON.parse(raw) : [];
            const lista = Array.isArray(prev) ? prev : [];
            lista.unshift(entrada);
            localStorage.setItem('draft20_historial', JSON.stringify(lista.slice(0, 10)));
        } catch (e) { /* ignore */ }
    }

    // =================== contador anónimo ===================
    /** Incrementa un contador agregado (sin cookies ni identificadores). */
    function evento(nombre) {
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/api/evento.php', JSON.stringify({ evento: nombre }));
            }
        } catch (e) { /* ignore */ }
    }

    // =================== sonido (WebAudio, sin ficheros) ===================
    let audioCtx = null;

    function sonidoActivo() {
        try { return localStorage.getItem('draft20_sonido') === '1'; } catch (e) { return false; }
    }

    function toggleSonido() {
        const on = !sonidoActivo();
        try { localStorage.setItem('draft20_sonido', on ? '1' : '0'); } catch (e) { /* ignore */ }
        if (on) sfx('turno'); // feedback inmediato al activar
        return on;
    }

    /** Tonos cortos generados al vuelo; no suena nada si el usuario no lo activó. */
    function sfx(nombre) {
        if (!sonidoActivo()) return;
        const tonos = {
            turno: [[660, 0.07]],
            item: [[523, 0.08], [784, 0.12]],
            perdido: [[330, 0.08], [220, 0.14]],
            fin: [[523, 0.1], [659, 0.1], [784, 0.18]],
        };
        const seq = tonos[nombre];
        if (!seq) return;
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            let t0 = audioCtx.currentTime;
            seq.forEach(function (p) {
                const osc = audioCtx.createOscillator();
                const g = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = p[0];
                g.gain.setValueAtTime(0.0001, t0);
                g.gain.exponentialRampToValueAtTime(0.08, t0 + 0.01);
                g.gain.exponentialRampToValueAtTime(0.0001, t0 + p[1]);
                osc.connect(g);
                g.connect(audioCtx.destination);
                osc.start(t0);
                osc.stop(t0 + p[1] + 0.02);
                t0 += p[1];
            });
        } catch (e) { /* sin audio */ }
    }

    // =================== PWA (añadir a pantalla de inicio) ===================
    let deferredPrompt = null;
    const esIOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '');

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
    });
    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        evento('pwa:install');
        toast(t('ui.lobby.pwa_instalada'));
    });

    /** Pasos manuales (iOS y navegadores sin prompt nativo). */
    function mostrarPasosInstalar() {
        const pasos = [];
        const android = el('li', {}, t('ui.lobby.pwa_paso_android'));
        const ios = el('li', {}, t('ui.lobby.pwa_paso_ios'));
        const pc = el('li', {}, t('ui.lobby.pwa_paso_pc'));
        if (esIOS) {
            pasos.push(ios, android, pc);
        } else {
            pasos.push(android, ios, pc);
        }
        const content = el('div', {}, [
            el('h2', { class: 'text-lg font-bold text-amber-400 mb-2' }, t('ui.lobby.pwa_titulo')),
            el('p', { class: 'text-xs text-slate-400 mb-3' }, t('ui.lobby.pwa_nota')),
            el('ul', { class: 'list-disc list-inside space-y-2 text-sm text-slate-200' }, pasos),
        ]);
        const m = showModal(content);
        content.appendChild(el('button', {
            class: 'mt-4 w-full bg-slate-600 text-slate-100 py-3 rounded-lg btn-tap',
            onclick: m.close,
        }, t('ui.reglas.cerrar')));
    }

    async function pwaInstalar() {
        if (deferredPrompt) {
            try {
                deferredPrompt.prompt();
                await deferredPrompt.userChoice;
            } catch (e) { /* ignore */ }
            deferredPrompt = null;
            return;
        }
        mostrarPasosInstalar();
    }

    // =================== Mi progreso ===================
    function showProgresoModal() {
        const st = loadStats();
        const desbloqueados = logrosDesbloqueados();
        const serie = leerSerieGuardada();
        let historial = [];
        try { historial = JSON.parse(localStorage.getItem('draft20_historial') || '[]') || []; } catch (e) { /* ignore */ }
        if (!Array.isArray(historial)) historial = [];

        const filas = [];
        filas.push(el('div', { class: 'text-center text-sm text-slate-200 mb-1' },
            t('ui.lobby.stats_record', { v: st.wins || 0, d: st.losses || 0, e: st.draws || 0 })));
        filas.push(el('div', { class: 'text-center text-xs text-slate-400 mb-3' },
            t('ui.lobby.progreso_racha', { actual: st.streak || 0, mejor: st.best || 0 })));
        if (serie) {
            filas.push(el('div', { class: 'text-center text-xs text-amber-300 mb-3' },
                t('ui.lobby.progreso_serie', { rival: serie.rival, mio: serie.mio || 0, suyos: serie.rivalPuntos || 0 })));
        }

        filas.push(el('div', { class: 'text-xs uppercase tracking-wide text-slate-400 mb-2' }, t('ui.lobby.progreso_logros') + ' · ' + desbloqueados.length + '/' + LOGROS_IDS.length));
        filas.push(el('div', { class: 'space-y-2 mb-4' }, LOGROS_META.map(function (l) {
            const ok = desbloqueados.indexOf(l.id) !== -1;
            return el('div', { class: 'flex items-start gap-2 rounded bg-slate-700/60 px-3 py-2 ' + (ok ? '' : 'opacity-50') }, [
                el('span', { class: 'text-xl leading-none' }, l.icono),
                el('div', { class: 'min-w-0' }, [
                    el('div', { class: 'text-xs font-bold ' + (ok ? 'text-amber-300' : 'text-slate-300') },
                        t('ui.juego.logros.' + l.id) + (ok ? ' ✓' : '')),
                    el('div', { class: 'text-[11px] text-slate-400 leading-snug' }, t('ui.juego.logros_desc.' + l.id)),
                ]),
            ]);
        })));

        filas.push(el('div', { class: 'text-xs uppercase tracking-wide text-slate-400 mb-2' }, t('ui.lobby.progreso_historial')));
        if (!historial.length) {
            filas.push(el('p', { class: 'text-xs text-slate-500 italic' }, t('ui.lobby.progreso_sin_historial')));
        } else {
            filas.push(el('div', { class: 'space-y-1' }, historial.slice(0, 5).map(function (h) {
                const icono = h.r === 'win' ? '✅' : (h.r === 'loss' ? '❌' : '🤝');
                const quien = h.bot ? 'Bot' : (h.rival || 'Rival');
                let fecha = '';
                try { fecha = new Date(h.ts || 0).toLocaleDateString(); } catch (e) { /* ignore */ }
                return el('div', { class: 'flex items-center justify-between text-xs text-slate-300 bg-slate-700/40 rounded px-2 py-1' }, [
                    el('span', { class: 'truncate' }, icono + ' ' + quien),
                    el('span', { class: 'text-slate-500 ml-2 flex-shrink-0' }, (h.tema ? tTematica(h.tema) : '') + ' · ' + fecha),
                ]);
            })));
        }

        const content = el('div', {}, [el('h2', { class: 'text-lg font-bold text-amber-400 mb-3' }, '🏅 ' + t('ui.lobby.progreso_titulo'))].concat(filas));
        const m = showModal(content);
        content.appendChild(el('button', {
            class: 'mt-4 w-full bg-slate-600 text-slate-100 py-3 rounded-lg btn-tap',
            onclick: m.close,
        }, t('ui.reglas.cerrar')));
    }

    // =================== toast ===================
    // Cola corta: los avisos se muestran en secuencia (1 activo + 2 pendientes)
    // para que dos mensajes seguidos no se pisen.
    const toastCola = [];
    let toastTimer = null;
    let toastMostrando = false;

    function toast(msg, ms) {
        toastCola.push({ msg: msg, ms: ms || 2000 });
        if (toastCola.length > 2) toastCola.splice(0, toastCola.length - 2);
        if (!toastMostrando) siguienteToast();
    }

    function siguienteToast() {
        const item = toastCola.shift();
        if (!item) {
            toastMostrando = false;
            return;
        }
        toastMostrando = true;
        let el = document.getElementById('toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        el.textContent = item.msg;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            el.classList.remove('show');
            siguienteToast();
        }, item.ms);
    }

    // =================== vibrate ===================
    function vibrate(pattern) {
        if (navigator.vibrate) {
            try { navigator.vibrate(pattern); } catch (e) { /* ignore */ }
        }
    }

    // =================== copy link ===================
    function copyLink(codigo) {
        const url = buildLink(codigo);
        const ok = function () { toast(t('ui.lobby.toast_copiado')); vibrate(20); };
        const fallback = function () {
            const ta = document.createElement('textarea');
            ta.value = url;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                const r = document.execCommand('copy');
                r ? ok() : toast('No se pudo copiar');
            } catch (e) { toast('No se pudo copiar'); }
            ta.remove();
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(ok, fallback);
        } else fallback();
    }

    function shareWhatsApp(codigo) {
        const url = buildLink(codigo);
        const msg = t('ui.app.titulo') + ': ' + codigo + '\n' + url;
        window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank');
    }

    // =================== DOM helpers ===================
    function $(sel) { return document.querySelector(sel); }
    function $$(sel) { return Array.from(document.querySelectorAll(sel)); }
    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'class') node.className = attrs[k];
                else if (k === 'html') node.innerHTML = attrs[k];
                else if (k.startsWith('on')) node.addEventListener(k.slice(2), attrs[k]);
                else if (attrs[k] !== null && attrs[k] !== undefined) node.setAttribute(k, attrs[k]);
            });
        }
        if (children) {
            (Array.isArray(children) ? children : [children]).forEach(function (c) {
                if (c == null) return;
                if (typeof c === 'string') node.appendChild(document.createTextNode(c));
                else node.appendChild(c);
            });
        }
        return node;
    }
    function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

    // =================== modal genérico ===================
    function showModal(content, opts) {
        opts = opts || {};
        const overlay = el('div', { class: 'fixed inset-0 bg-black/70 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4' });
        const box = el('div', {
            class: 'bg-slate-800 w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl p-5 fade-in ' +
                (opts.lockBody ? 'max-h-[90vh] overflow-hidden' : 'max-h-[85vh] overflow-y-auto'),
        });
        function close() {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
        }
        function onKey(e) { if (e.key === 'Escape') close(); }
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        box.appendChild(content);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        const foco = overlay.querySelector('button, select, input, a[href]');
        if (foco) { try { foco.focus(); } catch (e) { /* ignore */ } }
        return { overlay: overlay, close: close };
    }

    function showRulesModal() {
        const secs = [
            ['ui.reglas.obj_titulo', 'ui.reglas.obj_texto'],
            ['ui.reglas.sub_titulo', 'ui.reglas.sub_texto'],
            ['ui.reglas.cap_titulo', 'ui.reglas.cap_texto'],
            ['ui.reglas.dead_titulo', 'ui.reglas.dead_texto'],
            ['ui.reglas.fin_titulo', 'ui.reglas.fin_texto'],
        ];
        const content = el('div', {}, [
            el('h2', { class: 'text-xl font-bold text-amber-400 mb-4' }, t('ui.reglas.titulo')),
            el('div', { class: 'space-y-3' }, secs.map(function (s) {
                return el('div', {}, [
                    el('div', { class: 'text-sm font-bold text-slate-100' }, t(s[0])),
                    el('p', { class: 'text-xs text-slate-400 leading-relaxed' }, t(s[1])),
                ]);
            })),
        ]);
        const m = showModal(content);
        content.appendChild(el('button', {
            class: 'mt-5 w-full bg-amber-400 text-slate-900 font-bold py-3 rounded-lg btn-tap',
            onclick: m.close,
        }, t('ui.reglas.cerrar')));
        try { localStorage.setItem('draft20_reglas', '1'); } catch (e) { /* ignore */ }
    }

    // =================== INDEX / LOBBY ===================
    async function indexInit(linkSala) {
        // Preselección de temática: ?tematica=<id> o window.__tematicaPre (servidor).
        if (!state.tematicaSeleccionada) {
            let pre = window.__tematicaPre || null;
            if (!pre) {
                try { pre = new URLSearchParams(window.location.search).get('tematica'); } catch (e) { pre = null; }
            }
            if (pre && esTematicaValida(pre)) state.tematicaSeleccionada = pre;
        }
        // Si viene ?sala=CODE en URL, mostrar vista "join" con código pre-rellenado
        if (linkSala) {
            const session = loadSession(linkSala);
            if (session && session.jugadorId) {
                // Ya jugó/creó esta sala, ir al juego
                window.location.href = 'juego.php?codigo=' + encodeURIComponent(linkSala);
                return;
            }
            renderJoinView(linkSala, '');
        } else {
            renderInitialView();
            buscarPartidaEnCurso().then(function (partida) {
                if (partida) pintarBannerPartida(partida);
            });
        }
    }

    /**
     * Busca en localStorage salas propias que sigan vivas (jugando/esperando).
     * Las que ya no existen se limpian. Devuelve la primera válida.
     */
    async function buscarPartidaEnCurso() {
        try {
            const codigos = [];
            for (let i = 0; i < localStorage.length; i++) {
                const k = localStorage.key(i);
                const m = k && k.match(/^draft20_(?:bot_)?([A-Z0-9]{5})$/);
                if (m && codigos.indexOf(m[1]) === -1) codigos.push(m[1]);
            }
            for (let j = 0; j < codigos.length; j++) {
                const codigo = codigos[j];
                const r = await api('GET', 'api/estado.php?codigo=' + encodeURIComponent(codigo) + '&t=' + Date.now());
                if (r.ok && r.sala) {
                    const estado = r.sala.estado;
                    if (estado === 'jugando' || estado === 'esperando') {
                        return { codigo: codigo, estado: estado, revancha: false };
                    }
                    // Sala terminada con revancha fresca del rival: se puede volver.
                    const rev = r.sala.revancha;
                    const fresca = rev && (Math.floor(Date.now() / 1000) - (rev.ts || 0)) <= 600;
                    if (estado === 'finalizada' && fresca) {
                        return { codigo: codigo, estado: estado, revancha: true };
                    }
                }
                clearSession(codigo);
                try { localStorage.removeItem('draft20_bot_' + codigo); } catch (e) { /* ignore */ }
            }
        } catch (e) { /* ignore */ }
        return null;
    }

    /** Carga un script bajo demanda (p. ej. la librería de QR). */
    function cargarScript(src, alCargar, alFallar) {
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.onload = function () { if (alCargar) alCargar(); };
        s.onerror = function () { if (alFallar) alFallar(); };
        document.head.appendChild(s);
    }

    /** Modal con el QR del enlace de invitación (librería cargada on-demand). */
    function mostrarQR(codigo) {
        const url = buildLink(codigo);
        const cont = el('div', {}, [
            el('h2', { class: 'text-lg font-bold text-amber-400 mb-3' }, t('ui.lobby.qr_titulo')),
            el('div', { id: 'qrBox', class: 'flex justify-center bg-white rounded-lg p-3 min-h-[248px] items-center' }, [
                el('span', { class: 'text-slate-500 text-sm' }, '…'),
            ]),
            el('p', { class: 'text-xs text-slate-400 mt-3 text-center break-all' }, url),
        ]);
        const m = showModal(cont);
        cont.appendChild(el('button', {
            class: 'mt-4 w-full bg-slate-600 text-slate-100 py-3 rounded-lg btn-tap',
            onclick: m.close,
        }, t('ui.reglas.cerrar')));

        const pintar = function () {
            const box = document.getElementById('qrBox');
            if (!box || !window.QrCreator) return;
            box.innerHTML = '';
            window.QrCreator.render({
                text: url,
                size: 240,
                radius: 0.4,
                ecLevel: 'L',
                fill: '#0f172a',
                background: '#ffffff',
                quiet: 2,
            }, box);
        };
        if (window.QrCreator) {
            pintar();
        } else {
            cargarScript('/js/vendor/qr-creator.min.js', pintar);
        }
    }

    function pintarBannerPartida(partida) {
        const app = $('#app');
        if (!app || $('#partidaEnCurso')) return;
        const card = el('section', { id: 'partidaEnCurso', class: 'bg-amber-400 text-slate-900 p-4 rounded-lg m-4 fade-in' }, [
            el('p', { class: 'text-sm font-bold' }, partida.revancha ? t('ui.lobby.revancha_pendiente') : t('ui.lobby.partida_en_curso')),
            el('div', { class: 'flex items-center justify-between gap-3 mt-2' }, [
                el('span', { class: 'text-2xl font-mono font-bold tracking-widest' }, partida.codigo),
                el('a', {
                    class: 'bg-slate-900 text-amber-300 font-bold py-2 px-4 rounded-lg btn-tap',
                    href: 'juego.php?codigo=' + encodeURIComponent(partida.codigo),
                }, partida.revancha ? t('ui.lobby.revancha_ver') : t('ui.lobby.continuar')),
            ]),
        ]);
        app.insertBefore(card, app.firstChild);
    }

    function esTematicaValida(id) {
        if (id === TEMATICA_RANDOM) return true;
        const cats = categoriasData();
        for (let i = 0; i < cats.length; i++) {
            const list = cats[i].tematicas || [];
            for (let j = 0; j < list.length; j++) {
                if (list[j].id === id) return true;
            }
        }
        return false;
    }

    function renderInitialView() {
        const app = $('#app');
        clear(app);
        const st = loadStats();
        const hayStats = (st.wins || st.losses || st.draws);
        const btnInstalar = el('button', {
            id: 'btnInstalar',
            class: 'w-9 h-9 flex-shrink-0 rounded-full bg-emerald-500 text-white text-base font-bold btn-tap',
            'aria-label': t('ui.lobby.pwa_instalar'),
            title: t('ui.lobby.pwa_instalar'),
            onclick: pwaInstalar,
        }, '📲');
        const btnProgreso = el('button', {
            class: 'w-9 h-9 flex-shrink-0 rounded-full bg-slate-700 text-slate-200 text-base font-bold btn-tap',
            'aria-label': t('ui.lobby.btn_progreso'),
            title: t('ui.lobby.btn_progreso'),
            onclick: showProgresoModal,
        }, '🏅');
        const btnSonido = el('button', {
            class: 'w-9 h-9 flex-shrink-0 rounded-full bg-slate-700 text-slate-200 text-base font-bold btn-tap',
            'aria-label': t('ui.lobby.sonido'),
            title: t('ui.lobby.sonido'),
            onclick: function () { this.textContent = toggleSonido() ? '🔊' : '🔇'; },
        }, sonidoActivo() ? '🔊' : '🔇');
        const btnReglas = el('button', {
            class: 'w-9 h-9 flex-shrink-0 rounded-full bg-slate-700 text-slate-200 text-sm font-bold btn-tap',
            'aria-label': t('ui.lobby.btn_reglas'),
            title: t('ui.lobby.btn_reglas'),
            onclick: showRulesModal,
        }, '?');
        app.appendChild(el('header', { class: 'py-1.5 px-3 flex items-center gap-2 min-h-[44px]' }, [
            el('p', { class: 'flex-1 text-amber-300/80 text-xs font-mono truncate' },
                hayStats
                    ? t('ui.lobby.stats_record', { v: st.wins || 0, d: st.losses || 0, e: st.draws || 0 })
                    : ''),
            btnInstalar,
            btnProgreso,
            btnSonido,
            btnReglas,
        ]));

        const selectorBox = el('div', { id: 'tematicaSelector', class: 'mb-4' });
        const botSelectorBox = el('div', { id: 'tematicaBotSelector', class: 'mb-4' });

        let nombrePrevio = '';
        try { nombrePrevio = localStorage.getItem('draft20_nombre') || ''; } catch (e) { /* ignore */ }

        // Partida rápida: temática aleatoria y emparejamiento con el primero que
        // también la pulse (sin compartir código). Va antes de Crear Sala.
        const rapidaForm = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.input_nombre_jugador')),
            el('input', { id: 'nameRapida', type: 'text', maxlength: '20', value: nombrePrevio, placeholder: t('ui.lobby.placeholder_nombre'), class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base' }),
            el('button', { id: 'btnRapida', class: 'w-full bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-lg' }, '⚡ ' + t('ui.lobby.btn_rapida')),
            el('p', { class: 'text-[11px] text-slate-500 mt-2 text-center' }, t('ui.lobby.rapida_ayuda')),
        ]);

        // Modo "⭐ Valores visibles" (persistido; se comparte con la sala).
        // Se muestra en Crear Sala y en Practicar: ambas instancias van sincronizadas.
        try {
            if (localStorage.getItem('draft20_mostrar_valores') === '1') state.mostrarValores = true;
        } catch (e) { /* ignore */ }
        const mvPintores = [];
        function crearToggleValores(claseWrapper) {
            const btn = el('button', {
                type: 'button',
                'aria-pressed': state.mostrarValores ? 'true' : 'false',
                onclick: function () {
                    state.mostrarValores = !state.mostrarValores;
                    try { localStorage.setItem('draft20_mostrar_valores', state.mostrarValores ? '1' : '0'); } catch (e) { /* ignore */ }
                    mvPintores.forEach(function (p) { p(); });
                },
            }, t('ui.lobby.mostrar_valores'));
            function pintar() {
                btn.className = 'w-full py-3 px-4 rounded-lg border text-sm font-bold btn-tap ' +
                    (state.mostrarValores ? 'bg-amber-400 text-slate-900 border-amber-400' : 'bg-slate-700 text-slate-200 border-slate-600');
                btn.setAttribute('aria-pressed', state.mostrarValores ? 'true' : 'false');
            }
            mvPintores.push(pintar);
            pintar();
            return el('div', { class: claseWrapper }, [
                btn,
                el('div', { class: 'text-[11px] text-slate-500 mt-2 text-center' }, t('ui.lobby.mostrar_valores_ayuda')),
            ]);
        }

        // Selector de dificultad del bot (movido al ámbito del módulo para que
        // lo use también la reserva de partida rápida): ver crearSelectorDificultad.

        const createForm = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-2' }, t('ui.lobby.selector_tematica')),
            selectorBox,
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.input_nombre_jugador')),
            el('input', { id: 'nameCreate', type: 'text', maxlength: '20', value: nombrePrevio, placeholder: t('ui.lobby.placeholder_nombre'), class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base' }),
            crearToggleValores('mb-4'),
            el('button', { id: 'btnCreate', class: 'w-full bg-amber-400 text-slate-900 font-bold py-4 rounded-lg btn-tap text-lg' }, t('ui.lobby.btn_crear')),
        ]);

        const joinForm = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.label_unirse')),
            el('input', { id: 'codeJoin', type: 'text', maxlength: '5', minlength: '5', placeholder: t('ui.lobby.placeholder_codigo'), class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base uppercase tracking-widest text-center text-2xl font-mono' }),
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.input_nombre_jugador')),
            el('input', { id: 'nameJoin', type: 'text', maxlength: '20', value: nombrePrevio, placeholder: 'Jugador 2', class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base' }),
            el('button', { id: 'btnJoin', class: 'w-full bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-lg' }, t('ui.lobby.btn_unirse')),
        ]);

        const errorBox = el('div', { id: 'lobbyError', class: 'hidden bg-rose-500 text-white p-3 rounded-lg mx-4 mb-4 text-sm text-center' });

        // Dificultad del bot (persistida entre sesiones).
        try {
            const difGuardada = localStorage.getItem('draft20_bot_dificultad');
            if (difGuardada) state.botDificultad = difGuardada;
        } catch (e) { /* ignore */ }

        const practiceCard = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-2' }, t('ui.lobby.selector_tematica_bot')),
            botSelectorBox,
            el('label', { class: 'block text-sm text-slate-400 mb-2 text-center' }, t('ui.lobby.bot_dificultad')),
            crearSelectorDificultad('mt-2'),
            crearToggleValores('mt-4'),
                el('button', {
                    id: 'btnPractice',
                    class: 'w-full bg-slate-700 text-slate-200 py-3 rounded-lg btn-tap text-sm mt-4',
                    onclick: onPractice,
                }, t('ui.lobby.btn_practicar')),
                el('button', {
                    id: 'btnGuiada',
                    class: 'w-full bg-amber-400/90 text-slate-900 font-bold py-3 rounded-lg btn-tap text-sm mt-2',
                    onclick: onGuiada,
                }, '🎓 ' + t('ui.lobby.btn_guiada')),
                el('p', { class: 'text-[11px] text-slate-500 mt-1 text-center' }, t('ui.lobby.guiada_ayuda')),
            ]);

        app.appendChild(errorBox);
        app.appendChild(rapidaForm);
        app.appendChild(el('div', { class: 'text-center text-slate-500 text-xs my-2' }, '— o —'));
        app.appendChild(createForm);
        app.appendChild(el('div', { class: 'text-center text-slate-500 text-xs my-2' }, '— o —'));
        app.appendChild(joinForm);
        app.appendChild(el('div', { class: 'text-center text-slate-500 text-xs my-2' }, '— o —'));
        app.appendChild(practiceCard);

        $('#btnCreate').addEventListener('click', onCreate);
        $('#btnJoin').addEventListener('click', onJoin);
        $('#btnRapida').addEventListener('click', onPartidaRapida);

        renderTematicaSelector(selectorBox, { ctx: state });
        renderTematicaSelector(botSelectorBox, {
            ctx: ctxBot,
            onChange: function () {
                try { localStorage.setItem('draft20_tematica_bot', ctxBot.tematicaSeleccionada); } catch (e) { /* ignore */ }
            },
        });

        // Primer ingreso: mostrar reglas automáticamente.
        try {
            if (!localStorage.getItem('draft20_reglas')) showRulesModal();
        } catch (e) { /* ignore */ }
    }

    const TEMATICA_RANDOM = '__random__';

    // Selector de temática del bloque de práctica: independiente del de
    // "Crear sala" y con la última elección recordada.
    const ctxBot = { tematicaSeleccionada: TEMATICA_RANDOM };
    try {
        const tb = localStorage.getItem('draft20_tematica_bot');
        if (tb && esTematicaValida(tb)) ctxBot.tematicaSeleccionada = tb;
    } catch (e) { /* ignore */ }
    const DIF_OPCIONES = [['facil', 'ui.lobby.bot_facil'], ['normal', 'ui.lobby.bot_normal'], ['dificil', 'ui.lobby.bot_dificil'], ['extremo', 'ui.lobby.bot_extremo']];

    /** Selector de dificultad del bot (píldoras), reutilizado por el bloque de
     *  práctica y por la reserva de "Partida rápida". */
    function crearSelectorDificultad(claseWrapper) {
        const wrap = el('div', { class: 'flex flex-wrap gap-2 justify-center ' + (claseWrapper || '') });
        const btns = [];
        function pintar() {
            btns.forEach(function (b, i) {
                const activo = state.botDificultad === DIF_OPCIONES[i][0];
                b.className = 'px-3 py-1.5 rounded-full text-xs border btn-tap ' +
                    (activo ? 'bg-amber-400 text-slate-900 border-amber-400 font-bold' : 'bg-slate-700 text-slate-200 border-slate-600');
            });
        }
        DIF_OPCIONES.forEach(function (d) {
            const b = el('button', {
                type: 'button',
                onclick: function () {
                    state.botDificultad = d[0];
                    try { localStorage.setItem('draft20_bot_dificultad', d[0]); } catch (e) { /* ignore */ }
                    pintar();
                },
            }, t(d[1]));
            btns.push(b);
            wrap.appendChild(b);
        });
        pintar();
        return wrap;
    }

    /**
     * Selector de temática: desplegable nativo con todas las temáticas
     * agrupadas por categoría. Por defecto "✨ Todas (aleatoria)".
     */
    function renderTematicaSelector(container, opts) {
        opts = opts || {};
        const ctx = opts.ctx || state;
        const cats = categoriasData();
        if (!ctx.tematicaSeleccionada) ctx.tematicaSeleccionada = TEMATICA_RANDOM;

        const select = el('select', {
            class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 text-base',
            'aria-label': t('ui.lobby.selector_tematica'),
            onchange: function () {
                ctx.tematicaSeleccionada = select.value;
                if (opts.onChange) opts.onChange(ctx);
            },
        });
        select.appendChild(el('option', { value: TEMATICA_RANDOM }, t('ui.lobby.tematica_aleatoria')));
        cats.forEach(function (c) {
            const group = el('optgroup', { label: (c.emoji || '🎲') + ' ' + catLabel(c.id) });
            (c.tematicas || []).forEach(function (tm) {
                group.appendChild(el('option', { value: tm.id }, (tm.emoji || '🎲') + ' ' + tTematica(tm.id)));
            });
            select.appendChild(group);
        });
        select.value = ctx.tematicaSeleccionada || TEMATICA_RANDOM;

        clear(container);
        container.appendChild(select);
    }

    /**
     * Resuelve la temática elegida: si es "Todas (aleatoria)", sortea una del
     * catálogo completo; si no, devuelve el id seleccionado.
     */
    function resolverTematica(ctx) {
        const sel = ctx && ctx.tematicaSeleccionada;
        if (sel && sel !== TEMATICA_RANDOM) return sel;
        const todas = [];
        categoriasData().forEach(function (c) {
            (c.tematicas || []).forEach(function (tm) { todas.push(tm.id); });
        });
        if (!todas.length) return 'hamburguesa';
        return todas[Math.floor(Math.random() * todas.length)];
    }

    function renderJoinView(prefilledCode, prefillName) {
        renderInitialView();
        $('#codeJoin').value = prefilledCode;
        if (prefillName) $('#nameJoin').value = prefillName;
        $('#codeJoin').focus();
    }

    function showLobbyError(msg) {
        const box = $('#lobbyError');
        if (!box) return;
        box.textContent = msg;
        box.classList.remove('hidden');
        setTimeout(function () { box.classList.add('hidden'); }, 4000);
    }

    async function onCreate() {
        const tematica = resolverTematica(state);
        const nombre = $('#nameCreate').value.trim();
        const btn = $('#btnCreate');
        btn.disabled = true; btn.classList.add('opacity-50');
        const r = await api('POST', 'api/crear_sala.php', {
            tematica: tematica,
            nombre: nombre,
            mostrar_valores: !!state.mostrarValores,
        });
        btn.disabled = false; btn.classList.remove('opacity-50');
        if (!r.ok) { showLobbyError(r.error || 'Error'); vibrate([100, 50, 100]); return; }
        state.codigo = r.codigo;
        state.jugadorId = r.jugador_id;
        state.jugadorNombre = (nombre || '').trim() || ('Jugador 1');
        guardarNombre(state.jugadorNombre);
        state.tematicaCreada = tematica;
        saveSession();
        evento('game:creada');
        renderCreatorView();
        startPollingLobby();
    }

    /**
     * Crea una partida de práctica: sala nueva + bot sentado como J2.
     * Usada por "Practicar vs 🤖" y por la revancha contra bot (misma dificultad).
     */
    // Nombres del bot: aleatorios por partida; el 🤖 delante lo identifica siempre.
    const BOT_NOMBRES = ['Botín', 'Doña Subasta', 'El Martillo', 'Chollo', 'La Puja', 'Remate', 'Subastín', 'Doña Puja'];

    function nombreBot() {
        return '🤖 ' + BOT_NOMBRES[Math.floor(Math.random() * BOT_NOMBRES.length)];
    }

    async function iniciarPartidaBot(tematica, dificultad, nombre, mostrarValores) {
        const nombreFinal = (nombre && nombre.trim()) || state.jugadorNombre || 'Tú';
        const mv = (mostrarValores === undefined) ? !!state.mostrarValores : !!mostrarValores;
        // Guarda: si llega la constante de aleatoria sin resolver, se sortea aquí.
        const temaFinal = (tematica === TEMATICA_RANDOM || !tematica)
            ? resolverTematica({ tematicaSeleccionada: TEMATICA_RANDOM })
            : tematica;
        const r = await api('POST', 'api/crear_sala.php', {
            tematica: temaFinal,
            nombre: nombreFinal,
            mostrar_valores: mv,
        });
        if (!r.ok) { toast(r.error || 'Error'); return false; }
        const r2 = await api('POST', 'api/unirse_sala.php', { codigo: r.codigo, nombre: nombreBot(), bot: true, creador_id: r.jugador_id });
        if (!r2.ok) { toast(r2.error || 'Error'); return false; }
        try {
            localStorage.setItem('draft20_bot_' + r.codigo, JSON.stringify({
                jugadorId: r2.jugador_id,
                dificultad: dificultad || 'normal',
            }));
        } catch (e) { /* ignore */ }
        state.codigo = r.codigo;
        state.jugadorId = r.jugador_id;
        state.jugadorNombre = nombreFinal;
        guardarNombre(nombreFinal);
        saveSession();
        evento('game:bot');
        window.location.href = 'juego.php?codigo=' + encodeURIComponent(r.codigo);
        return true;
    }

    async function onPractice() {
        const tematica = resolverTematica(ctxBot);
        const nombre = ($('#nameCreate') && $('#nameCreate').value.trim()) || 'Tú';
        const dificultad = state.botDificultad || 'normal';
        const btn = $('#btnPractice');
        if (btn) { btn.disabled = true; btn.classList.add('opacity-50'); }
        await iniciarPartidaBot(tematica, dificultad, nombre);
        if (btn) { btn.disabled = false; btn.classList.remove('opacity-50'); }
    }

    /** Práctica guiada: bot fácil, ⭐ visibles y explicaciones paso a paso. */
    async function onGuiada() {
        const nombre = ($('#nameCreate') && $('#nameCreate').value.trim()) || 'Tú';
        const btn = $('#btnGuiada');
        if (btn) { btn.disabled = true; btn.classList.add('opacity-50'); }
        try { localStorage.setItem('draft20_guiada', '1'); } catch (e) { /* ignore */ }
        await iniciarPartidaBot(resolverTematica(ctxBot), 'facil', nombre, true);
        if (btn) { btn.disabled = false; btn.classList.remove('opacity-50'); }
    }

    async function onJoin() {
        const codigo = $('#codeJoin').value.trim().toUpperCase();
        const nombre = $('#nameJoin').value.trim();
        if (!/^[A-Z0-9]{5}$/.test(codigo)) { showLobbyError(t('ui.lobby.error_codigo_invalido')); vibrate([80, 40, 80]); return; }
        const btn = $('#btnJoin');
        btn.disabled = true; btn.classList.add('opacity-50');
        const r = await api('POST', 'api/unirse_sala.php', { codigo: codigo, nombre: nombre });
        btn.disabled = false; btn.classList.remove('opacity-50');
        if (!r.ok) { showLobbyError(r.error || 'Error'); vibrate([100, 50, 100]); return; }
        state.codigo = codigo;
        state.jugadorId = r.jugador_id;
        state.jugadorNombre = (nombre || '').trim() || ('Jugador 2');
        guardarNombre(state.jugadorNombre);
        saveSession();
        // J2 entra directamente al juego
        window.location.href = 'juego.php?codigo=' + encodeURIComponent(codigo);
    }

    function renderCreatorView() {
        const app = $('#app');
        clear(app);
        app.appendChild(el('header', { class: 'px-6 pt-2 pb-0 text-center' }, [
            el('p', { class: 'text-slate-400 text-sm' }, tTematica(state.sala?.tematica || state.tematicaCreada || '')),
        ]));

        app.appendChild(el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 text-center fade-in' }, [
            el('p', { class: 'text-sm text-slate-400 mb-2' }, t('ui.lobby.lbl_invitacion')),
            el('div', { class: 'text-5xl font-mono font-bold text-amber-400 tracking-widest my-4' }, state.codigo),
            el('div', { class: 'flex gap-2 mt-4' }, [
                el('button', { class: 'flex-1 bg-slate-700 text-slate-100 py-3 rounded-lg btn-tap', onclick: function () { copyLink(state.codigo); } }, t('ui.lobby.btn_copiar')),
                el('button', { class: 'flex-1 bg-emerald-500 text-white py-3 rounded-lg btn-tap', onclick: function () { shareWhatsApp(state.codigo); } }, t('ui.lobby.btn_whatsapp')),
                el('button', { class: 'bg-slate-700 text-slate-100 py-3 px-4 rounded-lg btn-tap', onclick: function () { mostrarQR(state.codigo); } }, t('ui.lobby.btn_qr')),
            ]),
        ]));

        app.appendChild(el('section', { id: 'waitingBox', class: 'bg-slate-800 p-6 rounded-lg m-4 text-center fade-in' }, [
            el('div', { class: 'inline-block animate-spin rounded-full h-8 w-8 border-4 border-slate-600 border-t-amber-400 mb-3' }),
            el('p', { class: 'text-slate-300 text-sm' }, t('ui.lobby.esperando_rival_join')),
        ]));
    }

    async function pollLobbyTick() {
        if (!state.codigo) return;
        const r = await api('GET', 'api/estado.php?codigo=' + encodeURIComponent(state.codigo)
            + (state.jugadorId ? '&jugador_id=' + encodeURIComponent(state.jugadorId) : '')
            + '&t=' + Date.now());
        if (!r.ok) {
            // Sala borrada o sin permiso: volvemos al lobby con aviso.
            if (r._status === 404 || r._status === 403) {
                stopPollingLobby();
                clearSession(state.codigo);
                state.codigo = null;
                state.jugadorId = null;
                toast(t('ui.app.sala_expirada'));
                renderInitialView();
            }
            return;
        }
        state.sala = r.sala;
        if (r.sala.estado === 'jugando' || r.sala.estado === 'finalizada') {
            stopPollingLobby();
            window.location.href = 'juego.php?codigo=' + encodeURIComponent(state.codigo);
        }
    }

    // =================== PARTIDA RÁPIDA ===================

    async function onPartidaRapida() {
        const nombre = ($('#nameRapida') && $('#nameRapida').value.trim()) || '';
        guardarNombre(nombre);
        const btn = $('#btnRapida');
        if (btn) { btn.disabled = true; btn.classList.add('opacity-50'); }
        const r = await api('POST', 'api/partida_rapida.php', { nombre: nombre });
        if (btn) { btn.disabled = false; btn.classList.remove('opacity-50'); }
        if (!r.ok) { showLobbyError(r.error || 'Error'); vibrate([100, 50, 100]); return; }

        state.codigo = r.codigo;
        state.jugadorId = r.jugador_id;
        state.jugadorNombre = nombre || (r.rol === 'creador' ? 'Jugador 1' : 'Jugador 2');
        state.tematicaCreada = null;
        saveSession();
        evento('game:rapida');

        if (r.rol === 'rival') {
            window.location.href = 'juego.php?codigo=' + encodeURIComponent(r.codigo);
            return;
        }
        renderBuscandoView();
        startPollingLobby();
    }

    /** Pantalla de espera del matchmaking, con reserva de bot a los 20 s. */
    function renderBuscandoView() {
        const app = $('#app');
        clear(app);
        app.appendChild(el('header', { class: 'px-6 pt-2 pb-0 text-center' }, [
            el('p', { class: 'text-slate-300 text-sm font-bold' }, t('ui.lobby.buscando_rival')),
        ]));
        app.appendChild(el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 text-center fade-in' }, [
            el('div', { class: 'inline-block animate-spin rounded-full h-8 w-8 border-4 border-slate-600 border-t-amber-400 mb-3' }),
            el('p', { class: 'text-slate-300 text-sm' }, t('ui.lobby.buscando_rival_sub')),
            el('div', { class: 'text-3xl font-mono font-bold text-amber-400 tracking-widest my-4' }, state.codigo || ''),
            el('div', { class: 'flex gap-2' }, [
                el('button', { class: 'flex-1 bg-slate-700 text-slate-100 py-3 rounded-lg btn-tap', onclick: function () { copyLink(state.codigo); } }, t('ui.lobby.btn_copiar')),
                el('button', { class: 'flex-1 bg-emerald-500 text-white py-3 rounded-lg btn-tap', onclick: function () { shareWhatsApp(state.codigo); } }, t('ui.lobby.btn_whatsapp')),
                el('button', { class: 'bg-slate-700 text-slate-100 py-3 px-4 rounded-lg btn-tap', onclick: function () { mostrarQR(state.codigo); } }, t('ui.lobby.btn_qr')),
            ]),
        ]));

        const botBox = el('section', { id: 'botReserva', class: 'hidden bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('p', { class: 'text-sm text-slate-300 text-center mb-3' }, t('ui.lobby.bot_reserva')),
            crearSelectorDificultad('mb-4'),
            el('button', {
                id: 'btnBotReserva',
                class: 'w-full bg-slate-700 text-slate-200 py-3 rounded-lg btn-tap text-sm',
                onclick: jugarBotDesdeBusqueda,
            }, '🤖 ' + t('ui.lobby.btn_practicar')),
        ]);
        app.appendChild(botBox);
        setTimeout(function () {
            const box = document.getElementById('botReserva');
            if (box && state.codigo) box.classList.remove('hidden');
        }, 20000);

        app.appendChild(el('div', { class: 'text-center m-4' }, [
            el('button', { class: 'text-slate-400 text-sm btn-tap', onclick: cancelarBusqueda }, t('ui.lobby.btn_cancelar')),
        ]));
    }

    async function cancelarBusqueda() {
        if (state.codigo) {
            await api('POST', 'api/partida_rapida.php', { accion: 'cancelar', codigo: state.codigo, jugador_id: state.jugadorId });
            clearSession(state.codigo);
        }
        stopPollingLobby();
        state.codigo = null;
        state.jugadorId = null;
        renderInitialView();
    }

    /** Deja la cola y arranca una partida de práctica con la temática de la sala. */
    async function jugarBotDesdeBusqueda() {
        if (!state.codigo) return;
        const tematica = (state.sala && state.sala.tematica) ? state.sala.tematica : TEMATICA_RANDOM;
        const nombre = state.jugadorNombre || 'Tú';
        const btn = $('#btnBotReserva');
        if (btn) { btn.disabled = true; btn.classList.add('opacity-50'); }
        await api('POST', 'api/partida_rapida.php', { accion: 'cancelar', codigo: state.codigo, jugador_id: state.jugadorId });
        stopPollingLobby();
        clearSession(state.codigo);
        state.codigo = null;
        await iniciarPartidaBot(tematica, state.botDificultad || 'normal', nombre);
    }

    let lobbyVisibilidadLista = false;

    function startPollingLobby(ms) {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
        state.pollTimer = setInterval(pollLobbyTick, ms || POLL_MS);
        // En segundo plano no se corta el poll (móvil): se baja la cadencia.
        if (!lobbyVisibilidadLista) {
            lobbyVisibilidadLista = true;
            document.addEventListener('visibilitychange', function () {
                if (!state.pollTimer) return;
                startPollingLobby(document.hidden ? POLL_OCULTO_MS : POLL_MS);
            });
            window.addEventListener('pagehide', function () { stopPollingLobby(); });
            window.addEventListener('pageshow', function () {
                if (!state.pollTimer && state.codigo && document.getElementById('btnRapida')) startPollingLobby();
            });
        }
        pollLobbyTick();
    }
    function stopPollingLobby() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    // =================== EXPOSE ===================
    window.__init = indexInit;

    // API compartida con app.game.js (vista de juego).
    window.DraftApp = {
        state: state,
        t: t, tItem: tItem, tTematica: tTematica,
        tematicaEmoji: tematicaEmoji,
        api: api, toast: toast, vibrate: vibrate,
        copyLink: copyLink, shareWhatsApp: shareWhatsApp,
        el: el, clear: clear, showModal: showModal,
        $: $,
        loadSession: loadSession, saveSession: saveSession, clearSession: clearSession,
        loadStats: loadStats, registrarResultado: registrarResultado,
        TEMATICA_RANDOM: TEMATICA_RANDOM,
        resolverTematica: resolverTematica,
        renderTematicaSelector: renderTematicaSelector,
        mostrarQR: mostrarQR,
        emojiImg: emojiImg,
        emojiSlug: emojiSlug,
        LOGROS_IDS: LOGROS_IDS,
        LOGROS_META: LOGROS_META,
        logroIcono: logroIcono,
        leerSerie: leerSerie,
        actualizarSerie: actualizarSerie,
        registrarHistorial: registrarHistorial,
        sfx: sfx,
        toggleSonido: toggleSonido,
        showRulesModal: showRulesModal,
        evento: evento,
        iniciarPartidaBot: iniciarPartidaBot,
    };
})();
