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
        turnStartAt: null,
        turnTurnoDe: null,
        turnTimerId: null,
        abandonoDetectadoVibrado: false,
    };

    const TURN_SECONDS = 60;

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

    // =================== fetch helper ===================
    async function api(method, path, body) {
        const opts = {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        };
        if (body !== undefined) opts.body = JSON.stringify(body);
        const r = await fetch(path, opts);
        const data = await r.json().catch(function () { return {}; });
        data._status = r.status;
        return data;
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
        app.appendChild(el('header', { class: 'p-6 text-center safe-pt' }, [
            el('h1', { class: 'text-3xl font-bold text-amber-400' }, t('ui.app.titulo')),
            el('p', { class: 'text-slate-400 text-sm mt-1' }, t('ui.app.subtitulo_lobby')),
        ]));

        const tematicasOptions = ['hamburguesa', 'zombies', 'peliculas', 'vacaciones', 'futbol', 'videojuegos', 'poderes', 'pareja', 'atraco']
            .map(function (id) { return el('option', { value: id }, tTematica(id)); });

        const createForm = el('section', { class: 'bg-slate-800 p-6 rounded-lg m-4 fade-in' }, [
            el('label', { class: 'block text-sm text-slate-400 mb-1' }, t('ui.lobby.selector_tematica')),
            el('select', { id: 'tematica', class: 'w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base' }, tematicasOptions),
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

        app.appendChild(errorBox);
        app.appendChild(createForm);
        app.appendChild(el('div', { class: 'text-center text-slate-500 text-xs my-2' }, '— o —'));
        app.appendChild(joinForm);

        $('#btnCreate').addEventListener('click', onCreate);
        $('#btnJoin').addEventListener('click', onJoin);
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
        const tematica = $('#tematica').value;
        const nombre = $('#nameCreate').value.trim();
        const btn = $('#btnCreate');
        btn.disabled = true; btn.classList.add('opacity-50');
        const r = await api('POST', 'api/crear_sala.php', { tematica: tematica, nombre: nombre });
        btn.disabled = false; btn.classList.remove('opacity-50');
        if (!r.ok) { showLobbyError(r.error || 'Error'); vibrate([100, 50, 100]); return; }
        state.codigo = r.codigo;
        state.jugadorId = r.jugador_id;
        state.jugadorNombre = (nombre || '').trim() || ('Jugador 1');
        saveSession();
        renderCreatorView();
        startPollingLobby();
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
            el('p', { class: 'text-slate-400 text-sm mt-1' }, tTematica(state.sala?.tematica || '')),
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

        renderGameShell();
        await pollGameTick();
        startPollingGame();
    }

    function renderGameShell() {
        const app = $('#app');
        clear(app);
        app.className = 'flex-1 flex flex-col';

        // Header
        const header = el('header', { class: 'flex items-center justify-between px-4 py-3 bg-slate-800 border-b border-slate-700 safe-pt' }, [
            el('button', { id: 'btnLeave', class: 'text-slate-400 text-sm btn-tap', onclick: onLeave }, '← ' + t('ui.juego.salir_lobby')),
            el('h1', { class: 'text-lg font-bold text-amber-400' }, t('ui.app.titulo')),
            el('div', { class: 'w-16' }),
        ]);

        // Scoreboard
        const scoreboard = el('section', { id: 'scoreboard', class: 'bg-slate-800 px-4 py-3 grid grid-cols-2 gap-3 border-b border-slate-700' });

        // Item card (min-h-0 permite que se encoqueja y no empuje al inventario fuera del viewport)
        const itemCard = el('section', { id: 'itemCard', class: 'flex-1 min-h-0 flex flex-col items-center justify-center p-6 bg-slate-900 overflow-y-auto' });

        // Inventory row (flex-shrink-0 garantiza que no se comprima)
        const inventory = el('section', { id: 'inventory', class: 'flex-shrink-0 bg-slate-800 px-4 py-3 border-t border-slate-700' });

        // Action bar (en flujo, no fija — evita tapar inventario en pantallas pequeñas)
        const actionBar = el('section', { id: 'actionBar', class: 'flex-shrink-0 bg-slate-800 border-t border-slate-700 p-3 safe-pb-action z-10' });

        app.appendChild(header);
        app.appendChild(scoreboard);
        app.appendChild(itemCard);
        app.appendChild(inventory);
        app.appendChild(actionBar);
    }

    async function pollGameTick() {
        if (!state.codigo) return;
        const r = await api('GET', 'api/estado.php?codigo=' + encodeURIComponent(state.codigo) + '&jugador_id=' + encodeURIComponent(state.jugadorId) + '&t=' + Date.now());
        if (!r.ok) return;
        const prev = state.sala;
        state.sala = r.sala;
        identifySlots();
        // Detectar cambio de turno (o primer turno) → reiniciar countdown.
        const turnoActual = state.sala && state.sala.item_actual ? state.sala.item_actual.turno_de : null;
        if (turnoActual !== state.turnTurnoDe) {
            state.turnTurnoDe = turnoActual;
            state.turnStartAt = state.sala ? Math.floor(Date.now() / 1000) : null;
            startTurnCountdown();
        }
        // Detectar transición a abandono (vibración única).
        if (prev && state.sala.estado === 'abandonada' && prev.estado !== 'abandonada' && !state.abandonoDetectadoVibrado) {
            state.abandonoDetectadoVibrado = true;
            vibrate([200, 100, 200]);
        }
        renderGame(prev);
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

        // Scoreboard
        renderScoreboard();

        // Estado terminal: abandono manda sobre finalización normal.
        if (s.estado === 'abandonada') {
            stopTurnCountdown();
            renderAbandonedScreen();
            // Vaciar inventarios + bar para que no aparezca UI residual.
            const inv = $('#inventory'); if (inv) clear(inv);
            const bar = $('#actionBar'); if (bar) clear(bar);
            return;
        }
        if (s.estado === 'finalizada') {
            stopTurnCountdown();
            renderFinalScreen();
            const inv = $('#inventory'); if (inv) clear(inv);
            const bar = $('#actionBar'); if (bar) clear(bar);
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
            el('div', { class: 'text-xs font-semibold opacity-80' }, t('ui.juego.lbl_rival_dinero') + ' · ' + (rival?.nombre || '')),
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

        card.appendChild(el('div', { class: 'text-xs text-slate-400 mb-2' }, t('ui.juego.ronda') + ' ' + s.ronda + ' / 8'));

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

        const turnoBanner = el('div', { class: 'mt-4 px-4 py-2 rounded-full text-sm font-bold ' + (myTurn ? 'bg-emerald-500 text-white' : 'bg-slate-700 text-slate-300') }, turnoText);
        card.appendChild(turnoBanner);

        // Cuenta atrás: solo visible si es MI turno.
        if (myTurn) {
            const remaining = Math.max(0, TURN_SECONDS - (Math.floor(Date.now() / 1000) - (state.turnStartAt || Math.floor(Date.now() / 1000))));
            const cd = el('div', { id: 'turnCountdown', class: 'mt-3 mx-auto w-fit px-4 py-1 rounded-full text-xs font-mono font-bold ' + (remaining <= 10 ? 'bg-rose-500 text-white' : 'bg-slate-700 text-slate-200') }, t('ui.juego.cuenta_atras', { seg: remaining }));
            card.appendChild(cd);
        }

        // Banner ámbar si hay decisión pendiente (deadlock sin dinero).
        if (s.decision_pendiente) {
            const dp = s.decision_pendiente;
            const isDecisor = dp.para === state.jugadorSlot;
            const isSobre    = dp.sobre === state.jugadorSlot;
            const msg = isDecisor
                ? 'El otro jugador cedió. Decide:'
                : (isSobre
                    ? 'Cediste el ítem. Esperando decisión…'
                    : 'Cede el ítem al rival pulsando PASAR');
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

    function startTurnCountdown() {
        stopTurnCountdown();
        state.turnTimerId = setInterval(function () {
            const cd = document.getElementById('turnCountdown');
            if (!cd || !state.sala || state.sala.estado !== 'jugando') return;
            const now = Math.floor(Date.now() / 1000);
            const remaining = Math.max(0, TURN_SECONDS - (now - (state.turnStartAt || now)));
            cd.textContent = t('ui.juego.cuenta_atras', { seg: remaining });
            // Color rojo en los últimos 10s.
            if (remaining <= 10) {
                cd.classList.remove('bg-slate-700', 'text-slate-200');
                cd.classList.add('bg-rose-500', 'text-white');
            }
        }, 1000);
    }
    function stopTurnCountdown() {
        if (state.turnTimerId) { clearInterval(state.turnTimerId); state.turnTimerId = null; }
    }

    function itemTooltip(i) {
        const name = tItem(i.id);
        const v = i.valor != null ? i.valor : '?';
        const p = i.precio != null ? i.precio : '?';
        return name + ' · ⭐' + v + ' · 🪙' + p;
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

        const buttons = el('div', { class: 'flex gap-2' });
        buttons.appendChild(el('button', { id: 'btnPujar', class: 'flex-1 bg-amber-400 text-slate-900 font-bold py-4 rounded-lg btn-tap text-base disabled:opacity-40', onclick: onPujar, title: canPujar1 ? '' : t('ui.juego.item_no_presupuesto') }, t('ui.juego.btn_pujar')));
        buttons.appendChild(el('button', { id: 'btnPujar3', class: 'bg-slate-700 text-slate-100 font-bold py-4 px-4 rounded-lg btn-tap text-sm disabled:opacity-40', onclick: onPujar3, title: canPujar3 ? '' : t('ui.juego.item_no_presupuesto') }, t('ui.juego.btn_pujar3')));
        buttons.appendChild(el('button', { id: 'btnBajar', class: 'flex-1 bg-emerald-500 text-white font-bold py-4 rounded-lg btn-tap text-base disabled:opacity-40', onclick: onBajar }, t('ui.juego.btn_bajar')));

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
        const s = state.sala;
        const me = s.jugadores[state.jugadorSlot];
        const rival = s.jugadores[1 - state.jugadorSlot];
        const myItems = me?.items_ganados || [];
        const rivalItems = rival?.items_ganados || [];
        const myScore = sumValor(myItems);
        const rivalScore = sumValor(rivalItems);
        const mySpent = sumPrecio(myItems);
        const rivalSpent = sumPrecio(rivalItems);

        // Centrar verticalmente el contenido cuando hay espacio; si no, scroll natural.
        card.classList.remove('items-center', 'justify-center');
        card.classList.add('items-stretch', 'justify-start');

        const header = el('div', { class: 'text-center mb-4' }, [
            el('div', { class: 'text-4xl mb-2' }, '🏆'),
            el('h2', { class: 'text-2xl font-bold text-amber-400' }, t('ui.juego.fin_titulo')),
        ]);

        // Bloque resultado
        let resultBlock;
        if (myScore > rivalScore) {
            resultBlock = el('div', { class: 'bg-emerald-500 text-white p-4 rounded-lg mb-4 text-center fade-in' }, [
                el('div', { class: 'text-3xl mb-1' }, '🏅'),
                el('p', { class: 'text-base font-bold' }, t('ui.juego.fin_ganador_score', { nombre: me?.nombre || 'Tú', puntos: myScore })),
            ]);
            vibrate([100, 50, 100, 50, 100]);
        } else if (myScore < rivalScore) {
            resultBlock = el('div', { class: 'bg-rose-500 text-white p-4 rounded-lg mb-4 text-center fade-in' }, [
                el('div', { class: 'text-3xl mb-1' }, '🏅'),
                el('p', { class: 'text-base font-bold' }, t('ui.juego.fin_ganador_score', { nombre: rival?.nombre || 'Rival', puntos: rivalScore })),
            ]);
        } else {
            resultBlock = el('div', { class: 'bg-slate-700 text-slate-100 p-4 rounded-lg mb-4 text-center fade-in' }, [
                el('div', { class: 'text-3xl mb-1' }, '🤝'),
                el('p', { class: 'text-base font-bold' }, t('ui.juego.fin_empate_score', { puntos: myScore })),
            ]);
        }

        // Lista de items por jugador
        const lists = el('div', { class: 'space-y-4 px-1' }, [
            renderItemList(t('ui.juego.lbl_tu_inv') + ' · ' + (me?.nombre || 'Tú'), 'text-amber-400', myItems, myScore, mySpent),
            renderItemList(t('ui.juego.lbl_rival_inv') + ' · ' + (rival?.nombre || 'Rival'), 'text-rose-400', rivalItems, rivalScore, rivalSpent),
        ]);

        const exitBtn = el('div', { class: 'text-center mt-6 mb-2' }, [
            el('button', { class: 'bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap', onclick: onLeave }, t('ui.juego.salir_lobby')),
        ]);

        card.appendChild(header);
        card.appendChild(resultBlock);
        card.appendChild(lists);
        card.appendChild(exitBtn);
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
            const turnoActual = state.sala && state.sala.item_actual ? state.sala.item_actual.turno_de : null;
            state.turnTurnoDe = turnoActual;
            state.turnStartAt = Math.floor(Date.now() / 1000);
            startTurnCountdown();
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
            stopTurnCountdown();
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
        stopTurnCountdown();
        window.location.href = 'index.php';
    }

    // =================== EXPOSE ===================
    window.__init = indexInit;
    window.__juegoInit = juegoInit;
})();