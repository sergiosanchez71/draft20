param(
    [switch]$Medir,
    [int]$Muestras = 6,
    [string]$Base = 'https://draft20.es'
)

# Draft 20 — Precalentado de origen+CDN y medición de latencia tras un deploy.
#
#   powershell -File tools/warmup.ps1            # precalienta (HTML, fichas y assets)
#   powershell -File tools/warmup.ps1 -Medir     # mide mediana/p95 por endpoint
#
# Tras cada push, la primera oleada de visitas encuentra el CDN frío (los assets
# van versionados con ?v=filemtime) y Opcache recompilando: eso provoca picos de
# varios segundos. El precalentado los evita.

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'

function Get-UrlsPrecalentar {
    param([string]$BaseUrl)

    $urls = [System.Collections.Generic.List[string]]::new()
    $fijas = @(
        '/', '/como-jugar', '/guias', '/glosario', '/juegos-de-subasta',
        '/sitemap.xml', '/feed.xml', '/manifest.webmanifest', '/sw.js',
        '/og-image.png', '/icons/icon-192.png', '/img/emoji/1f410.svg',
        '/tematica/futbol', '/tematica/anime', '/tematica/hamburguesa',
        '/categoria/comida', '/guia/draft-de-20-monedas', '/juego.php?codigo=ABCDE'
    )
    foreach ($p in $fijas) { $urls.Add($BaseUrl + $p) }

    # Assets reales (con ?v=filemtime) extraídos del HTML, para no hardcodear versiones.
    foreach ($htmlUrl in @('/', '/juego.php?codigo=ABCDE')) {
        try {
            $html = (Invoke-WebRequest -Uri ($BaseUrl + $htmlUrl) -UseBasicParsing -TimeoutSec 30 -Headers @{ 'User-Agent' = 'Draft20Warmup/1.0' }).Content
            foreach ($m in [regex]::Matches($html, '(?:href|src)="(/[^"]+\.(?:css|js)\?v=\d+)"')) {
                $urls.Add($BaseUrl + $m.Groups[1].Value)
            }
        } catch {
            Write-Output ('  aviso: no se pudo leer ' + $htmlUrl + ' para extraer assets')
        }
    }
    return ($urls | Select-Object -Unique)
}

function Invoke-Timed {
    param([string]$Url, [string]$Body = '')
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        if ($Body -ne '') {
            $r = Invoke-WebRequest -Uri $Url -Method POST -ContentType 'application/json' -Body $Body -UseBasicParsing -TimeoutSec 30 -Headers @{ 'User-Agent' = 'Draft20Warmup/1.0' }
        } else {
            $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 30 -Headers @{ 'User-Agent' = 'Draft20Warmup/1.0' }
        }
        $sw.Stop()
        return [pscustomobject]@{ Ok = $true; Code = [int]$r.StatusCode; Ms = $sw.ElapsedMilliseconds }
    } catch {
        $sw.Stop()
        $code = 0
        try { $code = [int]$_.Exception.Response.StatusCode } catch { /* sin respuesta */ }
        return [pscustomobject]@{ Ok = $false; Code = $code; Ms = $sw.ElapsedMilliseconds }
    }
}

if (-not $Medir) {
    $urls = Get-UrlsPrecalentar -BaseUrl $Base
    Write-Output ("Precalentando {0} URLs en {1}" -f $urls.Count, $Base)
    $fallos = 0
    foreach ($u in $urls) {
        $t = Invoke-Timed -Url $u
        $corta = $u -replace [regex]::Escape($Base), ''
        if ($t.Ok) {
            Write-Output ("  OK   {0,6} ms  {1}" -f $t.Ms, $corta)
        } else {
            $t2 = Invoke-Timed -Url $u
            if ($t2.Ok) {
                Write-Output ("  OK   {0,6} ms  {1}  (reintento)" -f $t2.Ms, $corta)
            } else {
                $fallos++
                Write-Output ("  FAIL       ---  {0}  (HTTP {1})" -f $corta, $t.Code)
            }
        }
    }
    Write-Output ("Precalentado terminado: {0} fallos" -f $fallos)
    exit ([int]($fallos -gt 0))
}

$objetivos = @(
    @{ Nombre = 'home'; Url = $Base + '/' },
    @{ Nombre = 'ficha'; Url = $Base + '/tematica/futbol' },
    @{ Nombre = 'juego-html'; Url = $Base + '/juego.php?codigo=ABCDE' },
    @{ Nombre = 'bundle-js'; Url = $Base + '/js/app.core.min.js' },
    @{ Nombre = 'sitemap'; Url = $Base + '/sitemap.xml' },
    @{ Nombre = 'api-cola'; Url = $Base + '/api/partida_rapida.php'; Body = '{"accion":"cola"}' }
)

Write-Output ("Midiendo {0} muestras por endpoint en {1}" -f $Muestras, $Base)
foreach ($o in $objetivos) {
    $tiempos = @()
    for ($i = 0; $i -lt $Muestras; $i++) {
        $t = Invoke-Timed -Url $o.Url -Body $o.Body
        if (-not $t.Ok) {
            Write-Output ("  {0,-11} fallo de red (HTTP {1})" -f $o.Nombre, $t.Code)
            $tiempos = @()
            break
        }
        $tiempos += $t.Ms
        Start-Sleep -Milliseconds 150
    }
    if ($tiempos.Count -eq 0) { continue }
    $orden = $tiempos | Sort-Object
    $mediana = $orden[[int][math]::Floor($orden.Count / 2)]
    $p95 = $orden[[int][math]::Ceiling($orden.Count * 0.95) - 1]
    $aviso = if ($p95 -gt 1500) { '  ← pico (>1,5 s)' } else { '' }
    Write-Output ("  {0,-11} mediana {1,6} ms   p95 {2,6} ms   max {3,6} ms{4}" -f $o.Nombre, $mediana, $p95, $orden[-1], $aviso)
}
Write-Output 'Repite la medición 1 y 10 minutos después del deploy: si la primera da picos y la segunda no, es arranque en frío.'
exit 0
