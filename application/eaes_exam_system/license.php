<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\License;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } else {
        $result = License::activateUpload($_FILES['license_file'] ?? []);
        if ($result['ok']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

$status = License::status();
$hardwareId = License::hardwareId() ?? '';
$isAdmin = !empty($_SESSION['admin_logged_in']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Software Activation — <?php echo htmlspecialchars(config('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: var(--color-ink); display:flex; justify-content:center; align-items:center; min-height:100vh; padding:20px; }
        .activation-card { width:100%; max-width:620px; padding:36px; }
        .hardware-id { font-family:monospace; letter-spacing:.04em; }
        .copy-button { margin-top:8px; }
    </style>
</head>
<body>
    <main class="activation-card card">
        <h2>Software Activation</h2>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif (!$status['valid']): ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars($status['message']); ?></div>
        <?php endif; ?>

        <?php if ($status['valid']): ?>
            <div class="alert alert-success">
                License active for <strong><?php echo htmlspecialchars($status['school_name']); ?></strong> through
                <strong><?php echo htmlspecialchars($status['expires']); ?></strong>.
            </div>
            <a class="btn btn-primary" href="<?php echo $isAdmin ? 'adminpanel.php' : 'adminlogin.php'; ?>">Continue</a>
        <?php else: ?>
            <p class="text-muted">Send this hardware ID to your software provider to receive a signed <code>license.lic</code> file.</p>
            <div class="form-group">
                <label for="hardware_id">Detected Motherboard HWID</label>
                <input id="hardware_id" class="form-control hardware-id" type="text" readonly value="<?php echo htmlspecialchars($hardwareId); ?>">
                <button class="btn btn-secondary copy-button" type="button" onclick="copyHardwareId(this)">Copy Hardware ID</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?php echo Csrf::field(); ?>
                <div class="form-group">
                    <label for="license_file">Signed license file</label>
                    <input id="license_file" class="form-control" type="file" name="license_file" accept=".lic,application/octet-stream" required>
                </div>
                <button class="btn btn-primary" type="submit">Activate Software</button>
            </form>
        <?php endif; ?>
    </main>
    <script>
        function copyHardwareId(button) {
            const value = document.getElementById('hardware_id').value;
            navigator.clipboard.writeText(value).then(() => { button.textContent = 'Copied'; });
        }
    </script>
</body>
</html>
