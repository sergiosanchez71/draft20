/* Draft 20 - app.game.js
 * Vista de juego: shell, renders, acciones, bot de practica y revancha.
 * Requiere app.core.js (window.DraftApp) y bot_policy.js cargados antes.
 */
(function () {
    'use strict';

    const POLL_MS = 1000;
    const BOT_MAX_RESPUESTA_MS = 60000; // watchdog del bot: solo corre en su turno

    const D = window.DraftApp;
    if (!D) return;

    const state = D.state;
    const t = D.t, tItem = D.tItem, tTematica = D.tTematica;
    const tematicaEmoji = D.tematicaEmoji;
    const api = D.api, toast = D.toast, vibrate = D.vibrate;
    const copyLink = D.copyLink, shareWhatsApp = D.shareWhatsApp;
    const el = D.el, clear = D.clear, showModal = D.showModal;
    const emojiImg = D.emojiImg, emojiSlug = D.emojiSlug;
    const $ = D.$;
    const loadSession = D.loadSession, saveSession = D.saveSession, clearSession = D.clearSession;
    const loadStats = D.loadStats, registrarResultado = D.registrarResultado;
    const TEMATICA_RANDOM = D.TEMATICA_RANDOM;
    const resolverTematica = D.resolverTematica;
    const renderTematicaSelector = D.renderTematicaSelector;
    const iniciarPartidaBot = D.iniciarPartidaBot;

    // El aviso del último ítem y el de la temática se muestran una sola vez
    // por partida (la carga de página va por partida).
    let ultimoItemAvisado = false;
    let tematicaAvisada = false;

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
        state.partidaGuardada = false;

        renderGameShell();
        await pollGameTick();
        startPollingGame();

        // Al volver a la pestaña, poll inmediato (evita last_seen obsoleto).
        document.addEventListener('visibilitychange', function () {
            if (!state.codigo) return;
            if (document.hidden) {
                stopPollingGame();
            } else if (document.getElementById('scoreboard')) {
                pollGameTick().then(function () {
                    // En estado terminal (revancha) o con bot no se relanza a 1s.
                    if (!state.pollTerminal) startPollingGame();
                });
            }
        });
    }

    function renderGameShell() {
        const app = $('#app');
        clear(app);
        app.className = 'flex-1 flex flex-col min-h-0 overflow-hidden';

        // Header
        const header = el('header', { class: 'flex items-center justify-between px-3 py-1.5 bg-slate-800 border-b border-slate-700 safe-pt' }, [
            el('button', {
                id: 'btnLeave',
                class: 'text-slate-400 text-xs btn-tap',
                onclick: onLeave,
                'aria-label': t('ui.juego.salir_lobby'),
            }, '← ' + t('ui.juego.salir_lobby')),
            el('div', { class: 'text-center flex-1 px-2' }, [
                el('h1', { class: 'text-sm font-bold text-amber-400 leading-tight' }, t('ui.app.titulo')),
                el('div', { id: 'headerTematica', class: 'text-[10px] text-slate-400 leading-tight truncate' }, ''),
            ]),
            el('div', { class: 'w-14' }),
        ]);

        // Banner de desconexión (polling caído)
        const offlineBanner = el('div', {
            id: 'offlineBanner',
            class: 'hidden bg-rose-600 text-white text-xs text-center py-2 px-3',
            role: 'status',
        }, t('ui.juego.sin_conexion'));

        // Scoreboard
        const scoreboard = el('section', { id: 'scoreboard', class: 'bg-slate-800 px-3 py-2 grid grid-cols-2 gap-2 border-b border-slate-700' });

        // Item card (min-h-0 permite que se encoja; el historial va en absoluto a la derecha)
        const itemCard = el('section', { id: 'itemCard', class: 'relative flex-1 min-h-0 flex flex-col p-3 sm:p-6 bg-slate-900 overflow-y-auto' });

        // Inventory row (flex-shrink-0 garantiza que no se comprima)
        const inventory = el('section', { id: 'inventory', class: 'flex-shrink-0 bg-slate-800 px-3 py-2 border-t border-slate-700' });

        // Emotes rápidos (compactos para no empujar la barra de acciones)
        const emoteBar = el('section', { id: 'emoteBar', class: 'flex-shrink-0 bg-slate-800 px-2 pb-0.5 flex gap-1 justify-center' });
        ['👍', '😂', '🔥', '😭', '🤝', '😱'].forEach(function (code) {
            emoteBar.appendChild(el('button', {
                class: 'text-base leading-none px-1.5 py-0.5 rounded btn-tap opacity-80 hover:opacity-100',
                'aria-label': 'Emote ' + code,
                onclick: function () { onEmote(code); },
            }, code));
        });

        // Action bar (en flujo, no fija — evita tapar inventario en pantallas pequeñas)
        const actionBar = el('section', { id: 'actionBar', class: 'flex-shrink-0 bg-slate-800 border-t border-slate-700 p-2 safe-pb-action z-10' });

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
            // Sala borrada (TTL/GC) o sin permiso: no tiene sentido seguir.
            if (r._status === 404 || r._status === 403) {
                stopPollingGame();
                clearSession(state.codigo);
                toast(t('ui.app.sala_expirada'));
                window.location.href = 'index.php';
                return;
            }
            // Solo cuenta como "sin conexión" un fallo de red real (status 0);
            // un 4xx/5xx del servidor no debe mostrar el banner offline.
            if (r._status === 0) {
                state.pollFailures = (state.pollFailures || 0) + 1;
                if (state.pollFailures >= 3) {
                    setOfflineBanner(true);
                    state.offlineActivo = true;
                }
            }
            return;
        }
        state.pollFailures = 0;
        setOfflineBanner(false);
        if (state.offlineActivo) {
            state.offlineActivo = false;
            toast(t('ui.juego.conexion_restablecida'));
        }

        const prev = state.sala;
        state.sala = r.sala;
        const rivalAusentePrev = state.rivalAusente;
        state.rivalAusente = (r.rival_ausente === undefined || r.rival_ausente === null) ? null : r.rival_ausente;
        // El rival vuelve: estuvo ausente (banner ≥6 s) y ya no lo está.
        if (rivalAusentePrev !== null && rivalAusentePrev >= 6 && (state.rivalAusente === null || state.rivalAusente < 6)) {
            toast(t('ui.juego.rival_ha_vuelto', { nombre: state.rivalNombre || 'Rival' }));
        }
        identifySlots();

        detectarAccionesRival(prev, state.sala);
        detectarEmotes(state.sala);

        // Detectar transición a abandono (vibración única).
        if (prev && state.sala.estado === 'abandonada' && prev.estado !== 'abandonada' && !state.abandonoDetectadoVibrado) {
            state.abandonoDetectadoVibrado = true;
            vibrate([200, 100, 200]);
        }

        // Evita reconstruir el DOM si nada relevante cambió (el poll va cada 1s).
        const sig = salaSignature(state.sala);
        if (sig !== state.lastRenderSig) {
            state.lastRenderSig = sig;
            renderGame(prev);
        }

        // Bot de práctica.
        if (state.bot) {
            botTick();
        }

        // Valores de la temática: los necesita el bot con valor real y el modo
        // "⭐ Valores visibles" (partidas humanas incluidas).
        cargarValoresSiToca();
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
        // Primer poll: sincroniza el máximo sin repintar emotes antiguos.
        if (!state.ultimoEmoteTs) {
            let max0 = 0;
            sala.emotes.forEach(function (e) { max0 = Math.max(max0, e.ts || 0); });
            state.ultimoEmoteTs = max0;
            return;
        }
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
            class: 'fixed left-1/2 top-16 -translate-x-1/2 z-40 flex items-center gap-2 bg-slate-800 border border-amber-400 rounded-full pl-3 pr-4 py-1.5 pop-emote',
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

        // El tiempo del bot SOLO corre en su turno (o si le toca decidir un deadlock).
        // Cuando es tu turno no cuenta nada suyo.
        const esTurnoBot = s.item_actual.turno_de === botSlot;
        const esDecisor = !!(s.decision_pendiente && s.decision_pendiente.para === botSlot);
        if (!esTurnoBot && !esDecisor) {
            state.botWaitUntil = 0;
            state.botKeyDelay = null;
            state.botWatchStart = 0;
            return;
        }

        // La key incluye decision_pendiente: si no, el bot no reaccionaría al
        // PASAR del humano (mismo ítem/precio/turno/pujas) y la partida se colgaría.
        const key = window.DraftBot.estadoKey(s);
        if (state.botTurnoKey === key) return;

        const dificultad = state.bot.dificultad || 'normal';

        // Delay humano: arranca al empezar SU turno, nunca antes.
        if (state.botKeyDelay !== key) {
            const cfg = window.DraftBot.config(dificultad);
            const rango = cfg.delayMs || [700, 1800];
            state.botKeyDelay = key;
            state.botWaitUntil = Date.now() + rango[0] + Math.random() * (rango[1] - rango[0]);
            state.botWatchStart = Date.now(); // watchdog de 60s desde su turno
            return;
        }

        // Watchdog: si en 60s no ha resuelto, fuerza una acción de respaldo.
        const watchdog = state.botWatchStart > 0 && (Date.now() - state.botWatchStart) > BOT_MAX_RESPUESTA_MS;
        if (!watchdog && Date.now() < state.botWaitUntil) return;

        let decision = window.DraftBot.decidir(s, botSlot, dificultad, Math.random, state.botValores || null);
        if (!decision) decision = window.DraftBot.respaldo(s, botSlot); // garantía de respuesta
        if (!decision) return; // sin acción legal: no marcar la situación

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
     * Los bots que conocen o intuyen los valores (`usaValorReal` o `intuida`)
     * y el modo "⭐ Valores visibles" necesitan el catálogo público de la
     * temática; se carga una vez conocida la sala.
     */
    function cargarValoresSiToca() {
        if (state.botValores || !state.sala) return;
        // Los valores llegan del API solo cuando el modo está activo o la
        // partida es contra bot (el catálogo ya no es público por HTTP).
        if (state.sala.valores && typeof state.sala.valores === 'object') {
            state.botValores = state.sala.valores;
        }
    }

    /**
     * Versión del bot en uso: el `?v=` (mtime) con el que el navegador cargó
     * bot_policy(.min).js. Permite comparar mejoras entre versiones en las
     * estadísticas agregadas.
     */
    function versionBot() {
        try {
            const el = document.querySelector('script[src*="bot_policy"]');
            const m = el && el.src.match(/[?&]v=(\d+)/);
            return m ? m[1] : '0';
        } catch (e) { return '0'; }
    }

    /**
     * Guarda en el dispositivo un registro compacto de la partida de práctica
     * (secuencia de ítems, quién ganó cada uno, valor y precio) para poder
     * calibrar el bot después con partidas reales. Conserva las últimas 20.
     * Si la partida terminó, envía además una copia anónima al servidor
     * (sin nombres, IDs ni códigos) para las estadísticas de calibración.
     */
    function guardarPartidaReferenciaSiToca() {
        if (!state.bot || state.partidaGuardada || state.jugadorSlot === null) return;
        const s = state.sala;
        if (!s || (s.estado !== 'finalizada' && s.estado !== 'abandonada')) return;
        state.partidaGuardada = true;
        const porItem = {};
        let valorHumano = 0, valorBot = 0;
        s.jugadores.forEach(function (j, slot) {
            (j.items_ganados || []).forEach(function (it) {
                porItem[it.id] = { por: slot, valor: it.valor, precio: it.precio };
                if (slot === state.jugadorSlot) valorHumano += it.valor || 0;
                else valorBot += it.valor || 0;
            });
        });
        const registro = {
            ts: Date.now(),
            tematica: s.tematica,
            dificultad: state.bot.dificultad || 'normal',
            visibles: !!s.mostrar_valores,
            humanoSlot: state.jugadorSlot,
            resultado: {
                humano: valorHumano,
                bot: valorBot,
                gana: valorHumano > valorBot ? 'humano' : (valorHumano < valorBot ? 'bot' : 'empate'),
            },
            items: (s.items_mezclados || []).map(function (id) {
                const d = porItem[id];
                return { id: id, valor: d ? d.valor : null, por: d ? d.por : null, precio: d ? d.precio : null };
            }),
        };
        try {
            const raw = localStorage.getItem('draft20_bot_partidas');
            const prev = raw ? JSON.parse(raw) : [];
            const lista = Array.isArray(prev) ? prev : [];
            lista.unshift(registro);
            localStorage.setItem('draft20_bot_partidas', JSON.stringify(lista.slice(0, 20)));
        } catch (e) { /* ignore */ }

        // Estadísticas anónimas (solo partidas terminadas contra el bot).
        if (s.estado !== 'finalizada') return;
        try {
            const payload = {
                version: versionBot(),
                tematica: registro.tematica,
                dificultad: registro.dificultad,
                visibles: registro.visibles,
                resultado: registro.resultado.gana,
                valorHumano: registro.resultado.humano,
                valorBot: registro.resultado.bot,
                items: registro.items
                    .filter(function (it) { return it.por !== null; })
                    .map(function (it) {
                        return {
                            valor: it.valor,
                            por: it.por === state.jugadorSlot ? 'humano' : 'bot',
                            precio: it.precio || 0,
                        };
                    }),
            };
            fetch('api/registrar_partida.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                keepalive: true,
                body: JSON.stringify(payload),
            }).catch(function () { /* best-effort */ });
        } catch (e) { /* ignore */ }
    }

    /** Registro de partidas de práctica guardado (para exportar/analizar). */
    function exportarPartidasReferencia() {
        try { return localStorage.getItem('draft20_bot_partidas') || '[]'; } catch (e) { return '[]'; }
    }

    function startPollingGame() {
        if (state.pollTimer) return;
        state.pollTimer = setInterval(pollGameTick, POLL_MS);
    }
    function stopPollingGame() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    /**
     * Al llegar a un estado terminal se deja de pollear a 1s. En partidas
     * humanas se mantiene un poll lento para recibir la revancha del rival;
     * en las de bot se corta del todo.
     */
    function ajustarPollingTerminal() {
        if (state.pollTerminal) return;
        state.pollTerminal = true;
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
        if (!state.bot) {
            state.pollTimer = setInterval(pollGameTick, 5000);
        }
    }

    function identifySlots() {
        const s = state.sala;
        if (!s) return;
        if (typeof s.mi_slot === 'number' && s.mi_slot !== null) {
            state.jugadorSlot = s.mi_slot;
        } else if (state.jugadorSlot === null) {
            // Compatibilidad: salas antiguas con ids visibles.
            for (let i = 0; i < s.jugadores.length; i++) {
                if (s.jugadores[i] && s.jugadores[i].id === state.jugadorId) {
                    state.jugadorSlot = i;
                    break;
                }
            }
        }
        if (state.jugadorSlot !== null) {
            state.rivalNombre = s.jugadores[1 - state.jugadorSlot]?.nombre || ('Jugador ' + (state.jugadorSlot + 2 === 2 ? 2 : 1));
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
        if (ht && s.tematica) {
            ht.textContent = tematicaEmoji(s.tematica) + ' ' + tTematica(s.tematica);
        }

        // Aviso transitorio de la temática al entrar en la partida (sin aceptación).
        if (s.estado === 'jugando' && !tematicaAvisada) {
            tematicaAvisada = true;
            if (s.tematica) {
                toast(t('ui.juego.aviso_tematica', { tema: tematicaEmoji(s.tematica) + ' ' + tTematica(s.tematica) }), 4000);
            }
        }

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
            ajustarPollingTerminal();
            guardarPartidaReferenciaSiToca();
            renderAbandonedScreen();
            // Vaciar inventarios + bar para que no aparezca UI residual.
            const inv = $('#inventory'); if (inv) clear(inv);
            const bar = $('#actionBar'); if (bar) clear(bar);
            const eb = $('#emoteBar'); if (eb) clear(eb);
            return;
        }
        if (s.estado === 'finalizada') {
            ajustarPollingTerminal();
            guardarPartidaReferenciaSiToca();
            renderFinalScreen();
            avisarUltimoItem();
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
        const rivalEsBot = s.bot_slot !== null && s.bot_slot !== undefined && Number(s.bot_slot) === (1 - state.jugadorSlot);
        const myTurn = s.item_actual && s.item_actual.turno_de === state.jugadorSlot;
        const rivalTurn = s.item_actual && s.item_actual.turno_de === (1 - state.jugadorSlot);

        const myNombre = me?.nombre || '';
        const myLabel = (myNombre && myNombre !== t('ui.juego.lbl_mi_dinero'))
            ? t('ui.juego.lbl_mi_dinero') + ' · ' + myNombre
            : t('ui.juego.lbl_mi_dinero');
        const rivalNombre = rival?.nombre || '';
        const rivalLabel = (rivalNombre ? t('ui.juego.lbl_rival_dinero') + ' · ' + rivalNombre : t('ui.juego.lbl_rival_dinero'))
            + (rivalEsBot && rivalNombre.indexOf('🤖') === -1 ? ' 🤖' : '');

        const myCard = el('div', { class: 'rounded-lg p-2 ' + (myTurn ? 'bg-amber-400 text-slate-900' : 'bg-slate-700 text-slate-100') }, [
            el('div', { class: 'text-[11px] font-semibold opacity-80 truncate' }, myLabel),
            el('div', { class: 'flex items-baseline gap-1 mt-0.5' }, [
                el('span', { class: 'text-xl font-bold font-mono' }, String(me?.dinero ?? 0)),
                el('span', { class: 'text-[11px] opacity-80' }, t('ui.juego.monedas')),
            ]),
            el('div', { class: 'text-[11px] mt-0.5 opacity-80' }, (me?.items_ganados?.length || 0) + '/4'),
        ]);

        const rivalCard = el('div', { class: 'rounded-lg p-2 ' + (rivalTurn ? 'bg-rose-500 text-white' : 'bg-slate-700 text-slate-100') }, [
            el('div', { class: 'text-[11px] font-semibold opacity-80 truncate' }, rivalLabel),
            el('div', { class: 'flex items-baseline gap-1 mt-0.5' }, [
                el('span', { class: 'text-xl font-bold font-mono' }, String(rival?.dinero ?? 0)),
                el('span', { class: 'text-[11px] opacity-80' }, t('ui.juego.monedas')),
            ]),
            el('div', { class: 'text-[11px] mt-0.5 opacity-80' }, (rival?.items_ganados?.length || 0) + '/4'),
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

        // Barra superior pegada a las esquinas de la tarjeta.
        const totalRondas = (s.total_items || (s.items_mezclados && s.items_mezclados.length) || 8);
        const chipTurno = el('div', {
            class: 'text-[11px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap ' + (myTurn ? 'bg-emerald-500 text-white' : 'bg-slate-700 text-slate-300'),
        }, myTurn ? t('ui.juego.tu_turno') : t('ui.juego.turno_rival'));
        card.appendChild(el('div', { class: 'w-full flex-shrink-0 flex items-center justify-between gap-2' }, [
            el('div', { class: 'text-xs text-slate-400' }, t('ui.juego.ronda') + ' ' + s.ronda + ' / ' + totalRondas),
            chipTurno,
        ]));

        // Aviso blando: el rival lleva sin responder unos segundos.
        if (state.rivalAusente !== null && state.rivalAusente >= 6 && s.estado === 'jugando') {
            card.appendChild(el('div', { class: 'w-full flex-shrink-0 bg-amber-500 text-slate-900 text-xs font-bold text-center py-2 px-3 rounded mt-2' },
                t('ui.juego.msg_rival_ausente', { nombre: state.rivalNombre || 'Rival', seg: state.rivalAusente })));
        }
        if (s.ronda >= totalRondas) {
            card.appendChild(el('div', { class: 'w-full flex-shrink-0 text-[11px] font-bold text-amber-300 mt-1' }, '🔥 ' + t('ui.juego.ultima_ronda')));
        }

        // Zona central: el ítem se centra en el espacio libre con auto-márgenes
        // (sin justify-center en el contenedor con scroll) y se puede scrollear
        // entero si no cabe, sin recortar la parte superior.
        const centro = el('div', { class: 'w-full flex-1 flex flex-col justify-center' });
        const wrap = el('div', { class: 'w-full my-auto flex flex-col items-center' });
        centro.appendChild(wrap);

        const emoji = el('div', { class: 'h-[clamp(3rem,12svh,6rem)] mb-2 flex items-center ' + (myTurn ? 'pulse-win' : '') }, [
            emojiImg(item.emoji, 'h-full w-auto', ''),
        ]);
        wrap.appendChild(emoji);

        const itemName = tItem(item.id);
        wrap.appendChild(el('h2', { class: 'text-lg font-bold text-slate-100 mb-1 text-center px-4' }, itemName));

        // Modo "⭐ Valores visibles": valor real del ítem en juego (compartido por sala).
        const valorItem = state.botValores ? state.botValores[item.id] : undefined;
        if (s.mostrar_valores && valorItem != null) {
            wrap.appendChild(el('div', { class: 'bg-slate-800 border border-amber-400 rounded-full px-3 py-0.5 text-sm font-bold text-amber-300 mb-1' }, '⭐ ' + valorItem));
        }

        // Precio y turno
        const status = el('div', { class: 'mt-2 text-center' }, [
            el('div', { class: 'text-[11px] text-slate-400' }, t('ui.juego.precio_actual')),
            el('div', { class: 'text-3xl font-bold font-mono text-amber-400' }, String(item.precio_actual)),
        ]);
        wrap.appendChild(status);

        // Historial de pujas: espacio SIEMPRE reservado a la derecha para que la
        // primera puja no desplace el emoji/nombre/precio. La más reciente abajo.
        const pujas = Array.isArray(item.pujas) ? item.pujas : [];
        card.classList.add('pr-20', 'sm:pr-24');
        const rail = el('div', {
            id: 'bidHistory',
            class: 'absolute right-2 top-12 bottom-12 w-20 sm:w-24 flex flex-col justify-end gap-1 overflow-hidden pointer-events-none',
        });
        const visibles = pujas.slice(-6);
        visibles.forEach(function (p, idx) {
            const esUltima = idx === visibles.length - 1;
            const soyYo = p.por === state.jugadorSlot;
            const nombre = soyYo ? 'Tú' : (state.rivalNombre || 'Rival');
            rail.appendChild(el('div', {
                class: 'rounded px-1.5 py-1 text-right text-[10px] leading-tight bg-slate-800/95 border ' +
                    (esUltima
                        ? 'border-amber-400 ' + (soyYo ? 'text-emerald-300' : 'text-rose-300')
                        : 'border-slate-700 text-slate-300'),
            }, [
                el('div', { class: 'font-bold truncate' }, nombre),
                el('div', { class: 'font-mono opacity-90' }, '+' + p.incremento + ' · ' + p.precio + '🪙'),
            ]));
        });
        card.appendChild(centro);
        card.appendChild(rail);

        // Banner ámbar si hay decisión pendiente (deadlock sin dinero).
        if (s.decision_pendiente) {
            const dp = s.decision_pendiente;
            const isDecisor = dp.para === state.jugadorSlot;
            const isSobre    = dp.sobre === state.jugadorSlot;
            const msg = isDecisor
                ? t('ui.juego.msg_deadlock_decide_corto')
                : (isSobre ? t('ui.juego.msg_cediste_item') : t('ui.juego.msg_cede_item'));
            wrap.appendChild(el('div', { class: 'mt-2 mx-2 px-3 py-1.5 rounded bg-amber-400 text-slate-900 text-xs font-semibold text-center' }, msg));
        }

        // Si estoy capped, mensaje varía según de quién es el turno
        const myCount = (s.jugadores[state.jugadorSlot]?.items_ganados || []).length;
        if (myCount >= 4) {
            const msg = myTurn
                ? t('ui.juego.msg_pasar_al_rival', { cap: 4 })
                : t('ui.juego.msg_esperando_rival_complete', { cap: 4 });
            wrap.appendChild(el('div', { class: 'mt-2 px-3 py-1.5 rounded bg-amber-400 text-slate-900 text-xs font-semibold text-center' }, msg));
        }
    }

    function itemTooltip(i) {
        // El ⭐ solo se revela con el modo "Valores visibles" o en la pantalla final.
        const name = tItem(i.id);
        const p = i.precio != null ? i.precio : '?';
        const v = (state.sala && state.sala.mostrar_valores && i.valor != null) ? ' · ⭐' + i.valor : '';
        return name + ' · 🪙' + p + v;
    }

    function renderInventory() {
        const inv = $('#inventory');
        if (!inv) return;
        clear(inv);
        const s = state.sala;
        const myItems = s.jugadores[state.jugadorSlot]?.items_ganados || [];
        const rivalItems = s.jugadores[1 - state.jugadorSlot]?.items_ganados || [];

        const myRow = el('div', { class: 'mb-2' }, [
            el('div', { class: 'text-[11px] text-slate-400 mb-1 flex items-center gap-1' }, [
                el('span', { class: 'inline-block w-1.5 h-1.5 rounded-full bg-amber-400' }),
                t('ui.juego.lbl_tu_inv') + ' · ' + (myItems.length) + '/4',
            ]),
            el('div', { class: 'flex gap-1 overflow-x-auto no-scrollbar pb-1 min-h-[36px]' },
                myItems.length === 0
                    ? [el('span', { class: 'text-slate-500 text-xs italic' }, t('ui.juego.sin_items'))]
                    : myItems.map(function (i) {
                        const wrap = el('div', { class: 'flex-shrink-0 w-9 h-10 flex flex-col items-center' }, [
                            el('div', { class: 'w-9 h-9 bg-slate-700 border-l-2 border-amber-400 rounded flex items-center justify-center', title: itemTooltip(i) }, [emojiImg(i.emoji, 'w-6 h-6', '')]),
                        ]);
                        if (i.precio != null) wrap.appendChild(el('div', { class: 'text-[9px] text-amber-400 leading-none mt-0.5 font-mono' }, i.precio + '🪙'));
                        return wrap;
                    })
            ),
        ]);

        const rivalRow = el('div', { class: 'pt-1.5 border-t border-slate-700' }, [
            el('div', { class: 'text-[11px] text-slate-400 mb-1 flex items-center gap-1' }, [
                el('span', { class: 'inline-block w-1.5 h-1.5 rounded-full bg-rose-500' }),
                (t('ui.juego.lbl_rival_inv') + ' · ' + (s.jugadores[1 - state.jugadorSlot]?.nombre || 'Rival') + ' · ' + (rivalItems.length) + '/4'),
            ]),
            el('div', { class: 'flex gap-1 overflow-x-auto no-scrollbar pb-1 min-h-[36px]' },
                rivalItems.length === 0
                    ? [el('span', { class: 'text-slate-500 text-xs italic' }, t('ui.juego.sin_items'))]
                    : rivalItems.map(function (i) {
                        const wrap = el('div', { class: 'flex-shrink-0 w-9 h-10 flex flex-col items-center' }, [
                            el('div', { class: 'w-9 h-9 bg-slate-700 border-l-2 border-rose-500 rounded flex items-center justify-center', title: itemTooltip(i) }, [emojiImg(i.emoji, 'w-6 h-6', '')]),
                        ]);
                        if (i.precio != null) wrap.appendChild(el('div', { class: 'text-[9px] text-rose-300 leading-none mt-0.5 font-mono' }, i.precio + '🪙'));
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
                class: 'flex-1 bg-emerald-500 text-white font-bold py-3 rounded-lg btn-tap text-base disabled:opacity-40',
                onclick: function () { onAsignarRival(state.jugadorSlot, 1); },
                title: canKeep ? '' : t('ui.juego.err_no_puedes_pagar_1'),
            }, t('ui.juego.btn_me_lo_quedo_1')));
            buttons.appendChild(el('button', {
                id: 'btnAsignarGift',
                class: 'flex-1 bg-amber-400 text-slate-900 font-bold py-3 rounded-lg btn-tap text-base',
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
                class: 'w-full bg-emerald-500 text-white font-bold py-3 rounded-lg btn-tap text-base',
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
            buttons.appendChild(el('button', { id: 'btnBajar', class: 'flex-1 bg-emerald-500 text-white font-bold py-3 rounded-lg btn-tap text-base', onclick: onBajar }, label));
            if (rivalCapped) {
                buttons.appendChild(el('button', { id: 'btnPujar3', class: 'bg-slate-700 text-slate-100 font-bold py-3 px-4 rounded-lg btn-tap text-sm', onclick: onPujar3 }, t('ui.juego.btn_pujar3')));
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
        buttons.appendChild(el('button', { id: 'btnPujar', class: 'flex-1 bg-amber-400 text-slate-900 font-bold py-3 rounded-lg btn-tap text-base disabled:opacity-40', onclick: onPujar, title: canPujar1 ? '' : t('ui.juego.item_no_presupuesto') }, t('ui.juego.btn_pujar')));
        buttons.appendChild(el('button', { id: 'btnPujar3', class: 'bg-slate-700 text-slate-100 font-bold py-3 px-4 rounded-lg btn-tap text-sm disabled:opacity-40', onclick: onPujar3, title: canPujar3 ? '' : t('ui.juego.item_no_presupuesto') }, t('ui.juego.btn_pujar3')));
        buttons.appendChild(el('button', { id: 'btnBajar', class: 'flex-1 bg-emerald-500 text-white font-bold py-3 rounded-lg btn-tap text-sm leading-tight disabled:opacity-40', onclick: onBajar }, bajarLabel));

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

    /**
     * Genera una tarjeta 1080×1080 con el resultado y la comparte (Web Share
     * nivel 2, con imagen) o la descarga con el texto copiado como fallback.
     */
    async function compartirResultado(myScore, rivalScore, resultado) {
        const s = state.sala;
        if (!s) return;
        const me = s.jugadores[state.jugadorSlot] || {};
        const rival = s.jugadores[1 - state.jugadorSlot] || {};
        const tema = s.tematica ? (tematicaEmoji(s.tematica) + ' ' + tTematica(s.tematica)) : '';
        const clave = resultado === 'win' ? 'ui.juego.texto_resultado_win'
            : (resultado === 'loss' ? 'ui.juego.texto_resultado_loss' : 'ui.juego.texto_resultado_draw');
        const texto = t(clave, { mio: myScore, rival: rivalScore }) + ' https://draft20.es';

        const canvas = document.createElement('canvas');
        canvas.width = 1080;
        canvas.height = 1080;
        const ctx = canvas.getContext('2d');
        if (!ctx) { toast(t('ui.juego.toast_resultado_error')); return; }

        ctx.fillStyle = '#0f172a';
        ctx.fillRect(0, 0, 1080, 1080);
        ctx.fillStyle = '#fbbf24';
        ctx.fillRect(0, 0, 1080, 18);
        ctx.textAlign = 'center';

        ctx.fillStyle = '#fbbf24';
        ctx.font = 'bold 92px Arial, sans-serif';
        ctx.fillText('Draft 20', 540, 170);

        ctx.fillStyle = '#cbd5e1';
        ctx.font = '44px Arial, sans-serif';
        ctx.fillText(tema, 540, 250);

        ctx.fillStyle = resultado === 'win' ? '#10b981' : (resultado === 'loss' ? '#f43f5e' : '#94a3b8');
        ctx.font = 'bold 240px Arial, sans-serif';
        ctx.fillText(myScore + ' - ' + rivalScore, 540, 560);

        ctx.fillStyle = '#e2e8f0';
        ctx.font = 'bold 46px Arial, sans-serif';
        ctx.fillText((me.nombre || 'Tú') + '  ·  ' + (rival.nombre || 'Rival'), 540, 660);

        const emojis = []
            .concat((me.items_ganados || []).map(function (i) { return i.emoji; }))
            .concat((rival.items_ganados || []).map(function (i) { return i.emoji; }));
        const lista = emojis.slice(0, 8);
        const iconos = await Promise.all(lista.map(function (code) {
            return new Promise(function (resolve) {
                const im = new Image();
                im.onload = function () { resolve(im); };
                im.onerror = function () { resolve(null); };
                im.src = '/img/emoji/' + emojiSlug(code) + '.svg';
            });
        }));
        const lado = 84;
        const hueco = 14;
        let x = 540 - (iconos.length * lado + Math.max(0, iconos.length - 1) * hueco) / 2;
        iconos.forEach(function (im, idx) {
            if (im) {
                ctx.drawImage(im, x, 748, lado, lado);
            } else {
                ctx.font = '64px Arial, sans-serif';
                ctx.fillText(lista[idx] || '🎲', x + lado / 2, 812);
            }
            x += lado + hueco;
        });

        ctx.fillStyle = '#94a3b8';
        ctx.font = '38px Arial, sans-serif';
        ctx.fillText('draft20.es · subasta por turnos para 2 jugadores', 540, 980);

        canvas.toBlob(function (blob) {
            if (!blob) { toast(t('ui.juego.toast_resultado_error')); return; }
            const file = (typeof File === 'function') ? new File([blob], 'draft20.png', { type: 'image/png' }) : null;
            if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({ files: [file], text: texto, url: 'https://draft20.es' })
                    .catch(function () { /* cancelado por el usuario */ });
                return;
            }
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'draft20-resultado.png';
            document.body.appendChild(a);
            a.click();
            a.remove();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto).then(function () {
                    toast(t('ui.juego.toast_resultado_copiado'));
                }, function () { /* sin clipboard */ });
            }
        }, 'image/png');
    }

    /**
     * Firma del estado visible: si no cambia, el poll no vuelve a pintar el DOM.
     * Incluye lo que afecta a la UI (incluido el aviso de rival ausente ≥6s).
     */
    function salaSignature(s) {
        if (!s) return '';
        const it = s.item_actual;
        return [
            s.estado,
            s.ronda,
            s.tematica,
            s.abandono_por,
            s.decision_pendiente ? (s.decision_pendiente.para + ':' + s.decision_pendiente.sobre) : '',
            it ? [it.id, it.precio_actual, it.turno_de, it.ultimo_pujo, (it.pujas || []).length].join(':') : '',
            (s.jugadores || []).map(function (p) {
                if (!p) return '';
                const inv = (p.items_ganados || []).map(function (i) { return i.id + i.precio; }).join('+');
                return p.dinero + ':' + inv;
            }).join('|'),
            s.revancha ? [s.revancha.por, s.revancha.codigo_nuevo, s.revancha.ts].join(':') : '',
            state.rivalNombre || '',
            (state.rivalAusente !== null && state.rivalAusente >= 6) ? state.rivalAusente : '',
            state.botValores ? 'val' : '', // el ⭐ aparece en cuanto cargan los valores
        ].join('#');
    }

    function renderItemList(label, color, items, totalValor, totalPrecio) {
        const header = el('div', { class: 'flex justify-between items-baseline mb-2 px-1' }, [
            el('div', { class: 'text-sm font-bold uppercase tracking-wide ' + color }, label),
            el('div', { class: 'text-base font-mono font-bold text-amber-400' }, totalValor + ' ⭐'),
        ]);
        const list = items.length === 0
            ? el('div', { class: 'text-slate-500 text-xs italic px-1' }, '—')
            : el('div', { class: 'space-y-1' }, items.map(function (i) {
                return el('div', { class: 'flex items-center gap-2 bg-slate-800 rounded px-2 py-1.5' }, [
                    el('span', { class: 'w-7 flex-shrink-0 flex justify-center' }, [emojiImg(i.emoji, 'w-6 h-6', '')]),
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

    /**
     * Último ítem: se asigna en la misma acción que finaliza la partida, así que
     * la carta nunca llega a pintarlo. Se avisa con un toast que se va solo.
     */
    function avisarUltimoItem() {
        if (ultimoItemAvisado) return;
        const s = state.sala;
        const ult = s && s.ultimo_item;
        if (!ult || !ult.id) return;
        ultimoItemAvisado = true;
        const ganador = (s.jugadores && s.jugadores[ult.ganador]) || null;
        toast(t('ui.juego.msg_ultimo_item', {
            item: (ult.emoji ? ult.emoji + ' ' : '') + tItem(ult.id),
            nombre: (ganador && ganador.nombre) ? ganador.nombre : '—',
            precio: (ult.precio || 0) + '🪙',
        }), 4500);
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
        const yoAbandone = por !== null && por !== undefined && por === state.jugadorSlot;
        const msg = yoAbandone
            ? t('ui.juego.msg_abandono_propio')
            : (rivalName
                ? t('ui.juego.msg_rival_abandono') + ' (' + rivalName + ')'
                : t('ui.juego.msg_rival_abandono'));

        card.appendChild(el('div', { class: 'text-center mt-4' }, [
            el('div', { class: 'text-6xl mb-4' }, '👋'),
            el('h2', { class: 'text-2xl font-bold text-amber-400 mb-3' }, msg),
            el('p', { class: 'text-slate-400 text-sm px-6' }, t('ui.juego.submsg_rival_abandono')),
        ]));

        card.appendChild(el('div', { class: 'text-center mt-4' }, [
            el('button', { class: 'bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap', onclick: onLeave }, t('ui.juego.salir_lobby')),
        ]));
    }

    const LOGROS = [
        { id: 'coleccionista', icono: '🧺', test: function (r, mi) { return mi.length >= 4; } },
        { id: 'cazador', icono: '🎯', test: function (r, mi) { return r === 'win' && mi.filter(function (i) { return (i.valor || 0) >= 8; }).length >= 2; } },
        { id: 'austero', icono: '💰', test: function (r, mi, ri, gasto) { return r === 'win' && gasto <= 8; } },
        { id: 'derrochador', icono: '💸', test: function (r, mi, ri, gasto) { return r === 'win' && gasto >= 18; } },
        { id: 'racha', icono: '🔥', test: function () { return (loadStats().streak || 0) >= 3; } },
        { id: 'veterano', icono: '🎖️', test: function () { const s = loadStats(); return ((s.wins || 0) + (s.losses || 0) + (s.draws || 0)) >= 10; } },
    ];

    /** Serie al mejor de 3 con el mismo rival (se reinicia a los 30 min). */
    function actualizarSerie(resultado) {
        const rival = state.rivalNombre || 'Rival';
        let serie = null;
        try { serie = JSON.parse(localStorage.getItem('draft20_serie') || 'null'); } catch (e) { /* ignore */ }
        const ahora = Date.now();
        const vigente = serie && serie.rival === rival && (ahora - (serie.ts || 0)) < 30 * 60 * 1000;
        if (!vigente) serie = { rival: rival, mio: 0, rivalPuntos: 0 };
        if (resultado === 'win') serie.mio = (serie.mio || 0) + 1;
        else if (resultado === 'loss') serie.rivalPuntos = (serie.rivalPuntos || 0) + 1;
        serie.ts = ahora;
        serie.ganada = (serie.mio >= 2 || serie.rivalPuntos >= 2) ? (serie.mio >= 2 ? 'mio' : 'rival') : '';
        try { localStorage.setItem('draft20_serie', JSON.stringify(serie)); } catch (e) { /* ignore */ }
        return serie;
    }

    function evaluarLogros(resultado, miItems, rivalItems, gasto) {
        let guardados = {};
        try { guardados = JSON.parse(localStorage.getItem('draft20_logros') || '{}') || {}; } catch (e) { /* ignore */ }
        const nuevos = [];
        LOGROS.forEach(function (l) {
            if (!guardados[l.id] && l.test(resultado, miItems, rivalItems, gasto)) {
                guardados[l.id] = Date.now();
                nuevos.push(l);
            }
        });
        try { localStorage.setItem('draft20_logros', JSON.stringify(guardados)); } catch (e) { /* ignore */ }
        return nuevos;
    }

    function renderSerieYLogros(resultado, myItems, rivalItems, mySpent) {
        if (!state.finalRegistrada) {
            state.finalRegistrada = true;
            state.serieFinal = actualizarSerie(resultado);
            state.logrosFinal = evaluarLogros(resultado, myItems, rivalItems, mySpent);
        }
        const serie = state.serieFinal || { mio: 0, rivalPuntos: 0, ganada: '' };
        const logrosNuevos = state.logrosFinal || [];
        const bloques = [];

        bloques.push(el('div', { class: 'text-center bg-slate-800 border border-slate-700 rounded-lg p-3 mb-4' }, [
            el('div', { class: 'text-xs text-slate-400' }, t('ui.juego.serie_titulo')),
            el('div', { class: 'text-xl font-mono font-bold text-amber-300' }, serie.mio + ' - ' + (serie.rivalPuntos || 0)),
            serie.ganada
                ? el('div', { class: 'text-sm font-bold text-emerald-400 mt-1' },
                    (serie.ganada === 'mio' ? t('ui.juego.serie_ganada') : t('ui.juego.serie_ganada').replace('¡Serie', 'Serie')))
                : null,
        ].filter(Boolean)));

        if (logrosNuevos.length) {
            bloques.push(el('div', { class: 'bg-amber-400 text-slate-900 rounded-lg p-3 mb-4 text-center fade-in' }, [
                el('div', { class: 'text-xs font-bold uppercase' }, t('ui.juego.logro_desbloqueado')),
                el('div', { class: 'text-sm font-bold mt-1' }, logrosNuevos.map(function (l) {
                    return l.icono + ' ' + t('ui.juego.logros.' + l.id);
                }).join(' · ')),
            ]));
        }
        return bloques;
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
                class: 'w-full bg-slate-700 text-slate-100 font-bold py-3 px-6 rounded-lg btn-tap',
                onclick: function () { compartirResultado(myScore, rivalScore, resultado); },
            }, t('ui.juego.btn_compartir_resultado')),
            el('button', {
                class: 'w-full bg-emerald-500 text-white font-bold py-3 px-6 rounded-lg btn-tap',
                onclick: openRevanchaModal,
            }, '🔄 ' + t('ui.juego.btn_revancha')),
            el('button', { class: 'w-full bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-lg btn-tap', onclick: onLeave }, t('ui.juego.salir_lobby')),
        ]);

        card.appendChild(header);
        if (revanchaBanner) card.appendChild(revanchaBanner);
        card.appendChild(resultBlock);
        renderSerieYLogros(resultado, myItems, rivalItems, mySpent).forEach(function (b) { card.appendChild(b); });
        card.appendChild(el('p', { class: 'text-center text-xs text-slate-400 mb-4' },
            t('ui.juego.tematica_label') + ': ' + (s.tematica ? (tematicaEmoji(s.tematica) + ' ' + tTematica(s.tematica)) : '—')));
        card.appendChild(lists);
        card.appendChild(statsLine);
        card.appendChild(exitBtn);
    }

    function renderEsperandoRival() {
        const card = $('#itemCard');
        if (!card) return;
        clear(card);
        card.classList.remove('pr-20', 'sm:pr-24');
        const wrap = el('div', { class: 'w-full my-auto flex flex-col items-center' });
        wrap.appendChild(el('div', { class: 'text-center mt-4 fade-in' }, [
            el('div', { class: 'text-5xl mb-4' }, '⏳'),
            el('h2', { class: 'text-xl font-bold text-amber-400 mb-2' }, t('ui.juego.msg_esperando_revancha')),
            el('div', { class: 'text-3xl font-mono font-bold text-slate-100 tracking-widest my-4' }, state.codigo || ''),
            el('p', { class: 'text-slate-400 text-xs px-6' }, t('ui.juego.submsg_esperando_revancha')),
        ]));
        wrap.appendChild(el('div', { class: 'flex gap-2 mt-6 px-2 w-full' }, [
            el('button', { class: 'flex-1 bg-slate-700 text-slate-100 py-3 rounded-lg btn-tap', onclick: function () { copyLink(state.codigo); } }, t('ui.lobby.btn_copiar')),
            el('button', { class: 'flex-1 bg-emerald-500 text-white py-3 rounded-lg btn-tap', onclick: function () { shareWhatsApp(state.codigo); } }, t('ui.lobby.btn_whatsapp')),
            el('button', { class: 'bg-slate-700 text-slate-100 py-3 px-4 rounded-lg btn-tap', onclick: function () { D.mostrarQR(state.codigo); } }, t('ui.lobby.btn_qr')),
        ]));
        wrap.appendChild(el('div', { class: 'text-center mt-6' }, [
            el('button', { class: 'text-slate-400 text-sm btn-tap', onclick: onLeave }, '← ' + t('ui.juego.salir_lobby')),
        ]));
        card.appendChild(wrap);
    }

    // =================== revancha ===================
    function openRevanchaModal() {
        const ctx = { tematicaSeleccionada: TEMATICA_RANDOM };
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
            const ok = await iniciarPartidaBot(tematica, dificultad, state.jugadorNombre, !!(state.sala && state.sala.mostrar_valores));
            if (ok) cerrar();
            return;
        }

        const viejoCodigo = state.codigo;
        const viejoId = state.jugadorId;
        const nombre = state.jugadorNombre || '';
        const r = await api('POST', 'api/crear_sala.php', {
            tematica: tematica,
            nombre: nombre,
            mostrar_valores: !!(state.sala && state.sala.mostrar_valores),
        });
        if (!r.ok) { toast(r.error || 'Error'); return; }
        const r2 = await api('POST', 'api/revancha.php', {
            codigo: viejoCodigo,
            jugador_id: viejoId,
            accion: 'proponer',
            codigo_nuevo: r.codigo,
            jugador_id_nuevo: r.jugador_id,
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
            state.lastRenderSig = salaSignature(state.sala);
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
        let completada = false;
        try {
            const r = await api('POST', 'api/accion.php', body);
            if (!r.ok) {
                toast(r.error || 'Error');
                vibrate([100, 50, 100]);
                return;
            }
            state.sala = r.sala;
            identifySlots();
            state.lastRenderSig = salaSignature(state.sala);
            renderGame(null);
            completada = true;
        } finally {
            // Tras un OK manda el re-render (deja deshabilitado lo que no proceda);
            // solo re-habilitamos si la acción falló.
            if (!completada) disableActions(false);
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
            // Doble toque: el primero avisa, el segundo (en 3 s) sale de verdad.
            const ahora = Date.now();
            if (!state.salirArmadoHasta || ahora > state.salirArmadoHasta) {
                state.salirArmadoHasta = ahora + 3000;
                toast(t('ui.juego.toque_otra_vez_salir'), 2000);
                return;
            }
            // Notificar abandono al servidor y luego redirigir.
            clearSession(state.codigo);
            stopPollingGame();
            // Fire-and-forget: la respuesta no importa porque ya estamos saliendo.
            fetch('api/accion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                keepalive: true,
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
    D.exportarPartidas = exportarPartidasReferencia;
    window.__juegoInit = juegoInit;
})();
