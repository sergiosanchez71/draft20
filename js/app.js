/* Draft 20 - app.js
 * Vanilla JS. Helpers (t, tItem, tTematica, api, toast, vibrate, copyLink, shareWhatsApp)
 * + lobby and game views.
 */
(function () {
    'use strict';

    const POLL_MS = 1000;

    const state = {
        codigo: null,
        jugadorId: null,
        jugadorSlot: null,
        jugadorNombre: null,
        rivalNombre: null,
        sala: null,
        pollTimer: null,
        isPolling: false,
        actionInFlight: false,
        abandonoDetectadoVibrado: false,
        tematicaSeleccionada: null,
        tematicaCreada: null,
        pollFailures: 0,
        pollInFlight: false,
        rivalAusente: null,
        ultimoEmoteEnviado: 0,
        botWaitUntil: 0,
        botKeyDelay: null,
        botValores: null,
        botDificultad: 'normal',
        statsRegistradas: false,
        ultimoEmoteTs: 0,
        bot: null,
        botTurnoKey: null,
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
        localStorage.setItem(keyFor(state.codigo), JSON.stringify({
            jugadorId: state.jugadorId,
            jugadorNombre: state.jugadorNombre,
        }));
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

    // =================== toast ===================
    let toastTimer = null;
    function toast(msg) {
        let el = document.getElementById('toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('show'); }, 2000);
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
        function close() { overlay.remove(); }
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        box.appendChild(content);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
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
        }
    }

    function renderInitialView() {
        const app = $('#app');
        clear(app);
        const st = loadStats();
        const hayStats = (st.wins || st.losses || st.draws);
        app.appendChild(el('header', { class: 'p-6 text-center safe-pt relative' }, [
            el('h1', { class: 'text-3xl font-bold text-amber-400' }, t('ui.app.titulo')),
            el('p', { class: 'text-slate-400 text-sm mt-1' }, t('ui.app.subtitulo_lobby')),
            hayStats
                ? el('p', { class: 'text-amber-300/80 text-xs mt-1 font-mono' }, t('ui.lobby.stats_record', { v: st.wins || 0, d: st.losses || 0, e: st.draws || 0 }))
                : null,
            el('button', {
                class: 'absolute top-4 right-4 w-9 h-9 rounded-full bg-slate-700 text-slate-200 text-sm font-bold btn-tap',
                'aria-label': t('ui.lobby.btn_reglas'),
                title: t('ui.lobby.btn_reglas'),
                onclick: showRulesModal,
            }, '?'),
        ]));

        const selectorBox = el('div', { id: 'tematicaSelector', class: 'mb-4' });

        const createForm = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-2' }, t('ui.lobby.selector_tematica')),
            selectorBox,
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.input_nombre_jugador')),
            el('input', { id: 'nameCreate', type: 'text', maxlength: '20', placeholder: t('ui.lobby.placeholder_nombre'), class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base' }),
            el('button', { id: 'btnCreate', class: 'w-full bg-amber-400 text-slate-900 font-bold py-4 rounded-lg btn-tap text-lg' }, t('ui.lobby.btn_crear')),
        ]);

        const joinForm = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.label_unirse')),
            el('input', { id: 'codeJoin', type: 'text', maxlength: '5', minlength: '5', placeholder: t('ui.lobby.placeholder_codigo'), class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base uppercase tracking-widest text-center text-2xl font-mono' }),
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.input_nombre_jugador')),
            el('input', { id: 'nameJoin', type: 'text', maxlength: '20', placeholder: 'Jugador 2', class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base' }),
            el('button', { id: 'btnJoin', class: 'w-full bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-lg' }, t('ui.lobby.btn_unirse')),
        ]);

        const errorBox = el('div', { id: 'lobbyError', class: 'hidden bg-rose-500 text-white p-3 rounded-lg mx-4 mb-4 text-sm text-center' });

        // Dificultad del bot (persistida entre sesiones).
        try {
            const difGuardada = localStorage.getItem('draft20_bot_dificultad');
            if (difGuardada) state.botDificultad = difGuardada;
        } catch (e) { /* ignore */ }
        const difOpciones = [['facil', 'ui.lobby.bot_facil'], ['normal', 'ui.lobby.bot_normal'], ['dificil', 'ui.lobby.bot_dificil']];
        const difBtns = [];
        const difWrap = el('div', { class: 'flex gap-2 justify-center mt-2' });
        function pintarDificultad() {
            difBtns.forEach(function (b, i) {
                const activo = state.botDificultad === difOpciones[i][0];
                b.className = 'px-3 py-1.5 rounded-full text-xs border btn-tap ' +
                    (activo ? 'bg-amber-400 text-slate-900 border-amber-400 font-bold' : 'bg-slate-700 text-slate-200 border-slate-600');
            });
        }
        difOpciones.forEach(function (d) {
            const b = el('button', {
                type: 'button',
                onclick: function () {
                    state.botDificultad = d[0];
                    try { localStorage.setItem('draft20_bot_dificultad', d[0]); } catch (e) { /* ignore */ }
                    pintarDificultad();
                },
            }, t(d[1]));
            difBtns.push(b);
            difWrap.appendChild(b);
        });
        pintarDificultad();

        const practiceBtn = el('div', { class: 'm-4' }, [
            el('button', {
                id: 'btnPractice',
                class: 'w-full bg-slate-700 text-slate-200 py-3 rounded-lg btn-tap text-sm',
                onclick: onPractice,
            }, t('ui.lobby.btn_practicar')),
            el('div', { class: 'text-center text-[11px] text-slate-500 mt-3' }, t('ui.lobby.bot_dificultad')),
            difWrap,
        ]);

        app.appendChild(errorBox);
        app.appendChild(createForm);
        app.appendChild(el('div', { class: 'text-center text-slate-500 text-xs my-2' }, '— o —'));
        app.appendChild(joinForm);
        app.appendChild(practiceBtn);

        $('#btnCreate').addEventListener('click', onCreate);
        $('#btnJoin').addEventListener('click', onJoin);

        renderTematicaSelector(selectorBox, { ctx: state });

        // Primer ingreso: mostrar reglas automáticamente.
        try {
            if (!localStorage.getItem('draft20_reglas')) showRulesModal();
        } catch (e) { /* ignore */ }
    }

    const TEMATICA_RANDOM = '__random__';

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
        const r = await api('POST', 'api/crear_sala.php', { tematica: tematica, nombre: nombre });
        btn.disabled = false; btn.classList.remove('opacity-50');
        if (!r.ok) { showLobbyError(r.error || 'Error'); vibrate([100, 50, 100]); return; }
        state.codigo = r.codigo;
        state.jugadorId = r.jugador_id;
        state.jugadorNombre = (nombre || '').trim() || ('Jugador 1');
        state.tematicaCreada = tematica;
        saveSession();
        renderCreatorView();
        startPollingLobby();
    }

    /**
     * Crea una partida de práctica: sala nueva + bot sentado como J2.
     * Usada por "Practicar vs 🤖" y por la revancha contra bot (misma dificultad).
     */
    async function iniciarPartidaBot(tematica, dificultad, nombre) {
        const nombreFinal = (nombre && nombre.trim()) || state.jugadorNombre || 'Tú';
        const r = await api('POST', 'api/crear_sala.php', { tematica: tematica, nombre: nombreFinal });
        if (!r.ok) { toast(r.error || 'Error'); return false; }
        const r2 = await api('POST', 'api/unirse_sala.php', { codigo: r.codigo, nombre: '🤖 Bot' });
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
        saveSession();
        window.location.href = 'juego.php?codigo=' + encodeURIComponent(r.codigo);
        return true;
    }

    async function onPractice() {
        const tematica = resolverTematica(state);
        const nombre = ($('#nameCreate') && $('#nameCreate').value.trim()) || 'Tú';
        const dificultad = state.botDificultad || 'normal';
        const btn = $('#btnPractice');
        if (btn) { btn.disabled = true; btn.classList.add('opacity-50'); }
        await iniciarPartidaBot(tematica, dificultad, nombre);
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
        saveSession();
        // J2 entra directamente al juego
        window.location.href = 'juego.php?codigo=' + encodeURIComponent(codigo);
    }

    function renderCreatorView() {
        const app = $('#app');
        clear(app);
        app.appendChild(el('header', { class: 'p-6 text-center safe-pt' }, [
            el('h1', { class: 'text-3xl font-bold text-amber-400' }, t('ui.app.titulo')),
            el('p', { class: 'text-slate-400 text-sm mt-1' }, tTematica(state.sala?.tematica || state.tematicaCreada || '')),
        ]));

        app.appendChild(el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 text-center fade-in' }, [
            el('p', { class: 'text-sm text-slate-400 mb-2' }, t('ui.lobby.lbl_invitacion')),
            el('div', { class: 'text-5xl font-mono font-bold text-amber-400 tracking-widest my-4' }, state.codigo),
            el('div', { class: 'flex gap-2 mt-4' }, [
                el('button', { class: 'flex-1 bg-slate-700 text-slate-100 py-3 rounded-lg btn-tap', onclick: function () { copyLink(state.codigo); } }, t('ui.lobby.btn_copiar')),
                el('button', { class: 'flex-1 bg-emerald-500 text-white py-3 rounded-lg btn-tap', onclick: function () { shareWhatsApp(state.codigo); } }, t('ui.lobby.btn_whatsapp')),
            ]),
        ]));

        app.appendChild(el('section', { id: 'waitingBox', class: 'bg-slate-800 p-6 rounded-lg m-4 text-center fade-in' }, [
            el('div', { class: 'inline-block animate-spin rounded-full h-8 w-8 border-4 border-slate-600 border-t-amber-400 mb-3' }),
            el('p', { class: 'text-slate-300 text-sm' }, t('ui.lobby.esperando_rival_join')),
        ]));
    }

    async function pollLobbyTick() {
        if (!state.codigo) return;
        const r = await api('GET', 'api/estado.php?codigo=' + encodeURIComponent(state.codigo) + '&t=' + Date.now());
        if (!r.ok) return;
        state.sala = r.sala;
        if (r.sala.estado === 'jugando' || r.sala.estado === 'finalizada') {
            stopPollingLobby();
            window.location.href = 'juego.php?codigo=' + encodeURIComponent(state.codigo);
        }
    }

    function startPollingLobby() {
        if (state.pollTimer) return;
        state.pollTimer = setInterval(pollLobbyTick, POLL_MS);
        pollLobbyTick();
    }
    function stopPollingLobby() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    // =================== JUEGO ===================
    async function juegoInit(codigo) {
        if (!codigo || !/^[A-Z0-9]{5}$/.test(codigo)) {
            window.location.href = 'index.php';
            return;
        }
        const session = loadSession(codigo);
        if (!session || !session.jugadorId) {
            window.location.href = 'index.php?sala=' + encodeURIComponent(codigo);
            return;
        }
        state.codigo = codigo;
        state.jugadorId = session.jugadorId;
        state.jugadorNombre = session.jugadorNombre;

        // ¿Partida de práctica contra el bot?
        try {
            const botRaw = localStorage.getItem('draft20_bot_' + codigo);
            state.bot = botRaw ? JSON.parse(botRaw) : null;
            if (state.bot && !state.bot.jugadorId) state.bot = null;
        } catch (e) { state.bot = null; }

        renderGameShell();
        await pollGameTick();
        startPollingGame();

        // Al volver a la pestaña, poll inmediato (evita last_seen obsoleto).
        document.addEventListener('visibilitychange', function () {
            if (!state.codigo) return;
            if (document.hidden) {
                stopPollingGame();
            } else if (document.getElementById('scoreboard')) {
                pollGameTick().then(function () { startPollingGame(); });
            }
        });
    }

    function renderGameShell() {
        const app = $('#app');
        clear(app);
        app.className = 'flex-1 flex flex-col min-h-0 overflow-hidden';

        // Header
        const header = el('header', { class: 'flex items-center justify-between px-4 py-3 bg-slate-800 border-b border-slate-700 safe-pt' }, [
            el('button', {
                id: 'btnLeave',
                class: 'text-slate-400 text-sm btn-tap',
                onclick: onLeave,
                'aria-label': t('ui.juego.salir_lobby'),
            }, '← ' + t('ui.juego.salir_lobby')),
            el('div', { class: 'text-center flex-1 px-2' }, [
                el('h1', { class: 'text-base font-bold text-amber-400 leading-tight' }, t('ui.app.titulo')),
                el('div', { id: 'headerTematica', class: 'text-[11px] text-slate-400 leading-tight truncate' }, ''),
            ]),
            el('div', { class: 'w-16' }),
        ]);

        // Banner de desconexión (polling caído)
        const offlineBanner = el('div', {
            id: 'offlineBanner',
            class: 'hidden bg-rose-600 text-white text-xs text-center py-2 px-3',
            role: 'status',
        }, t('ui.juego.sin_conexion'));

        // Scoreboard
        const scoreboard = el('section', { id: 'scoreboard', class: 'bg-slate-800 px-4 py-3 grid grid-cols-2 gap-3 border-b border-slate-700' });

        // Item card (min-h-0 permite que se encoja; el historial va en absoluto a la derecha)
        const itemCard = el('section', { id: 'itemCard', class: 'relative flex-1 min-h-0 flex flex-col items-center justify-center p-6 bg-slate-900 overflow-y-auto' });

        // Inventory row (flex-shrink-0 garantiza que no se comprima)
        const inventory = el('section', { id: 'inventory', class: 'flex-shrink-0 bg-slate-800 px-4 py-3 border-t border-slate-700' });

        // Emotes rápidos (compactos para no empujar la barra de acciones)
        const emoteBar = el('section', { id: 'emoteBar', class: 'flex-shrink-0 bg-slate-800 px-3 pb-0.5 flex gap-2 justify-center' });
        ['👍', '😂', '🔥', '😭', '🤝', '😱'].forEach(function (code) {
            emoteBar.appendChild(el('button', {
                class: 'text-lg leading-none px-2 py-0.5 rounded btn-tap opacity-80 hover:opacity-100',
                'aria-label': 'Emote ' + code,
                onclick: function () { onEmote(code); },
            }, code));
        });

        // Action bar (en flujo, no fija — evita tapar inventario en pantallas pequeñas)
        const actionBar = el('section', { id: 'actionBar', class: 'flex-shrink-0 bg-slate-800 border-t border-slate-700 p-3 safe-pb-action z-10' });

        app.appendChild(header);
        app.appendChild(offlineBanner);
        app.appendChild(scoreboard);
        app.appendChild(itemCard);
        app.appendChild(inventory);
        app.appendChild(emoteBar);
        app.appendChild(actionBar);
    }

    function setOfflineBanner(show) {
        const b = document.getElementById('offlineBanner');
        if (!b) return;
        if (show) b.classList.remove('hidden'); else b.classList.add('hidden');
    }

    async function pollGameTick() {
        if (!state.codigo || state.pollInFlight) return;
        state.pollInFlight = true;
        let r;
        try {
            r = await api('GET', 'api/estado.php?codigo=' + encodeURIComponent(state.codigo) + '&jugador_id=' + encodeURIComponent(state.jugadorId) + '&t=' + Date.now());
        } finally {
            state.pollInFlight = false;
        }
        if (!r.ok) {
            state.pollFailures = (state.pollFailures || 0) + 1;
            if (state.pollFailures >= 3) setOfflineBanner(true);
            return;
        }
        state.pollFailures = 0;
        setOfflineBanner(false);

        const prev = state.sala;
        state.sala = r.sala;
        state.rivalAusente = (r.rival_ausente === undefined || r.rival_ausente === null) ? null : r.rival_ausente;
        identifySlots();

        detectarAccionesRival(prev, state.sala);
        detectarEmotes(state.sala);

        // Detectar transición a abandono (vibración única).
        if (prev && state.sala.estado === 'abandonada' && prev.estado !== 'abandonada' && !state.abandonoDetectadoVibrado) {
            state.abandonoDetectadoVibrado = true;
            vibrate([200, 100, 200]);
        }

        renderGame(prev);

        // Bot de práctica.
        if (state.bot) {
            cargarValoresBotSiToca();
            botTick();
        }
    }

    function detectarAccionesRival(prev, sala) {
        if (!prev || !sala || state.jugadorSlot === null) return;
        const rivalSlot = 1 - state.jugadorSlot;
        const prevItem = prev.item_actual;
        const item = sala.item_actual;

        // Puja del rival sobre el mismo ítem.
        if (prevItem && item && prevItem.id === item.id && (item.precio_actual || 0) > (prevItem.precio_actual || 0)) {
            const pujas = item.pujas || [];
            const last = pujas.length ? pujas[pujas.length - 1] : null;
            if (last && last.por === rivalSlot) {
                toast(t('ui.juego.rival_puja', { n: last.incremento }));
                vibrate(30);
            }
        }

        // El rival ganó un ítem.
        const prevCount = (prev.jugadores[rivalSlot]?.items_ganados || []).length;
        const nowItems = sala.jugadores[rivalSlot]?.items_ganados || [];
        if (nowItems.length > prevCount) {
            const nuevo = nowItems[nowItems.length - 1];
            toast(t('ui.juego.perdio_item', {
                rival: state.rivalNombre || 'Rival',
                item: tItem(nuevo.id),
                precio: (nuevo.precio || 0) + '🪙',
            }));
            vibrate([80, 40, 80]);
        }
    }

    function detectarEmotes(sala) {
        if (!sala || !Array.isArray(sala.emotes) || state.jugadorSlot === null) return;
        let maxTs = state.ultimoEmoteTs || 0;
        sala.emotes.forEach(function (e) {
            const ts = e.ts || 0;
            if (ts > (state.ultimoEmoteTs || 0) && e.por !== state.jugadorSlot) {
                mostrarEmote(e.code, state.rivalNombre || 'Rival');
            }
            if (ts > maxTs) maxTs = ts;
        });
        state.ultimoEmoteTs = maxTs;
    }

    function mostrarEmote(code, nombre) {
        const b = el('div', {
            class: 'fixed left-1/2 top-24 -translate-x-1/2 z-40 flex items-center gap-2 bg-slate-800 border border-amber-400 rounded-full pl-3 pr-4 py-1.5 pop-emote',
            'aria-label': nombre + ' dice ' + code,
        }, [
            el('span', { class: 'text-2xl leading-none' }, code),
            el('span', { class: 'text-xs font-bold text-amber-300 max-w-[9rem] truncate' }, nombre),
        ]);
        document.body.appendChild(b);
        setTimeout(function () { b.remove(); }, 2400);
    }

    async function onEmote(code) {
        // Throttle: evita spamear el JSON de la sala a base de toques.
        const ahora = Date.now();
        if (ahora - (state.ultimoEmoteEnviado || 0) < 700) return;
        state.ultimoEmoteEnviado = ahora;
        mostrarEmote(code, 'Tú');
        const r = await api('POST', 'api/accion.php', {
            codigo: state.codigo,
            jugador_id: state.jugadorId,
            accion: 'emote',
            emote: code,
        });
        if (r && r.ok && r.sala && Array.isArray(r.sala.emotes) && r.sala.emotes.length) {
            const last = r.sala.emotes[r.sala.emotes.length - 1].ts || 0;
            if (last > (state.ultimoEmoteTs || 0)) state.ultimoEmoteTs = last;
        }
    }

    // =================== bot de práctica ===================
    async function botTick() {
        const s = state.sala;
        if (!s || !state.bot || s.estado !== 'jugando' || !s.item_actual || state.jugadorSlot === null) return;
        if (!window.DraftBot || !window.DraftBot.estadoKey) return;
        const botSlot = 1 - state.jugadorSlot;
        // La key incluye decision_pendiente: si no, el bot no reaccionaría al
        // PASAR del humano (mismo ítem/precio/turno/pujas) y la partida se colgaría.
        const key = window.DraftBot.estadoKey(s);
        if (state.botTurnoKey === key) return;

        const dificultad = state.bot.dificultad || 'normal';

        // Delay humano: al empezar una situación nueva, espera un poco.
        if (state.botKeyDelay !== key) {
            const cfg = window.DraftBot.config(dificultad);
            const rango = cfg.delayMs || [700, 1800];
            state.botKeyDelay = key;
            state.botWaitUntil = Date.now() + rango[0] + Math.random() * (rango[1] - rango[0]);
            return;
        }
        if (Date.now() < state.botWaitUntil) return;

        const decision = window.DraftBot.decidir(s, botSlot, dificultad, Math.random, state.botValores || null);
        if (!decision) return; // sin acción: NO marcar la situación como resuelta

        const r = await botAction(decision);
        if (r && r.ok) {
            state.botTurnoKey = key;
        } else {
            // Falló (carrera, estado cambiado): reintentar con delay humano.
            state.botTurnoKey = null;
            state.botKeyDelay = null;
        }
    }

    async function botAction(body) {
        const payload = Object.assign({ codigo: state.codigo, jugador_id: state.bot.jugadorId }, body);
        return await api('POST', 'api/accion.php', payload);
    }

    /**
     * El bot "difícil" conoce los valores reales: carga el catálogo público de
     * temáticas una vez conocida la temática de la sala.
     */
    async function cargarValoresBotSiToca() {
        if (!state.bot || state.bot.dificultad !== 'dificil' || state.botValores || !state.sala || !state.sala.tematica) return;
        try {
            const resp = await fetch('tematicas/' + encodeURIComponent(state.sala.tematica) + '.json');
            const data = await resp.json();
            const map = {};
            (data.items || []).forEach(function (it) { map[it.id] = it.valor; });
            state.botValores = map;
        } catch (e) { state.botValores = null; }
    }

    function startPollingGame() {
        if (state.pollTimer) return;
        state.pollTimer = setInterval(pollGameTick, POLL_MS);
    }
    function stopPollingGame() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    function identifySlots() {
        const s = state.sala;
        if (!s) return;
        for (let i = 0; i < s.jugadores.length; i++) {
            if (s.jugadores[i] && s.jugadores[i].id === state.jugadorId) {
                state.jugadorSlot = i;
                state.rivalNombre = s.jugadores[1 - i]?.nombre || ('Jugador ' + (i + 2 === 2 ? 2 : 1));
                break;
            }
        }
    }

    function renderGame(prev) {
        if (!state.sala) return;
        const s = state.sala;

        // Detectar nuevos ítems para animación y vibración
        if (prev && state.jugadorSlot !== null) {
            const prevItems = (prev.jugadores[state.jugadorSlot]?.items_ganados || []).map(function (i) { return i.id; }).join(',');
            const nowItems = (s.jugadores[state.jugadorSlot]?.items_ganados || []).map(function (i) { return i.id; }).join(',');
            if (nowItems.length > prevItems.length) {
                vibrate([40, 30, 80]);
            }
        }

        // Header: temática en curso
        const ht = document.getElementById('headerTematica');
        if (ht && s.tematica) ht.textContent = tematicaEmoji(s.tematica) + ' ' + tTematica(s.tematica);

        // Scoreboard
        renderScoreboard();

        // Sala esperando (p.ej. revancha propuesta y aún sin aceptar).
        if (s.estado === 'esperando') {
            renderEsperandoRival();
            const inv0 = $('#inventory'); if (inv0) clear(inv0);
            const bar0 = $('#actionBar'); if (bar0) clear(bar0);
            const eb0 = $('#emoteBar'); if (eb0) clear(eb0);
            return;
        }

        // Estado terminal: abandono manda sobre finalización normal.
        if (s.estado === 'abandonada') {
            renderAbandonedScreen();
            // Vaciar inventarios + bar para que no aparezca UI residual.
            const inv = $('#inventory'); if (inv) clear(inv);
            const bar = $('#actionBar'); if (bar) clear(bar);
            const eb = $('#emoteBar'); if (eb) clear(eb);
            return;
        }
        if (s.estado === 'finalizada') {
            renderFinalScreen();
            const inv = $('#inventory'); if (inv) clear(inv);
            const bar = $('#actionBar'); if (bar) clear(bar);
            const eb = $('#emoteBar'); if (eb) clear(eb);
            return;
        }

        // Item card
        renderItemCard();

        // Inventory
        renderInventory();

        // Action bar
        renderActionBar();
    }

    function renderScoreboard() {
        const sb = $('#scoreboard');
        if (!sb || state.jugadorSlot === null) return;
        clear(sb);
        const s = state.sala;
        const me = s.jugadores[state.jugadorSlot];
        const rival = s.jugadores[1 - state.jugadorSlot];
        const rivalEsBot = !!(state.bot && rival && state.bot.jugadorId === rival.id);
        const myTurn = s.item_actual && s.item_actual.turno_de === state.jugadorSlot;
        const rivalTurn = s.item_actual && s.item_actual.turno_de === (1 - state.jugadorSlot);

        const myCard = el('div', { class: 'rounded-lg p-3 ' + (myTurn ? 'bg-amber-400 text-slate-900' : 'bg-slate-700 text-slate-100') }, [
            el('div', { class: 'text-xs font-semibold opacity-80' }, t('ui.juego.lbl_mi_dinero') + ' · ' + (me?.nombre || '')),
            el('div', { class: 'flex items-baseline gap-1 mt-1' }, [
                el('span', { class: 'text-2xl font-bold font-mono' }, String(me?.dinero ?? 0)),
                el('span', { class: 'text-xs opacity-80' }, t('ui.juego.monedas')),
            ]),
            el('div', { class: 'text-xs mt-1 opacity-80' }, (me?.items_ganados?.length || 0) + '/4'),
        ]);

        const rivalCard = el('div', { class: 'rounded-lg p-3 ' + (rivalTurn ? 'bg-rose-500 text-white' : 'bg-slate-700 text-slate-100') }, [
            el('div', { class: 'text-xs font-semibold opacity-80' }, t('ui.juego.lbl_rival_dinero') + ' · ' + (rival?.nombre || '') + (rivalEsBot ? ' 🤖' : '')),
            el('div', { class: 'flex items-baseline gap-1 mt-1' }, [
                el('span', { class: 'text-2xl font-bold font-mono' }, String(rival?.dinero ?? 0)),
                el('span', { class: 'text-xs opacity-80' }, t('ui.juego.monedas')),
            ]),
            el('div', { class: 'text-xs mt-1 opacity-80' }, (rival?.items_ganados?.length || 0) + '/4'),
        ]);

        sb.appendChild(myCard);
        sb.appendChild(rivalCard);
    }

    function renderItemCard() {
        const card = $('#itemCard');
        if (!card) return;
        clear(card);
        const s = state.sala;
        const item = s.item_actual;
        if (!item) return;

        const myTurn = item.turno_de === state.jugadorSlot;
        const turnoText = myTurn ? t('ui.juego.tu_turno') : t('ui.juego.turno_rival');

        // Aviso blando: el rival lleva sin responder unos segundos.
        if (state.rivalAusente !== null && state.rivalAusente >= 6 && s.estado === 'jugando') {
            card.appendChild(el('div', { class: 'w-full bg-amber-500 text-slate-900 text-xs font-bold text-center py-2 px-3 rounded mb-3' },
                t('ui.juego.msg_rival_ausente', { nombre: state.rivalNombre || 'Rival', seg: state.rivalAusente })));
        }

        const totalRondas = (s.items_mezclados && s.items_mezclados.length) ? s.items_mezclados.length : 8;
        card.appendChild(el('div', { class: 'text-xs text-slate-400 mb-2' }, t('ui.juego.ronda') + ' ' + s.ronda + ' / ' + totalRondas));
        if (s.ronda >= totalRondas) {
            card.appendChild(el('div', { class: 'text-xs font-bold text-amber-300 mb-2' }, '🔥 ' + t('ui.juego.ultima_ronda')));
        }

        const emoji = el('div', { class: 'text-8xl mb-3 ' + (myTurn ? 'pulse-win' : '') }, item.emoji);
        card.appendChild(emoji);

        const itemName = tItem(item.id);
        card.appendChild(el('h2', { class: 'text-xl font-bold text-slate-100 mb-2 text-center px-4' }, itemName));

        // Precio y turno
        const status = el('div', { class: 'mt-4 text-center' }, [
            el('div', { class: 'text-xs text-slate-400' }, t('ui.juego.precio_actual')),
            el('div', { class: 'text-4xl font-bold font-mono text-amber-400' }, String(item.precio_actual)),
        ]);
        card.appendChild(status);

        // Historial de pujas: barra a la derecha, la más reciente abajo (las viejas suben).
        const pujas = Array.isArray(item.pujas) ? item.pujas : [];
        card.classList.toggle('pr-20', pujas.length > 0);
        card.classList.toggle('sm:pr-24', pujas.length > 0);
        if (pujas.length) {
            const rail = el('div', {
                id: 'bidHistory',
                class: 'absolute right-2 top-16 bottom-24 w-20 sm:w-24 flex flex-col justify-end gap-1 overflow-hidden pointer-events-none',
            });
            const visibles = pujas.slice(-6);
            visibles.forEach(function (p, idx) {
                const esUltima = idx === visibles.length - 1;
                const soyYo = p.por === state.jugadorSlot;
                const nombre = soyYo ? 'Tú' : (state.rivalNombre || 'Rival');
                rail.appendChild(el('div', {
                    class: 'rounded px-1.5 py-1 text-right text-[10px] leading-tight bg-slate-800/95 border ' +
                        (esUltima
                            ? 'border-amber-400 ' + (soyYo ? 'text-emerald-300' : 'text-rose-300') + ' fade-in'
                            : 'border-slate-700 text-slate-300'),
                }, [
                    el('div', { class: 'font-bold truncate' }, nombre),
                    el('div', { class: 'font-mono opacity-90' }, '+' + p.incremento + ' · ' + p.precio + '🪙'),
                ]));
            });
            card.appendChild(rail);
        }

        const turnoBanner = el('div', { class: 'mt-4 px-4 py-2 rounded-full text-sm font-bold ' + (myTurn ? 'bg-emerald-500 text-white' : 'bg-slate-700 text-slate-300') }, turnoText);
        card.appendChild(turnoBanner);

        // Banner ámbar si hay decisión pendiente (deadlock sin dinero).
        if (s.decision_pendiente) {
            const dp = s.decision_pendiente;
            const isDecisor = dp.para === state.jugadorSlot;
            const isSobre    = dp.sobre === state.jugadorSlot;
            const msg = isDecisor
                ? t('ui.juego.msg_deadlock_decide_corto')
                : (isSobre ? t('ui.juego.msg_cediste_item') : t('ui.juego.msg_cede_item'));
            card.appendChild(el('div', { class: 'mt-3 mx-4 px-3 py-2 rounded bg-amber-400 text-slate-900 text-xs font-semibold text-center' }, msg));
        }

        // Si estoy capped, mensaje varía según de quién es el turno
        const myCount = (s.jugadores[state.jugadorSlot]?.items_ganados || []).length;
        if (myCount >= 4) {
            const msg = myTurn
                ? t('ui.juego.msg_pasar_al_rival', { cap: 4 })
                : t('ui.juego.msg_esperando_rival_complete', { cap: 4 });
            card.appendChild(el('div', { class: 'mt-3 px-3 py-2 rounded bg-amber-400 text-slate-900 text-xs font-semibold text-center' }, msg));
        }
    }

    function itemTooltip(i) {
        // El valor (⭐) es secreto hasta la pantalla final: no se revela aquí.
        const name = tItem(i.id);
        const p = i.precio != null ? i.precio : '?';
        return name + ' · 🪙' + p;
    }

    function renderInventory() {
        const inv = $('#inventory');
        if (!inv) return;
        clear(inv);
        const s = state.sala;
        const myItems = s.jugadores[state.jugadorSlot]?.items_ganados || [];
        const rivalItems = s.jugadores[1 - state.jugadorSlot]?.items_ganados || [];

        const myRow = el('div', { class: 'mb-3' }, [
            el('div', { class: 'text-xs text-slate-400 mb-1 flex items-center gap-1' }, [
                el('span', { class: 'inline-block w-1.5 h-1.5 rounded-full bg-amber-400' }),
                t('ui.juego.lbl_tu_inv') + ' · ' + (myItems.length) + '/4',
            ]),
            el('div', { class: 'flex gap-1 overflow-x-auto no-scrollbar pb-1 min-h-[44px]' },
                myItems.length === 0
                    ? [el('span', { class: 'text-slate-500 text-xs italic' }, t('ui.juego.sin_items'))]
                    : myItems.map(function (i) {
                        const wrap = el('div', { class: 'flex-shrink-0 w-10 h-12 flex flex-col items-center' }, [
                            el('div', { class: 'w-10 h-10 bg-slate-700 border-l-2 border-amber-400 rounded flex items-center justify-center text-2xl', title: itemTooltip(i) }, i.emoji),
                        ]);
                        if (i.precio != null) wrap.appendChild(el('div', { class: 'text-[10px] text-amber-400 leading-none mt-0.5 font-mono' }, i.precio + '🪙'));
                        return wrap;
                    })
            ),
        ]);

        const rivalRow = el('div', { class: 'pt-2 border-t border-slate-700' }, [
            el('div', { class: 'text-xs text-slate-400 mb-1 flex items-center gap-1' }, [
                el('span', { class: 'inline-block w-1.5 h-1.5 rounded-full bg-rose-500' }),
                (t('ui.juego.lbl_rival_inv') + ' · ' + (s.jugadores[1 - state.jugadorSlot]?.nombre || 'Rival') + ' · ' + (rivalItems.length) + '/4'),
            ]),
            el('div', { class: 'flex gap-1 overflow-x-auto no-scrollbar pb-1 min-h-[44px]' },
                rivalItems.length === 0
                    ? [el('span', { class: 'text-slate-500 text-xs italic' }, t('ui.juego.sin_items'))]
                    : rivalItems.map(function (i) {
                        const wrap = el('div', { class: 'flex-shrink-0 w-10 h-12 flex flex-col items-center' }, [
                            el('div', { class: 'w-10 h-10 bg-slate-700 border-l-2 border-rose-500 rounded flex items-center justify-center text-2xl', title: itemTooltip(i) }, i.emoji),
                        ]);
                        if (i.precio != null) wrap.appendChild(el('div', { class: 'text-[10px] text-rose-300 leading-none mt-0.5 font-mono' }, i.precio + '🪙'));
                        return wrap;
                    })
            ),
        ]);

        inv.appendChild(myRow);
        inv.appendChild(rivalRow);
    }

    function renderActionBar() {
        const bar = $('#actionBar');
        if (!bar) return;
        clear(bar);
        const s = state.sala;
        if (s.estado === 'finalizada') {
            bar.appendChild(el('div', { class: 'text-center text-slate-300 text-sm py-2' }, t('ui.juego.fin_titulo')));
            return;
        }
        const item = s.item_actual;
        if (!item) {
            bar.appendChild(el('div', { class: 'text-center text-slate-400 py-2' }, t('ui.juego.esperando_rival')));
            return;
        }

        const myTurn = item.turno_de === state.jugadorSlot;
        const myCount = (s.jugadores[state.jugadorSlot]?.items_ganados || []).length;
        const rivalCount = (s.jugadores[1 - state.jugadorSlot]?.items_ganados || []).length;
        const capped = myCount >= 4;
        const canBajar = item.ultimo_pujo !== null;

        // FASE 2: soy el decisor (puede o no ser mi turno nominal).
        // El decisor puede tener turno_de apuntando al "sobre" porque PASAR
        // no avanza el turno, solo marca decision_pendiente. Por eso este
        // check va ANTES del filtro !myTurn.
        if (s.decision_pendiente && s.decision_pendiente.para === state.jugadorSlot) {
            const rivalMoney = s.jugadores[state.jugadorSlot]?.dinero ?? 0;
            const sobre = s.decision_pendiente.sobre;
            const canKeep = rivalMoney >= 1;
            const buttons = el('div', { class: 'flex gap-2' });
            buttons.appendChild(el('button', {
                id: 'btnAsignarKeep',
                class: 'flex-1 bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-base disabled:opacity-40',
                onclick: function () { onAsignarRival(state.jugadorSlot, 1); },
                title: canKeep ? '' : t('ui.juego.err_no_puedes_pagar_1'),
            }, t('ui.juego.btn_me_lo_quedo_1')));
            buttons.appendChild(el('button', {
                id: 'btnAsignarGift',
                class: 'flex-1 bg-amber-400 text-slate-900 font-bold py-4 rounded-lg btn-tap text-base',
                onclick: function () { onAsignarRival(sobre, 0); },
            }, t('ui.juego.btn_se_lo_regalo')));
            bar.appendChild(buttons);
            if (!canKeep) {
                const bk = document.getElementById('btnAsignarKeep');
                if (bk) bk.disabled = true;
            }
            bar.appendChild(el('div', { class: 'text-center text-amber-300 text-xs mt-2' }, t('ui.juego.msg_deadlock_decide')));
            return;
        }

        if (!myTurn) {
            // No es mi turno: mostrar mensaje contextual.
            // Si soy el jugador sin dinero y el rival está decidiendo, mensaje específico.
            if (s.decision_pendiente && s.decision_pendiente.sobre === state.jugadorSlot) {
                bar.appendChild(el('div', { class: 'text-center text-slate-400 py-3' }, t('ui.juego.msg_esperando_decision_rival')));
                return;
            }
            const msg = capped ? t('ui.juego.msg_esperando_rival_pase', { cap: 4 }) : t('ui.juego.esperando_rival');
            bar.appendChild(el('div', { class: 'text-center text-slate-400 py-3' }, msg));
            return;
        }

        // ===== MI TURNO =====

        // Si soy el "sobre" (cedí el ítem con PASAR), item.turno_de puede seguir
        // apuntándome. Mostrar mensaje pasivo y no permitir más acciones.
        if (s.decision_pendiente && s.decision_pendiente.sobre === state.jugadorSlot) {
            bar.appendChild(el('div', { class: 'text-center text-slate-400 py-3' }, t('ui.juego.msg_esperando_decision_rival')));
            return;
        }

        // FASE 1: deadlock sin dinero → solo botón PASAR.
        // Guard !decision_pendiente: si ya hay una decisión activa, no mostrar PASAR.
        if ((s.jugadores[state.jugadorSlot]?.dinero ?? 0) === 0
            && item.precio_actual === 0
            && item.ultimo_pujo === null
            && !s.decision_pendiente) {
            bar.appendChild(el('button', {
                id: 'btnPasarDeadlock',
                class: 'w-full bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-base',
                onclick: onPasarDeadlock,
            }, t('ui.juego.btn_pasar_turno')));
            bar.appendChild(el('div', { class: 'text-center text-amber-300 text-xs mt-2' }, t('ui.juego.msg_deadlock_sin_dinero')));
            return;
        }

        // Caso especial: estoy capped → solo ME BAJO (o PASAR TURNO si nadie ha pujado).
        // El server hace el cap-handler que asigna el ítem al rival a precio 0.
        if (capped) {
            const rivalCapped = rivalCount >= 4;
            // Estando capped el server asigna el ítem al rival a precio 0: no paga nadie.
            const label = canBajar ? t('ui.juego.btn_bajar') : t('ui.juego.btn_pasar_turno');
            const buttons = el('div', { class: 'flex gap-2' });
            buttons.appendChild(el('button', { id: 'btnBajar', class: 'flex-1 bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-base', onclick: onBajar }, label));
            if (rivalCapped) {
                buttons.appendChild(el('button', { id: 'btnPujar3', class: 'bg-slate-700 text-slate-100 font-bold py-4 px-4 rounded-lg btn-tap text-sm', onclick: onPujar3 }, t('ui.juego.btn_pujar3')));
            }
            bar.appendChild(buttons);
            bar.appendChild(el('div', { class: 'text-center text-amber-300 text-xs mt-2' }, t('ui.juego.msg_cap_accion', { cap: 4 })));
            return;
        }

        // Caso normal: pujar +1, pujar +3, bajar
        const me = s.jugadores[state.jugadorSlot];
        const myMoney = me ? me.dinero : 0;
        const canPujar1 = (item.precio_actual + 1) <= myMoney;
        const canPujar3 = (item.precio_actual + 3) <= myMoney;

        // El precio solo lo paga el rival si él es el último pujador y no está capped.
        const rivalSlot = 1 - state.jugadorSlot;
        const rivalPaga = canBajar && item.ultimo_pujo === rivalSlot && rivalCount < 4;
        const bajarLabel = rivalPaga
            ? t('ui.juego.btn_bajar_rival_paga', { precio: item.precio_actual })
            : t('ui.juego.btn_bajar');

        const buttons = el('div', { class: 'flex gap-2' });
        buttons.appendChild(el('button', { id: 'btnPujar', class: 'flex-1 bg-amber-400 text-slate-900 font-bold py-4 rounded-lg btn-tap text-base disabled:opacity-40', onclick: onPujar, title: canPujar1 ? '' : t('ui.juego.item_no_presupuesto') }, t('ui.juego.btn_pujar')));
        buttons.appendChild(el('button', { id: 'btnPujar3', class: 'bg-slate-700 text-slate-100 font-bold py-4 px-4 rounded-lg btn-tap text-sm disabled:opacity-40', onclick: onPujar3, title: canPujar3 ? '' : t('ui.juego.item_no_presupuesto') }, t('ui.juego.btn_pujar3')));
        buttons.appendChild(el('button', { id: 'btnBajar', class: 'flex-1 bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-sm leading-tight disabled:opacity-40', onclick: onBajar }, bajarLabel));

        bar.appendChild(buttons);

        if (!canBajar) {
            const b = document.getElementById('btnBajar');
            if (b) b.disabled = true;
        }
        if (!canPujar1) {
            const b = document.getElementById('btnPujar');
            if (b) b.disabled = true;
        }
        if (!canPujar3) {
            const b3 = document.getElementById('btnPujar3');
            if (b3) b3.disabled = true;
        }
    }

    function sumValor(items) { return (items || []).reduce(function (acc, i) { return acc + (i.valor || 0); }, 0); }
    function sumPrecio(items) { return (items || []).reduce(function (acc, i) { return acc + (i.precio || 0); }, 0); }

    function renderItemList(label, color, items, totalValor, totalPrecio) {
        const header = el('div', { class: 'flex justify-between items-baseline mb-2 px-1' }, [
            el('div', { class: 'text-sm font-bold uppercase tracking-wide ' + color }, label),
            el('div', { class: 'text-base font-mono font-bold text-amber-400' }, totalValor + ' ⭐'),
        ]);
        const list = items.length === 0
            ? el('div', { class: 'text-slate-500 text-xs italic px-1' }, '—')
            : el('div', { class: 'space-y-1' }, items.map(function (i) {
                return el('div', { class: 'flex items-center gap-2 bg-slate-800 rounded px-2 py-1.5' }, [
                    el('span', { class: 'text-xl w-7 text-center flex-shrink-0' }, i.emoji),
                    el('span', { class: 'flex-1 text-sm text-slate-100 truncate' }, tItem(i.id)),
                    el('span', { class: 'text-xs font-mono text-amber-300 flex-shrink-0' }, '⭐' + (i.valor || 0)),
                    el('span', { class: 'text-xs font-mono text-slate-400 flex-shrink-0' }, '🪙' + (i.precio || 0)),
                ]);
            }));
        const footer = items.length > 0
            ? el('div', { class: 'text-xs text-slate-500 mt-2 px-1 text-right font-mono' }, t('ui.juego.total_gastado') + ': ' + totalPrecio + ' 🪙')
            : null;
        return el('div', { class: 'mb-4' }, [header, list, footer].filter(Boolean));
    }

    function renderAbandonedScreen() {
        const card = $('#itemCard');
        if (!card) return;
        clear(card);
        card.classList.remove('pr-20', 'sm:pr-24');
        const s = state.sala;
        const por = s.abandono_por;
        const rivalSlot = state.jugadorSlot !== null ? (1 - state.jugadorSlot) : null;
        const rivalName = (por !== null && por !== undefined && rivalSlot !== null && por !== state.jugadorSlot)
            ? (s.jugadores[por]?.nombre || '')
            : (s.jugadores[rivalSlot]?.nombre || '');
        const msg = rivalName
            ? t('ui.juego.msg_rival_abandono') + ' (' + rivalName + ')'
            : t('ui.juego.msg_rival_abandono');

        card.classList.remove('items-center', 'justify-center');
        card.classList.add('items-stretch', 'justify-start');

        card.appendChild(el('div', { class: 'text-center mt-8' }, [
            el('div', { class: 'text-6xl mb-4' }, '👋'),
            el('h2', { class: 'text-2xl font-bold text-amber-400 mb-3' }, msg),
            el('p', { class: 'text-slate-400 text-sm px-6' }, t('ui.juego.submsg_rival_abandono')),
        ]));

        card.appendChild(el('div', { class: 'text-center mt-8' }, [
            el('button', { class: 'bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap', onclick: onLeave }, t('ui.juego.salir_lobby')),
        ]));
    }

    function renderFinalScreen() {
        const card = $('#itemCard');
        if (!card) return;
        clear(card);
        card.classList.remove('pr-20', 'sm:pr-24');
        const s = state.sala;
        const me = s.jugadores[state.jugadorSlot];
        const rival = s.jugadores[1 - state.jugadorSlot];
        const myItems = me?.items_ganados || [];
        const rivalItems = rival?.items_ganados || [];
        const myScore = sumValor(myItems);
        const rivalScore = sumValor(rivalItems);
        const mySpent = sumPrecio(myItems);
        const rivalSpent = sumPrecio(rivalItems);
        const myMoney = me?.dinero || 0;
        const rivalMoney = rival?.dinero || 0;

        // Victoria: más ⭐; empate → más monedas restantes; empate total → tablas.
        let resultado = 'draw';
        let porDesempate = false;
        if (myScore > rivalScore) resultado = 'win';
        else if (rivalScore > myScore) resultado = 'loss';
        else if (myMoney > rivalMoney) { resultado = 'win'; porDesempate = true; }
        else if (rivalMoney > myMoney) { resultado = 'loss'; porDesempate = true; }
        registrarResultado(resultado);

        // Centrar verticalmente el contenido cuando hay espacio; si no, scroll natural.
        card.classList.remove('items-center', 'justify-center');
        card.classList.add('items-stretch', 'justify-start');

        const header = el('div', { class: 'text-center mb-4' }, [
            el('div', { class: 'text-4xl mb-2' }, '🏆'),
            el('h2', { class: 'text-2xl font-bold text-amber-400' }, t('ui.juego.fin_titulo')),
        ]);

        // Bloque resultado
        let resultBlock;
        if (resultado === 'win') {
            const clave = porDesempate ? 'ui.juego.fin_ganador_desempate' : 'ui.juego.fin_ganador_score';
            resultBlock = el('div', { class: 'bg-emerald-500 text-white p-4 rounded-lg mb-4 text-center fade-in' }, [
                el('div', { class: 'text-3xl mb-1' }, '🏅'),
                el('p', { class: 'text-base font-bold' }, t(clave, { nombre: me?.nombre || 'Tú', puntos: myScore })),
            ]);
            vibrate([100, 50, 100, 50, 100]);
        } else if (resultado === 'loss') {
            const clave = porDesempate ? 'ui.juego.fin_ganador_desempate' : 'ui.juego.fin_ganador_score';
            resultBlock = el('div', { class: 'bg-rose-500 text-white p-4 rounded-lg mb-4 text-center fade-in' }, [
                el('div', { class: 'text-3xl mb-1' }, '🏅'),
                el('p', { class: 'text-base font-bold' }, t(clave, { nombre: rival?.nombre || 'Rival', puntos: rivalScore })),
            ]);
        } else {
            resultBlock = el('div', { class: 'bg-slate-700 text-slate-100 p-4 rounded-lg mb-4 text-center fade-in' }, [
                el('div', { class: 'text-3xl mb-1' }, '🤝'),
                el('p', { class: 'text-base font-bold' }, t('ui.juego.fin_empate_score', { puntos: myScore })),
            ]);
        }

        // Banner de propuesta de revancha del rival (si existe y está fresca).
        let revanchaBanner = null;
        const rev = s.revancha;
        const revFresca = rev && (Math.floor(Date.now() / 1000) - (rev.ts || 0)) <= 600;
        if (revFresca && rev.por !== state.jugadorSlot) {
            const tema = rev.tematica ? (tematicaEmoji(rev.tematica) + ' ' + tTematica(rev.tematica)) : '';
            revanchaBanner = el('div', { class: 'bg-slate-700 border border-amber-400 rounded-lg p-3 mb-4 text-center fade-in' }, [
                el('p', { class: 'text-sm text-slate-100 mb-3' }, t('ui.juego.msg_revancha_propuesta', { nombre: rival?.nombre || 'Rival', tema: tema })),
                el('div', { class: 'flex gap-2' }, [
                    el('button', { class: 'flex-1 bg-emerald-500 text-white font-bold py-3 rounded-lg btn-tap', onclick: aceptarRevancha }, t('ui.juego.btn_revancha_unirse')),
                    el('button', { class: 'flex-1 bg-slate-600 text-slate-100 py-3 rounded-lg btn-tap', onclick: rechazarRevancha }, t('ui.juego.btn_revancha_rechazar')),
                ]),
            ]);
        }

        // Récord local
        const st = loadStats();
        const statsLine = el('div', { class: 'text-center text-xs text-slate-400 mt-4 font-mono' },
            t('ui.lobby.stats_record', { v: st.wins || 0, d: st.losses || 0, e: st.draws || 0 }));

        // Lista de items por jugador
        const lists = el('div', { class: 'space-y-4 px-1' }, [
            renderItemList(t('ui.juego.lbl_tu_inv') + ' · ' + (me?.nombre || 'Tú'), 'text-amber-400', myItems, myScore, mySpent),
            renderItemList(t('ui.juego.lbl_rival_inv') + ' · ' + (rival?.nombre || 'Rival'), 'text-rose-400', rivalItems, rivalScore, rivalSpent),
        ]);

        const exitBtn = el('div', { class: 'mt-6 mb-2 space-y-2' }, [
            el('button', {
                class: 'w-full bg-emerald-500 text-white font-bold py-3 px-6 rounded-lg btn-tap',
                onclick: openRevanchaModal,
            }, '🔄 ' + t('ui.juego.btn_revancha')),
            el('button', { class: 'w-full bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap', onclick: onLeave }, t('ui.juego.salir_lobby')),
        ]);

        card.appendChild(header);
        if (revanchaBanner) card.appendChild(revanchaBanner);
        card.appendChild(resultBlock);
        card.appendChild(lists);
        card.appendChild(statsLine);
        card.appendChild(exitBtn);
    }

    function renderEsperandoRival() {
        const card = $('#itemCard');
        if (!card) return;
        clear(card);
        card.classList.remove('items-center', 'justify-center', 'pr-20', 'sm:pr-24');
        card.classList.add('items-stretch', 'justify-start');
        card.appendChild(el('div', { class: 'text-center mt-8 fade-in' }, [
            el('div', { class: 'text-5xl mb-4' }, '⏳'),
            el('h2', { class: 'text-xl font-bold text-amber-400 mb-2' }, t('ui.juego.msg_esperando_revancha')),
            el('div', { class: 'text-3xl font-mono font-bold text-slate-100 tracking-widest my-4' }, state.codigo || ''),
            el('p', { class: 'text-slate-400 text-xs px-6' }, t('ui.juego.submsg_esperando_revancha')),
        ]));
        card.appendChild(el('div', { class: 'flex gap-2 mt-6 px-2' }, [
            el('button', { class: 'flex-1 bg-slate-700 text-slate-100 py-3 rounded-lg btn-tap', onclick: function () { copyLink(state.codigo); } }, t('ui.lobby.btn_copiar')),
            el('button', { class: 'flex-1 bg-emerald-500 text-white py-3 rounded-lg btn-tap', onclick: function () { shareWhatsApp(state.codigo); } }, t('ui.lobby.btn_whatsapp')),
        ]));
        card.appendChild(el('div', { class: 'text-center mt-6' }, [
            el('button', { class: 'text-slate-400 text-sm btn-tap', onclick: onLeave }, '← ' + t('ui.juego.salir_lobby')),
        ]));
    }

    // =================== revancha ===================
    function openRevanchaModal() {
        const ctx = { tematicaSeleccionada: (state.sala && state.sala.tematica) || TEMATICA_RANDOM };
        const box = el('div', {});
        const content = el('div', {}, [
            el('h2', { class: 'text-lg font-bold text-amber-400 mb-3' }, '🔄 ' + t('ui.juego.btn_revancha')),
            box,
        ]);
        const m = showModal(content);
        renderTematicaSelector(box, { ctx: ctx });
        content.appendChild(el('div', { class: 'flex gap-2 mt-4' }, [
            el('button', { class: 'flex-1 bg-slate-600 text-slate-100 py-3 rounded-lg btn-tap', onclick: m.close }, t('ui.reglas.cerrar')),
            el('button', {
                class: 'flex-1 bg-emerald-500 text-white font-bold py-3 rounded-lg btn-tap',
                onclick: function () { proponerRevancha(resolverTematica(ctx), m.close); },
            }, t('ui.juego.btn_revancha_proponer')),
        ]));
    }

    async function proponerRevancha(tematica, closeModal) {
        const cerrar = function () { if (typeof closeModal === 'function') closeModal(); };

        // Contra bot: nueva partida inmediata con el mismo bot y dificultad.
        if (state.bot) {
            const dificultad = state.bot.dificultad || 'normal';
            const ok = await iniciarPartidaBot(tematica, dificultad, state.jugadorNombre);
            if (ok) cerrar();
            return;
        }

        const viejoCodigo = state.codigo;
        const viejoId = state.jugadorId;
        const nombre = state.jugadorNombre || '';
        const r = await api('POST', 'api/crear_sala.php', { tematica: tematica, nombre: nombre });
        if (!r.ok) { toast(r.error || 'Error'); return; }
        const r2 = await api('POST', 'api/revancha.php', {
            codigo: viejoCodigo,
            jugador_id: viejoId,
            accion: 'proponer',
            codigo_nuevo: r.codigo,
            tematica: tematica,
        });
        if (!r2.ok) { toast(r2.error || 'Error'); return; }
        cerrar();
        state.codigo = r.codigo;
        state.jugadorId = r.jugador_id;
        saveSession();
        window.location.href = 'juego.php?codigo=' + encodeURIComponent(r.codigo);
    }

    async function aceptarRevancha() {
        const rev = state.sala && state.sala.revancha;
        if (!rev || !rev.codigo_nuevo) return;
        const r = await api('POST', 'api/unirse_sala.php', { codigo: rev.codigo_nuevo, nombre: state.jugadorNombre || '' });
        if (!r.ok) { toast(r.error || 'Error'); return; }
        state.codigo = rev.codigo_nuevo;
        state.jugadorId = r.jugador_id;
        saveSession();
        window.location.href = 'juego.php?codigo=' + encodeURIComponent(rev.codigo_nuevo);
    }

    async function rechazarRevancha() {
        const r = await api('POST', 'api/revancha.php', {
            codigo: state.codigo,
            jugador_id: state.jugadorId,
            accion: 'rechazar',
        });
        if (r.ok) {
            state.sala = r.sala;
            toast(t('ui.juego.msg_revancha_rechazada'));
            renderGame(null);
        } else {
            toast(r.error || 'Error');
        }
    }

    async function onPujar() { await sendAction('pujar', 1); }
    async function onPujar3() { await sendAction('pujar', 3); }
    async function onBajar() { await sendAction('bajar'); }
    async function onPasarDeadlock() { await sendAction('pasar_deadlock'); }
    async function onAsignarRival(destino, precio) {
        await sendAction('asignar_rival', undefined, { destino: destino, precio: precio });
    }
    async function onAbandonar() {
        await sendAction('abandonar');
    }

    async function sendAction(accion, incremento, extras) {
        if (state.actionInFlight) return; // guard anti doble-click
        if (state.sala && (state.sala.estado === 'abandonada' || state.sala.estado === 'finalizada')) {
            return; // estado terminal: no enviar acciones
        }
        const body = Object.assign({ codigo: state.codigo, jugador_id: state.jugadorId, accion: accion }, extras || {});
        if (incremento !== undefined) body.incremento = incremento;
        state.actionInFlight = true;
        disableActions(true);
        try {
            const r = await api('POST', 'api/accion.php', body);
            if (!r.ok) {
                toast(r.error || 'Error');
                vibrate([100, 50, 100]);
                return;
            }
            state.sala = r.sala;
            identifySlots();
            renderGame(null);
        } finally {
            disableActions(false);
            state.actionInFlight = false;
        }
    }
    function disableActions(disabled) {
        ['btnPujar', 'btnPujar3', 'btnBajar', 'btnPasarDeadlock', 'btnAsignarKeep', 'btnAsignarGift'].forEach(function (id) {
            const b = document.getElementById(id);
            if (b) b.disabled = disabled;
        });
    }

    function onLeave() {
        const s = state.sala;
        const enJuego = s && s.estado === 'jugando';
        if (enJuego) {
            if (!confirm(t('ui.juego.confirm_salir_jugando'))) return;
            // Notificar abandono al servidor y luego redirigir.
            clearSession(state.codigo);
            stopPollingGame();
            // Fire-and-forget: la respuesta no importa porque ya estamos saliendo.
            fetch('api/accion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ codigo: state.codigo, jugador_id: state.jugadorId, accion: 'abandonar' }),
            }).catch(function () { /* ignore */ });
            window.location.href = 'index.php';
            return;
        }
        // Estados terminales (abandonada, finalizada, sin sala): salida directa.
        clearSession(state.codigo);
        stopPollingGame();
        window.location.href = 'index.php';
    }

    // =================== EXPOSE ===================
    window.__init = indexInit;
    window.__juegoInit = juegoInit;
})();