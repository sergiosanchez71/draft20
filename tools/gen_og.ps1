param(
    [string]$Root = (Split-Path -Parent $PSScriptRoot),
    [string]$Only = ''
)

Add-Type -AssemblyName System.Drawing

$temas = (Get-Content -Raw -Encoding UTF8 (Join-Path $env:TEMP 'temas_og.json') | ConvertFrom-Json)
$outDir = Join-Path $Root 'og\tematica'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

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
    $g.DrawString('Subasta por turnos para 2 jugadores  |  draft20.es', $fontPie, (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(148, 163, 184))), (New-Object System.Drawing.RectangleF(0, 540, $w, 60)), $sf)

    $g.Dispose()
    $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
}

$n = 0
foreach ($t in $temas) {
    if ($Only -ne '' -and $t.id -ne $Only) { continue }
    $path = Join-Path $outDir ($t.id + '.png')
    New-OgImage -Nombre $t.nombre -Categoria $t.categoria -Path $path
    $n++
}
Write-Output "OG generadas: $n en $outDir"
