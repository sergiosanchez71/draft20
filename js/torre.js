/**
 * Draft 20 — Torre de Batalla: lógica pura y persistencia (sin DOM).
 *
 * 10 pisos en solitario contra el bot en extremo y modo secreto. La UI
 * (lobby, HUD, modales) vive en app.core.js / app.game.js; este módulo solo
 * decide y guarda. Sin dependencias: funciona en navegador y en Node
 * (tests/torre.test.js) con un localStorage inyectable.
 */
(function () {
    'use strict';

    // Visibilidad de la Torre en el lobby: false = oculta hasta nuevo aviso.
    // Toda la lógica sigue intacta; al reactivar, volver a pintar la tarjeta.
    var VISIBLE = false;
    var CLAVE = 'draft20_torre';
    var CLAVE_HECHAS = 'draft20_torre_hechas';
    var TOTAL_PISOS = 10;
    var MAX_REVIVES = 3;
    // Sin red de anuncios: 1 vida efectiva. El sistema de revives está
    // completo y testeado; al llegar los anuncios basta ponerlo a true
    // (y cablear el SDK en mostrarAnuncioRecompensado).
    var REVIVES_ACTIVOS = false;
    var DIFICULTAD = 'extremo';
    var MONEDAS_INICIALES = 20;

    var store = null;
    try { store = localStorage; } catch (e) { store = null; }

    function ahora() { return Date.now(); }

    function runVacio() {
        return {
            pisoActual: 1,
            vidasRestantes: MAX_REVIVES,
            partidasGanadas: 0,
            tematicasJugadas: [],
            enProgreso: false,
            fecha: 0,
            codigoSala: null,
            temaPiso: null,
            gastado: 0,
        };
    }

    function sanear(obj) {
        var r = runVacio();
        if (!obj || typeof obj !== 'object') return r;
        if (obj.enProgreso === true) r.enProgreso = true;
        var piso = parseInt(obj.pisoActual, 10);
        r.pisoActual = (piso >= 1 && piso <= TOTAL_PISOS) ? piso : 1;
        var vidas = parseInt(obj.vidasRestantes, 10);
        r.vidasRestantes = (vidas >= 0 && vidas <= MAX_REVIVES) ? vidas : MAX_REVIVES;
        r.partidasGanadas = Math.max(0, parseInt(obj.partidasGanadas, 10) || 0);
        r.tematicasJugadas = Array.isArray(obj.tematicasJugadas)
            ? obj.tematicasJugadas.filter(function (x) { return typeof x === 'string'; })
            : [];
        r.fecha = Math.max(0, parseInt(obj.fecha, 10) || 0);
        r.codigoSala = (typeof obj.codigoSala === 'string' && obj.codigoSala !== '') ? obj.codigoSala : null;
        r.temaPiso = (typeof obj.temaPiso === 'string' && obj.temaPiso !== '') ? obj.temaPiso : null;
        r.gastado = Math.max(0, parseInt(obj.gastado, 10) || 0);
        return r;
    }

    function guardar(run) {
        try {
            if (store) store.setItem(CLAVE, JSON.stringify(run));
        } catch (e) { /* sin almacenamiento no se persiste */ }
        return run;
    }

    function cargar() {
        try {
            if (!store) return runVacio();
            var raw = store.getItem(CLAVE);
            if (!raw) return runVacio();
            return sanear(JSON.parse(raw));
        } catch (e) {
            return runVacio();
        }
    }

    /** Empieza una subida desde el piso 1 (reinicia cualquier run previo). */
    function empezar() {
        var r = runVacio();
        r.enProgreso = true;
        r.fecha = ahora();
        return guardar(r);
    }

    /**
     * Temática del piso: aleatoria entre el catálogo, evitando las ya
     * jugadas en esta subida. Si se agotasen (imposible con 10 << 69),
     * se permite repetir.
     */
    function temaParaPiso(jugadas, todas) {
        var vistas = {};
        (Array.isArray(jugadas) ? jugadas : []).forEach(function (id) { vistas[id] = true; });
        var bolsa = (Array.isArray(todas) ? todas : []).filter(function (id) { return !vistas[id]; });
        if (bolsa.length === 0) bolsa = (Array.isArray(todas) ? todas : []).slice();
        if (bolsa.length === 0) return 'hamburguesa';
        return bolsa[Math.floor(Math.random() * bolsa.length)];
    }

    /**
     * Resultado del piso con la regla del juego: más ⭐ gana; empate a ⭐
     * lo rompen las monedas; empate total = 'tablas' (en la Torre es derrota).
     */
    function resultadoPiso(miScore, rivalScore, miDinero, rivalDinero) {
        miScore = +miScore || 0; rivalScore = +rivalScore || 0;
        miDinero = +miDinero || 0; rivalDinero = +rivalDinero || 0;
        if (miScore > rivalScore) return 'win';
        if (rivalScore > miScore) return 'loss';
        if (miDinero > rivalDinero) return 'win';
        if (rivalDinero > miDinero) return 'loss';
        return 'tablas';
    }

    /**
     * Desglose del piso para la pantalla de resultados (puro y testeable).
     * items: [{valor, precio?}]; gasto exacto = 20 − dinero restante.
     */
    function resumenPiso(miItems, rivalItems, miDinero, rivalDinero) {
        function tot(items) {
            var score = 0;
            (Array.isArray(items) ? items : []).forEach(function (it) {
                score += (it && +it.valor) || 0;
            });
            return score;
        }
        var miScore = tot(miItems), rivalScore = tot(rivalItems);
        miDinero = Math.max(0, +miDinero || 0);
        rivalDinero = Math.max(0, +rivalDinero || 0);
        var res = resultadoPiso(miScore, rivalScore, miDinero, rivalDinero);
        return {
            miScore: miScore,
            rivalScore: rivalScore,
            miGastado: Math.max(0, MONEDAS_INICIALES - miDinero),
            rivalGastado: Math.max(0, MONEDAS_INICIALES - rivalDinero),
            resultado: res,
            desempate: miScore === rivalScore && res !== 'tablas',
        };
    }

    /** Registra el piso superado (SOLO entre partidas: anti-exploit). */
    function superarPiso(run, tema, dineroRestante) {
        run.partidasGanadas += 1;
        if (typeof tema === 'string' && tema !== '') run.tematicasJugadas.push(tema);
        run.gastado += Math.max(0, MONEDAS_INICIALES - (parseInt(dineroRestante, 10) || 0));
        run.pisoActual += 1;
        run.codigoSala = null;
        run.temaPiso = null;
        return guardar(run);
    }

    /** Gasta un revive (se persiste al instante: recargar no lo devuelve). */
    function consumirRevive(run) {
        if (!REVIVES_ACTIVOS) return false;
        if (run.vidasRestantes <= 0) return false;
        run.vidasRestantes -= 1;
        guardar(run);
        return true;
    }

    function revivesUsados(run) {
        return MAX_REVIVES - (parseInt(run.vidasRestantes, 10) || 0);
    }

    function victoria(run) {
        return !!run.enProgreso && run.partidasGanadas >= TOTAL_PISOS;
    }

    /** Termina el run (game over o victoria): limpia el progreso activo. */
    function terminar() {
        return guardar(runVacio());
    }

    /**
     * Salas ya procesadas (anti-reproceso al volver con atrás/recarga a una
     * final ya resuelta: el guardián en memoria no sobrevive a la recarga).
     */
    function leerHechas() {
        try {
            var raw = store && store.getItem(CLAVE_HECHAS);
            var lista = raw ? JSON.parse(raw) : [];
            return Array.isArray(lista) ? lista.filter(function (x) { return typeof x === 'string'; }) : [];
        } catch (e) {
            return [];
        }
    }
    function pisoHecho(codigo) {
        return leerHechas().indexOf(codigo) !== -1;
    }
    function marcarPisoHecho(codigo) {
        try {
            if (typeof codigo !== 'string' || codigo === '') return;
            var lista = leerHechas();
            if (lista.indexOf(codigo) === -1) {
                lista.push(codigo);
                while (lista.length > 10) lista.shift();
                if (store) store.setItem(CLAVE_HECHAS, JSON.stringify(lista));
            }
        } catch (e) { /* ignore */ }
    }

    /**
     * Logros ganados por el estado actual (el llamante difiere contra
     * draft20_logros para mostrar solo los nuevos).
     */
    function logrosGanados(run, esVictoria) {
        var ids = [];
        if (run.partidasGanadas >= 3) ids.push('torre_piso3');
        if (run.partidasGanadas >= 7) ids.push('torre_piso7');
        if (esVictoria && run.partidasGanadas >= TOTAL_PISOS) ids.push('torre_piso10');
        if (esVictoria && revivesUsados(run) === 0) ids.push('torre_intocable');
        return ids;
    }

    function textoCompartir(run, nombre) {
        var quien = (nombre && String(nombre).trim()) || 'Alguien';
        return '🏆 ' + quien + ' ha conquistado la Torre de Draft 20 (10/10 a ciegas contra el bot extremo). '
            + 'Monedas gastadas: ' + run.gastado + '. Revives usados: ' + revivesUsados(run) + '. '
            + 'Temáticas: ' + run.tematicasJugadas.length + '/10. ¿Te atreves? https://draft20.es/';
    }

    /**
     * Anuncio recompensado desacoplado. Hoy: mock (3 s y éxito).
     * Mañana (red real): poner AD_RED a true y cablear adBreak/AdinPlay aquí;
     * la firma (onExito, onCancelado + {cancelar}) no cambia.
     */
    var AD_RED = false;
    function mostrarAnuncioRecompensado(onExito, onCancelado) {
        if (AD_RED && typeof adBreak !== 'undefined') {
            try {
                adBreak({
                    type: 'reward',
                    name: 'torre-revive',
                    beforeAd: function () {},
                    afterAd: function () {},
                    beforeReward: function (showAdFn) { showAdFn(); },
                    adDismissed: function () { if (onCancelado) onCancelado(); },
                    adViewed: function () { if (onExito) onExito(); },
                });
                return { cancelar: function () { if (onCancelado) onCancelado(); } };
            } catch (e) { /* cae al mock */ }
        }
        var t = setTimeout(function () { if (onExito) onExito(); }, 3000);
        return {
            cancelar: function () {
                clearTimeout(t);
                if (onCancelado) onCancelado();
            },
        };
    }

    var Torre = {
        VISIBLE: VISIBLE,
        CLAVE: CLAVE,
        TOTAL_PISOS: TOTAL_PISOS,
        MAX_REVIVES: MAX_REVIVES,
        REVIVES_ACTIVOS: REVIVES_ACTIVOS,
        DIFICULTAD: DIFICULTAD,
        cargar: cargar,
        guardar: guardar,
        empezar: empezar,
        temaParaPiso: temaParaPiso,
        resultadoPiso: resultadoPiso,
        resumenPiso: resumenPiso,
        superarPiso: superarPiso,
        consumirRevive: consumirRevive,
        revivesUsados: revivesUsados,
        victoria: victoria,
        terminar: terminar,
        pisoHecho: pisoHecho,
        marcarPisoHecho: marcarPisoHecho,
        logrosGanados: logrosGanados,
        textoCompartir: textoCompartir,
        mostrarAnuncioRecompensado: mostrarAnuncioRecompensado,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = Torre;
    } else if (typeof window !== 'undefined') {
        window.Torre = Torre;
    }
})();
