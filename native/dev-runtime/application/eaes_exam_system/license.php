<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:06              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 declare (strict_types=1); require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\License; $tvwac = ''; $FKlJI = ''; if (!($_SERVER['REQUEST_METHOD'] === 'POST')) { goto TtYae; } if (!Csrf::verify($_POST['csrf_token'] ?? null)) { goto Wz7An; } $wphBH = License::activateUpload($_FILES['license_file'] ?? []); if ($wphBH['ok']) { goto u5S9Q; } $FKlJI = $wphBH['message']; goto WO9uR; u5S9Q: $tvwac = $wphBH['message']; WO9uR: goto n3HsU; Wz7An: $FKlJI = 'Your form session expired. Please try again.'; n3HsU: TtYae: $BhSDG = License::status(); $U8MTm = License::hardwareId() ?? ''; $YxG32 = !empty($_SESSION['admin_logged_in']); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Software Activation — <?php  echo htmlspecialchars(B_K5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: var(--color-ink); }
        .activation-card { width:100%; max-width:620px; padding:36px; }
        .hardware-id { font-family:monospace; letter-spacing:.04em; }
        .copy-button { margin-top:8px; }
    </style>
</head>
<body>
    <div class="page-shell">
    <main class="login-main">
    <div class="activation-card card">
        <h2>Software Activation</h2>

        <?php  if (!($tvwac !== '')) { goto Nd7X2; } ?>
            <div class="alert alert-success"><?php  echo htmlspecialchars($tvwac); ?></div>
        <?php  Nd7X2: ?>
        <?php  if ($FKlJI !== '') { goto bCy6D; } if (!$BhSDG['valid']) { goto j8l6k; } goto oj9j_; bCy6D: ?>
            <div class="alert alert-error"><?php  echo htmlspecialchars($FKlJI); ?></div>
        <?php  goto oj9j_; j8l6k: ?>
            <div class="alert alert-warning"><?php  echo htmlspecialchars($BhSDG['message']); ?></div>
        <?php  oj9j_: ?>

        <?php  if ($BhSDG['valid']) { goto dxImf; } ?>
            <p class="text-muted">Send this hardware ID to your software provider to receive a signed <code>license.lic</code> file.</p>
            <div class="form-group">
                <label for="hardware_id">Detected Motherboard HWID</label>
                <input id="hardware_id" class="form-control hardware-id" type="text" readonly value="<?php  echo htmlspecialchars($U8MTm); ?>">
                <button class="btn btn-secondary copy-button" type="button" onclick="copyHardwareId(this)">Copy Hardware ID</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?php  echo Csrf::field(); ?>
                <div class="form-group">
                    <label for="license_file">Signed license file</label>
                    <input id="license_file" class="form-control" type="file" name="license_file" accept=".lic,application/octet-stream" required>
                </div>
                <button class="btn btn-primary" type="submit">Activate Software</button>
            </form>
        <?php  goto zzj9G; dxImf: ?>
            <div class="alert alert-success">
                License active for <strong><?php  echo htmlspecialchars($BhSDG['school_name']); ?></strong> through
                <strong><?php  echo htmlspecialchars($BhSDG['expires']); ?></strong>.
            </div>
            <a class="btn btn-primary" href="<?php  echo $YxG32 ? 'adminpanel.php' : 'adminlogin.php'; ?>">Continue</a>
        <?php  zzj9G: ?>
    </div>
    </main>
    <script>
        function copyHardwareId(button) {
            const value = document.getElementById('hardware_id').value;
            navigator.clipboard.writeText(value).then(() => { button.textContent = 'Copied'; });
        }
    </script>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
    </div>
</body>
</html>
