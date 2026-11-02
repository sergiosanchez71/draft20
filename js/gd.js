/* Draft 20 - gd.js
 * Integración self-hosted para GameDistribution.
 *
 * La web completa (landing + lobby + juego) se sirve en draft20.es/?gd=1 y
 * este módulo se encarga de:
 *   - cargar el SDK de GD una sola vez (GD_OPTIONS antes del script),
 *   - mostrar el pre-roll obligatorio en el primer inicio de partida,
 *   - pedir los display ads (banner superior, lobby y final),
 *   - arrancar/volver del juego sin navegar (mismo documento),
 *   - bloquear cualquier enlace que saque al jugador de la experiencia.
 */
(function () {
    'use strict';

    if (!window.__GD_MODE) return;

    var GAME_ID = window.__GD_GAME_ID || '';
    var SDK_URL = 'https://html5.api.gamedistribution.com/main.min.js';
    var MIN_LOADING_MS = 900;

    function t(key, fallback) {
        var parts = String(key || '').split('.');
        var v = window.LANG;
        for (var i = 0; i < parts.length; i++) v = v && v[parts[i]];
        return typeof v === 'string' && v ? v : (fallback || '');
    }

    // =================== carga del SDK ===================
    var sdkReady = false;
    var sdkFailed = false;
    var sdkResolve = null;
    var sdkPromise = new Promise(function (resolve) { sdkResolve = resolve; });

    window.GD_OPTIONS = {
        gameId: GAME_ID,
        onEvent: function (evt) {
            var name = evt && evt.name;
            if (name === 'SDK_READY') {
                sdkReady = true;
                if (sdkResolve) sdkResolve();
                return;
            }
            if (name === 'SDK_ERROR') {
                sdkFailed = true;
                if (sdkResolve) sdkResolve();
                return;
            }
            if (name === 'SDK_GAME_PAUSE') {
                if (inGame && typeof window.__d20Pause === 'function') window.__d20Pause();
                return;
            }
            if (name === 'SDK_GAME_START') {
                if (inGame && typeof window.__d20Resume === 'function') window.__d20Resume();
            }
        },
    };

    function cargarSdk() {
        if (window.gdsdk && typeof window.gdsdk.showAd === 'function') {
            sdkReady = true;
            if (sdkResolve) sdkResolve();
            return;
        }
        var s = document.createElement('script');
        s.src = SDK_URL;
        s.async = true;
        s.onerror = function () {
            sdkFailed = true;
            if (sdkResolve) sdkResolve();
        };
        document.head.appendChild(s);
    }

    function esperar(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    /** ¿Está el SDK listo? Espera como máximo `ms` sin bloquear para siempre. */
    function esperarSdk(ms) {
        if (window.gdsdk && typeof window.gdsdk.showAd === 'function') return Promise.resolve(true);
        if (sdkFailed) return Promise.resolve(false);
        return Promise.race([sdkPromise, esperar(ms || 8000)]).then(function () {
            return !!(window.gdsdk && typeof window.gdsdk.showAd === 'function');
        });
    }

    // =================== overlay de carga ===================
    var loading = null;
    function crearLoading() {
        if (loading) return loading;
        loading = document.createElement('div');
        loading.id = 'gdLoading';
        loading.setAttribute('role', 'status');
        loading.setAttribute('aria-live', 'polite');
        var spinner = document.createElement('div');
        spinner.className = 'gd-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        var texto = document.createElement('p');
        texto.className = 'text-sm font-semibold';
        texto.textContent = t('ui.gd.cargando', 'Cargando…');
        loading.appendChild(spinner);
        loading.appendChild(texto);
        document.body.appendChild(loading);
        return loading;
    }
    function mostrarLoading(texto) {
        var box = crearLoading();
        var p = box.querySelector('p');
        if (p && texto) p.textContent = texto;
        box.hidden = false;
    }
    function ocultarLoading() {
        if (loading) loading.hidden = true;
    }

    // =================== pre-roll ===================
    var preRollHecho = false;
    var inGame = false;

    function mostrarPreRoll() {
        if (preRollHecho) return Promise.resolve();
        preRollHecho = true;
        mostrarLoading(t('ui.gd.preparando', 'Preparando la partida…'));
        return esperarSdk(9000).then(function (listo) {
            if (!listo) return null;
            try {
                return Promise.resolve(window.gdsdk.showAd());
            } catch (e) {
                return null;
            }
        }).then(function () {
            // Un mínimo de feedback evita el parpadeo si el anuncio responde
            // al instante (o si el SDK no está disponible en local).
            return esperar(MIN_LOADING_MS);
        }).catch(function () {
            // El juego nunca debe quedarse bloqueado por un fallo del anuncio.
        }).then(function () {
            ocultarLoading();
        });
    }

    // =================== display ads ===================
    var pedidos = typeof WeakSet !== 'undefined' ? new WeakSet() : null;

    function ajustarSlot(slot) {
        if (!slot) return;
        var w = parseInt(slot.getAttribute('data-gd-w'), 10) || 300;
        var h = parseInt(slot.getAttribute('data-gd-h'), 10) || 250;
        var disponible = slot.clientWidth || (slot.parentNode && slot.parentNode.clientWidth) || w;
        var escala = Math.min(1, disponible / w);
        slot.style.setProperty('--gd-scale', String(escala));
        slot.style.height = Math.round(h * escala) + 'px';
    }

    function ajustarSlots() {
        var slots = document.querySelectorAll('.gd-ad-slot');
        for (var i = 0; i < slots.length; i++) ajustarSlot(slots[i]);
    }

    /** El SDK puede pintar el iframe de forma asíncrona: esperamos un poco. */
    function esperarRelleno(cont, ms) {
        var inicio = Date.now();
        return new Promise(function (resolve) {
            (function comprobar() {
                if (cont && (cont.childElementCount > 0 || String(cont.innerHTML || '').trim() !== '')) {
                    resolve(true);
                    return;
                }
                if (Date.now() - inicio >= ms) {
                    resolve(false);
                    return;
                }
                setTimeout(comprobar, 150);
            })();
        });
    }

    function mostrarDisplay(containerId) {
        var cont = document.getElementById(containerId);
        if (!cont) return Promise.resolve(false);
        if (pedidos) {
            if (pedidos.has(cont)) return Promise.resolve(false);
            pedidos.add(cont);
        }
        var slot = cont.closest ? cont.closest('.gd-ad-slot') : cont.parentNode;
        ajustarSlot(slot);
        return esperarSdk(6000).then(function (listo) {
            if (!listo) return false;
            var adType = (window.gdsdk.AdType && window.gdsdk.AdType.Display) || 'display';
            try {
                return Promise.resolve(window.gdsdk.showAd(adType, { containerId: containerId }));
            } catch (e) {
                return false;
            }
        }).then(function () {
            return esperarRelleno(cont, 2500);
        }).then(function (relleno) {
            if (!slot) return relleno;
            if (relleno) {
                slot.classList.remove('is-empty');
                slot.classList.add('is-filled');
                ajustarSlot(slot);
            } else {
                slot.classList.remove('is-filled');
                slot.classList.add('is-empty');
            }
            return relleno;
        }).catch(function () {
            if (slot) {
                slot.classList.remove('is-filled');
                slot.classList.add('is-empty');
            }
            return false;
        });
    }

    // API interna que usa app.game.js tras pintar los contenedores.
    window.__gdShowAd = function (containerId) {
        return mostrarDisplay(containerId);
    };

    // =================== arranque / vuelta al lobby ===================
    var arrancando = false;

    window.__gdStartGame = function (codigo, memoria) {
        if (!codigo || arrancando) return Promise.resolve();
        arrancando = true;
        return mostrarPreRoll().then(function () {
            inGame = true;
            document.body.classList.add('gd-jugando');
            var app = document.getElementById('app');
            if (app) app.className = 'flex-1 flex flex-col min-h-0';
            window.scrollTo(0, 0);
            try {
                return Promise.resolve(window.__juegoInit(codigo, memoria));
            } catch (e) {
                return null;
            }
        }).then(function (r) {
            arrancando = false;
            return r;
        }, function () {
            arrancando = false;
            return null;
        });
    };

    window.__gdVolverLobby = function () {
        inGame = false;
        document.body.classList.remove('gd-jugando');
        var D = window.DraftApp;
        if (D && typeof D.volverAlLobby === 'function') D.volverAlLobby();
        else window.location.reload();
        window.scrollTo(0, 0);
    };

    // =================== bloqueo de enlaces salientes ===================
    function esMismoDocumento(u) {
        if (u.origin !== window.location.origin) return false;
        if (u.pathname !== window.location.pathname) return false;
        var actual = new URL(window.location.href);
        // Selector de idioma: misma página, solo cambia ?lang.
        if (u.searchParams.has('lang')) {
            var copia = new URL(u.href);
            copia.searchParams.delete('lang');
            var base = new URL(actual.href);
            base.searchParams.delete('lang');
            return copia.href === base.href;
        }
        return u.href.split('#')[0] === actual.href.split('#')[0];
    }

    function bloquearEnlace() {
        var D = window.DraftApp;
        var msg = t('ui.gd.enlace_bloqueado', 'Esta sección está disponible en draft20.es');
        if (D && typeof D.toast === 'function') D.toast(msg, 2600);
    }

    document.addEventListener('click', function (ev) {
        var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (href.charAt(0) === '#') return;
        var u;
        try { u = new URL(a.href, window.location.href); } catch (e) { return; }
        if (esMismoDocumento(u)) return;
        ev.preventDefault();
        ev.stopPropagation();
        bloquearEnlace();
    }, true);

    // =================== eventos ===================
    document.addEventListener('d20:lobby', function () {
        // El lobby acaba de (re)pintarse: nuevo contenedor y nueva petición.
        setTimeout(function () { mostrarDisplay('gdAdLobby'); }, 0);
    });

    window.addEventListener('resize', ajustarSlots);
    window.addEventListener('orientationchange', function () { setTimeout(ajustarSlots, 150); });

    // Arranca la carga del SDK en cuanto la landing está lista (no bloquea el
    // primer render: el pre-roll solo se pide al pulsar jugar).
    function iniciar() {
        crearLoading();
        ocultarLoading();
        ajustarSlots();
        cargarSdk();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
