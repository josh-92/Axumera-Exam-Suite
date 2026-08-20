<# analyze-kiosk-layout.ps1 - inspect the saved kiosk screenshot:
   1. top region rows (kiosk strip) should be predominantly white
   2. first red row (exam .info-bar 4px border #d9383a) should be ~36-40 px
#>
$ErrorActionPreference = 'Continue'
Add-Type -AssemblyName System.Drawing
$path = 'C:\Axumera-Enginnering\native\build\student-kiosk-layout.png'
if (-not (Test-Path $path)) { Write-Host "missing: $path"; exit 1 }
$bmp = New-Object System.Drawing.Bitmap($path)
Write-Host ("Screenshot: {0}x{1} (mtime {2})" -f $bmp.Width, $bmp.Height, (Get-Item $path).LastWriteTime)

$firstRedRow = -1
for ($y = 0; $y -lt [Math]::Min(80, $bmp.Height); $y++) {
    $found = $false
    for ($x = 0; $x -lt $bmp.Width; $x += 2) {
        $p = $bmp.GetPixel($x, $y)
        if ($p.R -ge 180 -and $p.G -le 110 -and $p.B -le 110 -and ($p.R - $p.G) -ge 80) { $found = $true; break }
    }
    if ($found) { $firstRedRow = $y; break }
}
Write-Host "firstRedRow = $firstRedRow (expected ~36-40: the .info-bar red border below the 36px strip)"

$goldRows = 0
for ($y = 0; $y -lt 40; $y++) {
    $gold = $false
    for ($x = 0; $x -lt $bmp.Width; $x += 2) {
        $p = $bmp.GetPixel($x, $y)
        if ($p.R -ge 190 -and $p.G -ge 130 -and $p.G -le 190 -and $p.B -le 100) { $gold = $true; break }
    }
    if ($gold) { $goldRows++ }
}
Write-Host "rows 0..39 containing gold EXIT-EXAM button pixels: $goldRows (expect > 0)"

$white = 0; $samples = 0
foreach ($y in 5, 10, 15, 20, 25, 30) {
    for ($x = 0; $x -lt $bmp.Width; $x += 8) {
        $p = $bmp.GetPixel($x, $y)
        if ($p.R -ge 245 -and $p.G -ge 245 -and $p.B -ge 245) { $white++ }
        $samples++
    }
}
Write-Host ("strip whiteness ratio: {0:P0}" -f ($white / $samples))

# row profile for the report: sample center-ish column every 10 rows
Write-Host "--- column x=683 pixel color per row (0..70) ---"
for ($y = 0; $y -le 70; $y += 5) {
    $p = $bmp.GetPixel(683, $y)
    Write-Host ("y={0,3}  R={1,3} G={2,3} B={3,3}  #{0:X2}{1:X2}{2:X2}" -f $y, $p.R, $p.G, $p.B)
}
$bmp.Dispose()
