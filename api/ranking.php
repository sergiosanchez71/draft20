<?php
/**
 * Draft 20 — API: ranking de partidas (solo lectura, con clave).
 *
 * Lee api/datos/partidas_humanas.jsonl y api/datos/partidas.jsonl.
 * Acceso con ?clave=<secreto>, donde el secreto vive en
 * api/datos/clave_ranking.txt (fuera de git; se crea a mano en el
 * servidor). Sin clave válida → 403.
 *
 * Por defecto devuelve JSON crudo. Añade ?html=true para obtener el panel
 * HTML interno de administración (o usa directamente admin/ranking.php).
 *
 * --- Petición JSON ---
 *   GET api/ranking.php?clave=SECRETO
 *   Sin dias = todo el histórico sin filtro, con desglose por meses.
 *   dias=N (1-180) = solo los últimos N días; dias=0 o dias=todo = histórico.
 *
 * --- Respuesta JSON 200 ---
 *   { "ok": true, "dias": 0, "total": 12, "porTematica": {...},
 *     "porModo": {...}, "porTipo": {...}, "porResultado": {...}, "porFranja": {...},
 *     "porMes": {"2026-09": {"total": 12, "topTematica": "pizza",
 *       "top": {"pizza": 5, ...}, "porTipo": {...}}} }
 *   top = mapa ordenado temática → partidas del top 10 mensual (uso interno).
 */
declare(strict_types=1);

require_once __DIR__ . '/ranking_lib.php';

if (!function_exists('responder')) {
    function responder(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder(['ok' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
}

$secreto = trim((string) @file_get_contents(__DIR__ . '/datos/clave_ranking.txt'));
$clave = isset($_GET['clave']) && is_string($_GET['clave']) ? $_GET['clave'] : '';
if ($secreto === '' || !hash_equals($secreto, $clave)) {
    responder(['ok' => false, 'error' => 'No autorizado.'], 403);
}

$diasRaw = isset($_GET['dias']) && is_string($_GET['dias']) ? $_GET['dias'] : '0';
$dias = ($diasRaw === '0' || strtolower($diasRaw) === 'todo') ? 0 : max(1, min(180, (int) $diasRaw));
$html = isset($_GET['html']) && strtolower($_GET['html']) === 'true';

$agg = ranking_humanas([
    __DIR__ . '/datos/partidas_humanas.jsonl',
    __DIR__ . '/datos/partidas.jsonl',
], $dias);

if (!$html) {
    responder(['ok' => true, 'dias' => $dias] + $agg, 200);
}

// Panel HTML interno (cuando se pide ?html=true)
$titulo = 'Draft 20 — Ranking por Idioma';
$diasLabel = $dias === 0 ? 'Todo el histórico' : 'Últimos ' . $dias . ' días';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo) ?></title>
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
            <h1><?= htmlspecialchars($titulo) ?></h1>
            <div class="subtitle">Vista interna de administración · Datos desde <code>api/datos/partidas_*.jsonl</code></div>
        </div>
        <div class="auth-bar">
            <input type="text" id="claveInput" placeholder="Clave API" value="<?= htmlspecialchars($clave) ?>" maxlength="128">
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
const API_URL = window.location.pathname + '?clave=' + encodeURIComponent(<?= json_encode($clave) ?>) + '&json=true&dias=';
const claveActual = <?= json_encode($clave) ?>;

async function cargarDatos(dias) {
    const res = await fetch(API_URL + dias);
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
    // Totales
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

    // Temáticas globales (top)
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

    // Mensual
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
    window.location.href = window.location.pathname + '?clave=' + encodeURIComponent(clave) + '&html=true&dias=' + document.getElementById('filtroRango').value;
});

document.getElementById('btnExportar').addEventListener('click', () => {
    window.location.href = window.location.pathname + '?clave=' + encodeURIComponent(document.getElementById('claveInput').value) + '&csv=true&dias=' + document.getElementById('filtroRango').value;
});

// Carga inicial con la clave ya en la URL (si la hay)
const params = new URLSearchParams(window.location.search);
if (params.get('clave')) {
    document.getElementById('claveInput').value = params.get('clave');
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
<?php
exit;