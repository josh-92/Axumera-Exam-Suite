<# diag-screen.ps1 - print display info + E2E prerequisites (dev runtime). #>
$ErrorActionPreference = 'Continue'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$s = [System.Windows.Forms.Screen]::PrimaryScreen
Write-Host ("Screen: {0}x{1}" -f $s.Bounds.Width, $s.Bounds.Height)
$g = [System.Drawing.Graphics]::FromHwnd([IntPtr]::Zero)
Write-Host ("DPI: {0} (scale {1:P0})" -f $g.DpiX, ($g.DpiX / 96.0))

$mysql = 'C:\Axumera-Enginnering\native\dev-runtime\runtime\mariadb\bin\mysql.exe'
Write-Host "--- students (roll 107) ---"
& $mysql --protocol=tcp -h 127.0.0.1 -P 3310 -u root --skip-password -N -e "SELECT id, full_name, roll_number, stream, section FROM eaes_exam.students WHERE roll_number = 107;" 2>&1
Write-Host "--- live exam ---"
& $mysql --protocol=tcp -h 127.0.0.1 -P 3310 -u root --skip-password -N -e "SELECT id, exam_name, status, starts_at, ends_at FROM eaes_exam.exams WHERE status = 'live' OR NOW() BETWEEN starts_at AND ends_at;" 2>&1
Write-Host "--- license (dev app) ---"
if (Test-Path 'C:\Axumera-Enginnering\native\dev-runtime\application\eaes_exam_system\storage\license.lic') { Write-Host 'license.lic present' } else { Write-Host 'license.lic MISSING' }
