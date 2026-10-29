param(
    [string]$Root = (Split-Path -Parent $PSScriptRoot),
    [string]$Data = (Join-Path $env:TEMP 'og_data.json'),
    [string]$Tipo = '',
    [string]$Only = '',
    [string]$Lang = 'es',
    [switch]$Generic
)

# Genera las imágenes OG (1200x630) de categorías, guías y secciones.
# Antes:  php tools/og_data.php  (escribe el JSON que se lee aquí)

Add-Type -AssemblyName System.Drawing

if ($Lang -eq 'en' -and $Data -eq (Join-Path $env:TEMP 'og_data.json')) {
    $Data = Join-Path $env:TEMP 'og_data_en.json'
}
if (-not (Test-Path $Data)) {
    Write-Error "No existe $Data. Ejecuta antes: php tools/og_data.php"
    exit 1
}

$entradas = (Get-Content -Raw -Encoding UTF8 $Data | ConvertFrom-Json)

function New-OgImage {
    param(
        [string]$Nombre,
        [string]$Categoria,
        [string]$Path
    )
    $w = 1200; $h = 630
    $innerW = $w - 80
    $bmp = New-Object System.Drawing.Bitmap($w, $h)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAlias
    $g.Clear([System.Drawing.Color]::FromArgb(15, 23, 42))
    $amber = [System.Drawing.Color]::FromArgb(251, 191, 36)
    $g.FillRectangle((New-Object System.Drawing.SolidBrush($amber)), 0, 0, $w, 10)

    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center

    $fontMarca = New-Object System.Drawing.Font('Arial', 46, [System.Drawing.FontStyle]::Bold)
    $g.DrawString('Draft 20', $fontMarca, (New-Object System.Drawing.SolidBrush($amber)), (New-Object System.Drawing.RectangleF(0, 40, $w, 90)), $sf)

    $size = 96
    do {
        $fontName = New-Object System.Drawing.Font('Arial', $size, [System.Drawing.FontStyle]::Bold)
        $ancho = $g.MeasureString($Nombre, $fontName).Width
        if ($ancho -le 1040) { break }
        $size -= 6
    } while ($size -gt 44)
    $g.DrawString($Nombre, $fontName, (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)), (New-Object System.Drawing.RectangleF(40, 200, $innerW, 180)), $sf)

    $fontCat = New-Object System.Drawing.Font('Arial', 38)
    $g.DrawString($Categoria, $fontCat, (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(203, 213, 225))), (New-Object System.Drawing.RectangleF(0, 400, $w, 80)), $sf)

    $fontPie = New-Object System.Drawing.Font('Arial', 26)
    $pie = if ($Lang -eq 'en') { 'Turn-based auction for 2 players  |  draft20.es' } else { 'Subasta por turnos para 2 jugadores  |  draft20.es' }
    $g.DrawString($pie, $fontPie, (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(148, 163, 184))), (New-Object System.Drawing.RectangleF(0, 540, $w, 60)), $sf)

    $g.Dispose()
    $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
}

function New-OgGeneric {
    param(
        [string]$Path,
        [string]$Lang
    )
    $w = 1200; $h = 630
    $bmp = New-Object System.Drawing.Bitmap($w, $h)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAlias
    $g.Clear([System.Drawing.Color]::FromArgb(15, 23, 42))
    $amber = [System.Drawing.Color]::FromArgb(251, 191, 36)
    $g.FillRectangle((New-Object System.Drawing.SolidBrush($amber)), 0, 0, $w, 10)

    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center

    $fontTitulo = New-Object System.Drawing.Font('Arial', 110, [System.Drawing.FontStyle]::Bold)
    $g.DrawString('Draft 20', $fontTitulo, (New-Object System.Drawing.SolidBrush($amber)), (New-Object System.Drawing.RectangleF(0, 70, $w, 190)), $sf)

    $subtitulo = if ($Lang -eq 'en') { 'Turn-based auction for 2 players' } else { 'Subasta por turnos para 2 jugadores' }
    $fontSub = New-Object System.Drawing.Font('Arial', 42)
    $g.DrawString($subtitulo, $fontSub, (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(226, 232, 240))), (New-Object System.Drawing.RectangleF(0, 290, $w, 90)), $sf)

    $fontMoneda = New-Object System.Drawing.Font('Arial', 30, [System.Drawing.FontStyle]::Bold)
    $brochaOro = New-Object System.Drawing.SolidBrush($amber)
    $brochaOscura = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(15, 23, 42))
    foreach ($x in @(420, 540, 660)) {
        $g.FillEllipse($brochaOro, $x, 400, 80, 80)
        $g.DrawString('20', $fontMoneda, $brochaOscura, (New-Object System.Drawing.RectangleF($x, 400, 80, 80)), $sf)
    }

    $fontPie = New-Object System.Drawing.Font('Arial', 28)
    $g.DrawString('draft20.es', $fontPie, (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(148, 163, 184))), (New-Object System.Drawing.RectangleF(0, 540, $w, 60)), $sf)

    $g.Dispose()
    $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
}

$n = 0
foreach ($e in $entradas) {
    if ($Tipo -ne '' -and $e.tipo -ne $Tipo) { continue }
    if ($Only -ne '' -and $e.id -ne $Only) { continue }
    $dir = if ($Lang -eq 'en') { Join-Path $Root ('og\en\' + $e.tipo) } else { Join-Path $Root ('og\' + $e.tipo) }
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    New-OgImage -Nombre $e.nombre -Categoria $e.categoria -Path (Join-Path $dir ($e.id + '.png'))
    $n++
}
Write-Output "OG generadas: $n"

if ($Generic) {
    $dirGenerica = if ($Lang -eq 'en') { Join-Path $Root 'og\en' } else { Join-Path $Root 'og' }
    New-Item -ItemType Directory -Force -Path $dirGenerica | Out-Null
    New-OgGeneric -Path (Join-Path $dirGenerica 'og-image.png') -Lang $Lang
    Write-Output "OG genérica: $dirGenerica\og-image.png"
}
