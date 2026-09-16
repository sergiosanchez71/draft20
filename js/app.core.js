/* Draft 20 - app.core.js
 * Helpers compartidos (i18n, api, toast, vibrate, DOM, modal, stats, sesión)
 * + lobby (crear / unirse / practicar). Expone window.DraftApp para app.game.js.
 */
(function () {
    'use strict';

    const POLL_MS = 1000;
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
        }
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
        app.appendChild(el('header', { class: 'py-2 text-center relative min-h-[36px]' }, [
            hayStats
                ? el('p', { class: 'text-amber-300/80 text-xs font-mono pt-1' }, t('ui.lobby.stats_record', { v: st.wins || 0, d: st.losses || 0, e: st.draws || 0 }))
                : null,
            el('button', {
                class: 'absolute top-1 right-4 w-9 h-9 rounded-full bg-slate-700 text-slate-200 text-sm font-bold btn-tap',
                'aria-label': t('ui.lobby.btn_reglas'),
                title: t('ui.lobby.btn_reglas'),
                onclick: showRulesModal,
            }, '?'),
        ]));

        const selectorBox = el('div', { id: 'tematicaSelector', class: 'mb-4' });

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
                btn.className = 'px-4 py-2 rounded-full text-xs border btn-tap ' +
                    (state.mostrarValores ? 'bg-amber-400 text-slate-900 border-amber-400 font-bold' : 'bg-slate-700 text-slate-200 border-slate-600');
                btn.setAttribute('aria-pressed', state.mostrarValores ? 'true' : 'false');
            }
            mvPintores.push(pintar);
            pintar();
            return el('div', { class: claseWrapper }, [
                btn,
                el('div', { class: 'text-[11px] text-slate-500 mt-2' }, t('ui.lobby.mostrar_valores_ayuda')),
            ]);
        }

        const createForm = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-2' }, t('ui.lobby.selector_tematica')),
            selectorBox,
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.input_nombre_jugador')),
            el('input', { id: 'nameCreate', type: 'text', maxlength: '20', placeholder: t('ui.lobby.placeholder_nombre'), class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base' }),
            crearToggleValores('text-center mb-4'),
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
        const difOpciones = [['facil', 'ui.lobby.bot_facil'], ['normal', 'ui.lobby.bot_normal'], ['dificil', 'ui.lobby.bot_dificil'], ['extremo', 'ui.lobby.bot_extremo']];
        const difBtns = [];
        const difWrap = el('div', { class: 'flex flex-wrap gap-2 justify-center mt-2' });
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

        // Modo "⭐ Valores visibles": los toggles ya se crean en las tarjetas.

        const practiceCard = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-2 text-center' }, t('ui.lobby.bot_dificultad')),
            difWrap,
            crearToggleValores('text-center mt-4'),
            el('button', {
                id: 'btnPractice',
                class: 'w-full bg-slate-700 text-slate-200 py-3 rounded-lg btn-tap text-sm mt-4',
                onclick: onPractice,
            }, t('ui.lobby.btn_practicar')),
        ]);

        app.appendChild(errorBox);
        app.appendChild(createForm);
        app.appendChild(el('div', { class: 'text-center text-slate-500 text-xs my-2' }, '— o —'));
        app.appendChild(joinForm);
        app.appendChild(el('div', { class: 'text-center text-slate-500 text-xs my-2' }, '— o —'));
        app.appendChild(practiceCard);

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
        state.tematicaCreada = tematica;
        saveSession();
        renderCreatorView();
        startPollingLobby();
    }

    /**
     * Crea una partida de práctica: sala nueva + bot sentado como J2.
     * Usada por "Practicar vs 🤖" y por la revancha contra bot (misma dificultad).
     */
    async function iniciarPartidaBot(tematica, dificultad, nombre, mostrarValores) {
        const nombreFinal = (nombre && nombre.trim()) || state.jugadorNombre || 'Tú';
        const mv = (mostrarValores === undefined) ? !!state.mostrarValores : !!mostrarValores;
        const r = await api('POST', 'api/crear_sala.php', {
            tematica: tematica,
            nombre: nombreFinal,
            mostrar_valores: mv,
        });
        if (!r.ok) { toast(r.error || 'Error'); return false; }
        const r2 = await api('POST', 'api/unirse_sala.php', { codigo: r.codigo, nombre: 'Bot', bot: true });
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
        app.appendChild(el('header', { class: 'px-6 pt-2 pb-0 text-center' }, [
            el('p', { class: 'text-slate-400 text-sm' }, tTematica(state.sala?.tematica || state.tematicaCreada || '')),
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
        iniciarPartidaBot: iniciarPartidaBot,
    };
})();
