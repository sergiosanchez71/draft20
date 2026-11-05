<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Draft 20 — Ranking por Idioma</title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #0f172a;
        color: #f1f5f9;
        margin: 0;
        padding: 20px;
        line-height: 1.5;
    }
    .container { max-width: 1200px; margin: 0 auto; }
    header {
        background: #1e293b;
        border-bottom: 2px solid #f59e0b;
        padding: 16px 24px;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    h1 { margin: 0; font-size: 1.5rem; color: #f59e0b; letter-spacing: -0.5px; }
    .subtitle { color: #94a3b8; font-size: 0.875rem; margin-top: 4px; }
    .auth-bar {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }
    input[type="text"], input[type="number"], select {
        background: #334155;
        border: 1px solid #475569;
        color: #f1f5f9;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 0.875rem;
    }
    input[type="text"]:focus, select:focus {
        outline: none;
        border-color: #f59e0b;
    }
    button {
        background: #f59e0b;
        color: #0f172a;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: 700;
        cursor: pointer;
        font-size: 0.875rem;
    }
    button:hover { background: #d97706; }
    button.secondary { background: #475569; color: #f1f5f9; }
    button.secondary:hover { background: #64748b; }
    .filters {
        background: #1e293b;
        padding: 16px 24px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        align-items: center;
        border: 1px solid #334155;
    }
    .filters label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: block; }
    .filters .field { display: flex; flex-direction: column; }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: #1e293b;
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 16px 20px;
    }
    .stat-card .value { font-size: 2rem; font-weight: 800; color: #f59e0b; line-height: 1; }
    .stat-card .label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; }
    .stat-card .pct { font-size: 0.875rem; color: #22c55e; margin-top: 4px; }
    table {
        width: 100%;
        border-collapse: collapse;
        background: #1e293b;
        border: 1px solid #334155;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 24px;
    }
    th {
        background: #334155;
        color: #94a3b8;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 10px 14px;
        text-align: left;
        font-weight: 600;
    }
    td {
        padding: 10px 14px;
        border-bottom: 1px solid #334155;
        font-size: 0.875rem;
    }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #334155; }
    .bar {
        height: 6px;
        background: #334155;
        border-radius: 3px;
        overflow: hidden;
        margin-top: 4px;
    }
    .bar-fill {
        height: 100%;
        background: #f59e0b;
    }
    .bar-fill.lang-es { background: #22c55e; }
    .bar-fill.lang-en { background: #3b82f6; }
    .section {
        margin-bottom: 32px;
    }
    .section h2 {
        font-size: 1rem;
        color: #f59e0b;
        margin: 0 0 12px 0;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .export {
        margin-top: 16px;
        text-align: right;
    }
    .muted { color: #64748b; font-size: 0.875rem; }
    .lang-es { color: #22c55e; }
    .lang-en { color: #3b82f6; }
    .lang-other { color: #94a3b8; }
    .no-data {
        text-align: center;
        padding: 40px;
        color: #64748b;
    }
</style>
</head>
<body>
<div class="container">
    <header>
        <div>
            <h1>Draft 20 — Ranking por Idioma</h1>
            <div class="subtitle">Vista interna de administración · Datos desde <code>api/datos/partidas_*.jsonl</code></div>
        </div>
        <div class="auth-bar">
            <input type="text" id="claveInput" placeholder="Clave API" maxlength="128">
            <button id="btnConsultar">Consultar</button>
            <button class="secondary" id="btnExportar">Exportar CSV</button>
        </div>
    </header>

    <div class="filters">
        <div class="field">
            <label>Idioma</label>
            <select id="filtroIdioma">
                <option value="todos">Todos los idiomas</option>
                <option value="es">Español</option>
                <option value="en">Inglés</option>
                <option value="NO_IDENTIFICADO">No identificado</option>
            </select>
        </div>
        <div class="field">
            <label>Rango</label>
            <select id="filtroRango">
                <option value="todos">Todo el histórico</option>
                <option value="7">Últimos 7 días</option>
                <option value="30">Últimos 30 días</option>
                <option value="90">Últimos 90 días</option>
            </select>
        </div>
        <div class="field">
            <label>Mostrar</label>
            <select id="filtroLimite">
                <option value="10">Top 10 temáticas</option>
                <option value="25">Top 25 temáticas</option>
                <option value="50">Top 50 temáticas</option>
            </select>
        </div>
    </div>

    <div class="stats-grid" id="statsGrid">
        <!-- se llena con JS -->
    </div>

    <div class="section">
        <h2>Temáticas más jugadas por idioma</h2>
        <div id="sectionTematicas">
            <!-- se llena con JS -->
        </div>
    </div>

    <div class="section">
        <h2>Evolución mensual por idioma</h2>
        <div id="sectionMensual">
            <!-- se llena con JS -->
        </div>
    </div>

    <div class="export">
        <button class="secondary" id="btnCopiarJSON">Copiar JSON</button>
    </div>
</div>

<script>
function getParam(name) {
    const params = new URLSearchParams(window.location.search);
    return params.get(name) || '';
}

const claveURL = getParam('clave');

function buildApiUrl(dias) {
    const clave = document.getElementById('claveInput').value.trim() || claveURL;
    return '/api/ranking.php?clave=' + encodeURIComponent(clave) + '&json=true&dias=' + dias;
}

async function cargarDatos(dias) {
    const res = await fetch(buildApiUrl(dias));
    if (!res.ok) throw new Error('Error ' + res.status);
    return res.json();
}

function fmt(n) { return n.toLocaleString('es-ES'); }
function pct(n, total) { return total ? ((n / total) * 100).toFixed(1) : 0; }
function barHtml(val, max, colorClass) {
    const w = max ? Math.round((val / max) * 100) : 0;
    return '<div class="bar"><div class="bar-fill ' + colorClass + '" style="width:' + w + '%;" title="' + val + '"></div></div>';
}

function render(datos) {
    const total = datos.total || 0;
    const idiomas = {};
    Object.values(datos.porMes || {}).forEach(m => {
        Object.entries(m.porIdiomas || {}).forEach(([idioma, c]) => {
            idiomas[idioma] = (idiomas[idioma] || 0) + c;
        });
    });

    const grid = document.getElementById('statsGrid');
    grid.innerHTML = '';
    Object.entries(idiomas).sort((a,b) => b[1]-a[1]).forEach(([idioma, c]) => {
        const cls = idioma === 'es' ? 'lang-es' : (idioma === 'en' ? 'lang-en' : 'lang-other');
        const nombre = idioma === 'es' ? 'Español' : (idioma === 'en' ? 'Inglés' : 'No identificado');
        grid.innerHTML += `
            <div class="stat-card">
                <div class="value">${fmt(c)}</div>
                <div class="label">${nombre}</div>
                <div class="pct">${pct(c, total)}% del total ${barHtml(c, Math.max(...Object.values(idiomas)), cls)}</div>
            </div>`;
    });

    const temSec = document.getElementById('sectionTematicas');
    const temOrdenado = Object.entries(datos.porTematica || {}).sort((a,b) => b[1]-a[1]);
    const limite = parseInt(document.getElementById('filtroLimite').value) || 10;
    let tabla = '<table><thead><tr><th>#</th><th>Temática</th><th>Partidas</th><th>Distribución</th></tr></thead><tbody>';
    temOrdenado.slice(0, limite).forEach(([t, c], i) => {
        const cls = i === 0 ? 'lang-es' : (i === 1 ? 'lang-en' : 'lang-other');
        tabla += `<tr><td>${i+1}</td><td>${htmlEscape(t)}</td><td>${fmt(c)}</td><td>${barHtml(c, temOrdenado[0][1], cls)}</td></tr>`;
    });
    tabla += '</tbody></table>';
    temSec.innerHTML = tabla;

    const mesSec = document.getElementById('sectionMensual');
    const mesesOrdenados = Object.entries(datos.porMes || {}).sort((a,b) => a[0] > b[0] ? 1 : -1);
    let tablaMes = '<table><thead><tr><th>Mes</th><th>Total</th><th>Español</th><th>Inglés</th><th>No identificado</th></tr></thead><tbody>';
    mesesOrdenados.forEach([mes, d] => {
        const idiomasMes = d.porIdiomas || {};
        tablaMes += `<tr>
            <td>${mes}</td>
            <td>${fmt(d.total)}</td>
            <td>${fmt(idiomasMes.es || 0)}</td>
            <td>${fmt(idiomasMes.en || 0)}</td>
            <td>${fmt(idiomasMes.NO_IDENTIFICADO || 0)}</td>
        </tr>`;
    });
    tablaMes += '</tbody></table>';
    mesSec.innerHTML = tablaMes;
}

function htmlEscape(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.getElementById('btnConsultar').addEventListener('click', async () => {
    const clave = document.getElementById('claveInput').value.trim();
    if (!clave) { alert('Ingresa la clave API'); return; }
    const dias = document.getElementById('filtroRango').value;
    window.location.href = window.location.pathname + '?clave=' + encodeURIComponent(clave) + '&dias=' + dias;
});

document.getElementById('btnExportar').addEventListener('click', () => {
    const clave = document.getElementById('claveInput').value.trim();
    if (!clave) { alert('Ingresa la clave API'); return; }
    const dias = document.getElementById('filtroRango').value;
    window.location.href = window.location.pathname + '?clave=' + encodeURIComponent(clave) + '&csv=true&dias=' + dias;
});

// Carga inicial con la clave ya en la URL (si la hay)
const params = new URLSearchParams(window.location.search);
const claveUrl = params.get('clave');
if (claveUrl) {
    document.getElementById('claveInput').value = claveUrl;
    const dias = params.get('dias') || '30';
    document.getElementById('filtroRango').value = dias;
    cargarDatos(dias).then(d => render(d)).catch(e => {
        document.body.innerHTML = '<div class="no-data">Error cargando datos: ' + e.message + '</div>';
    });
} else {
    document.body.innerHTML = '<div class="no-data">Ingresa tu clave API y presiona Consultar.</div>';
}
</script>
</body>
</html>