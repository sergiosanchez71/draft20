param(
    [string]$Root = (Split-Path -Parent $PSScriptRoot)
)

# Draft 20 — Genera los iconos pequeños del favicon desde icons/icon-512.png:
#   - icons/icon-48.png y icons/icon-96.png (múltiplos de 48 que pide Google)
#   - favicon.ico con 16, 32 y 48 px (entradas PNG, soportadas por navegadores
#     modernos y por Google)
#
# Uso:  powershell -File tools/gen_icons.ps1

Add-Type -AssemblyName System.Drawing

$origen = Join-Path $Root 'icons\icon-512.png'
if (-not (Test-Path $origen)) { Write-Error "No existe $origen"; exit 1 }

function New-PngEscalado {
    param([System.Drawing.Image]$Src, [int]$Lado, [string]$Path)
    $bmp = New-Object System.Drawing.Bitmap($Lado, $Lado)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.DrawImage($Src, 0, 0, $Lado, $Lado)
    $g.Dispose()
    $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
}

$src = [System.Drawing.Image]::FromFile($origen)
$salidas = @{}
foreach ($lado in 16, 32, 48, 96) {
    $tmp = Join-Path $env:TEMP ('draft20_icon_' + $lado + '.png')
    New-PngEscalado -Src $src -Lado $lado -Path $tmp
    $salidas[$lado] = $tmp
}
$src.Dispose()

# Iconos públicos 48 y 96.
Copy-Item $salidas[48] (Join-Path $Root 'icons\icon-48.png') -Force
Copy-Item $salidas[96] (Join-Path $Root 'icons\icon-96.png') -Force

# favicon.ico multi-tamaño (entradas PNG).
$entradas = @(16, 32, 48)
$datos = @{}
foreach ($lado in $entradas) { $datos[$lado] = [System.IO.File]::ReadAllBytes($salidas[$lado]) }

$icoPath = Join-Path $Root 'icons\favicon.ico'
$fs = [System.IO.File]::Create($icoPath)
$bw = New-Object System.IO.BinaryWriter($fs)
try {
    $bw.Write([UInt16]0)                 # reserved
    $bw.Write([UInt16]1)                 # type: icon
    $bw.Write([UInt16]$entradas.Count)   # count
    $offset = 6 + 16 * $entradas.Count
    foreach ($lado in $entradas) {
        $bw.Write([Byte]$lado)           # width
        $bw.Write([Byte]$lado)           # height
        $bw.Write([Byte]0)               # palette
        $bw.Write([Byte]0)               # reserved
        $bw.Write([UInt16]1)             # planes
        $bw.Write([UInt16]32)            # bpp
        $bw.Write([UInt32]$datos[$lado].Length)
        $bw.Write([UInt32]$offset)
        $offset += $datos[$lado].Length
    }
    foreach ($lado in $entradas) { $bw.Write($datos[$lado]) }
} finally {
    $bw.Close(); $fs.Close()
}

foreach ($tmp in $salidas.Values) { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }

# Copia en la raíz para el descubrimiento por defecto de /favicon.ico
# (el HTML declara /icons/favicon.ico con ?v= para saltar la caché del CDN).
Copy-Item $icoPath (Join-Path $Root 'favicon.ico') -Force

Write-Output ('icon-48.png: ' + (Get-Item (Join-Path $Root 'icons\icon-48.png')).Length + ' bytes')
Write-Output ('icon-96.png: ' + (Get-Item (Join-Path $Root 'icons\icon-96.png')).Length + ' bytes')
Write-Output ('icons/favicon.ico + favicon.ico: ' + (Get-Item $icoPath).Length + ' bytes (16/32/48)')
