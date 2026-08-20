<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:05              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\Logger; use App\Core\Session; use App\Repositories\AdminRepository; if (!(empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id']))) { goto DQb_X; } header("Location: adminlogin.php"); exit; DQb_X: $tvwac = ""; $BhSDG = ""; if (!($_SERVER['REQUEST_METHOD'] === 'POST')) { goto g6jA3; } if (!Csrf::verify($_POST['csrf_token'] ?? null)) { goto clcbr; } $HxZHN = (string) ($_POST['currentPassword'] ?? ''); $Dotex = (string) ($_POST['newPassword'] ?? ''); $MCJn0 = (string) ($_POST['confirmPassword'] ?? ''); $a1heL = AdminRepository::findById((int) $_SESSION['admin_id']); if (!$a1heL) { goto uv9H2; } if (!AdminRepository::verifyPassword($a1heL, $HxZHN)) { goto gxYyN; } if ($Dotex !== $MCJn0) { goto UqVCJ; } if (strlen($Dotex) < 8) { goto h5uiH; } AdminRepository::updatePassword((int) $a1heL['id'], $Dotex); Logger::audit('admin', $a1heL['username'], 'password_changed'); Session::destroy(); $tvwac = "Password changed successfully! Please log in again using your new credentials."; $BhSDG = "success"; goto W9n2s; uv9H2: $tvwac = "Your admin account could not be found."; $BhSDG = "error"; goto W9n2s; gxYyN: $tvwac = "Current password is incorrect!"; $BhSDG = "error"; goto W9n2s; UqVCJ: $tvwac = "New passwords do not match!"; $BhSDG = "error"; goto W9n2s; h5uiH: $tvwac = "New password must be at least 8 characters long."; $BhSDG = "error"; W9n2s: goto bg4uW; clcbr: $tvwac = "Your session expired. Please try again."; $BhSDG = "error"; bg4uW: g6jA3: ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — <?php  echo htmlspecialchars(b_k5T('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Page layout handled by the .page-shell sticky-footer shell. */
        .password-card { width: 100%; max-width: 500px; padding: 40px 50px; border-top: 5px solid var(--color-danger); text-align: center; }
        .card-title { font-size: 26px; font-weight: bold; margin-bottom: 10px; }
        .card-subtitle { font-size: 14px; color: var(--color-muted); margin-bottom: 30px; }
        .btn-block { width: 100%; margin-top: 8px; }
        .btn-cancel { display: inline-block; margin-top: 20px; font-size: 14px; color: var(--color-muted); text-decoration: none; }
        .error-msg { color: var(--color-danger); font-size: 13px; margin-top: 8px; display: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="page-shell">
    <main class="login-main">
    <div class="password-card card">
        <h1 class="card-title">Change Password</h1>
        <p class="card-subtitle">Update your credentials for the exam management system</p>

        <?php  if (!$tvwac) { goto NU4_e; } ?>
            <div class="alert <?php  echo $BhSDG === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php  echo ($BhSDG === 'success' ? '✅ ' : '⚠️ ') . htmlspecialchars($tvwac); ?>
            </div>
        <?php  NU4_e: ?>

        <?php  if ($BhSDG === 'success') { goto wIRSq; } ?>
            <form id="passwordForm" action="changepassword.php" method="POST" onsubmit="return handleClientValidate()">
                <?php  echo Csrf::field(); ?>
                <div class="form-group">
                    <label for="currentPassword">Current Password</label>
                    <input type="password" name="currentPassword" id="currentPassword" placeholder="Enter current password" required>
                </div>
                <div class="form-group">
                    <label for="newPassword">New Password</label>
                    <input type="password" name="newPassword" id="newPassword" placeholder="At least 8 characters" required minlength="8" oninput="validatePasswords()">
                </div>
                <div class="form-group">
                    <label for="confirmPassword">Confirm New Password</label>
                    <input type="password" name="confirmPassword" id="confirmPassword" placeholder="Repeat your new password" required oninput="validatePasswords()">
                    <div class="error-msg" id="matchError">⚠️ New passwords do not match!</div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Update Password</button>
            </form>
            <a href="adminpanel.php" class="btn-cancel">Return to Dashboard</a>
        <?php  goto Lo4c_; wIRSq: ?>
            <a href="adminlogin.php" class="btn btn-success btn-block">Go Back to Login</a>
        <?php  Lo4c_: ?>
    </div>
    </main>

    <script>
        function validatePasswords() {
            const newPass = document.getElementById("newPassword").value;
            const confirmPass = document.getElementById("confirmPassword").value;
            const errorBox = document.getElementById("matchError");
            const mismatch = confirmPass.length > 0 && newPass !== confirmPass;
            errorBox.style.display = mismatch ? "block" : "none";
            return !mismatch;
        }
        function handleClientValidate() {
            if (!validatePasswords()) {
                alert("Cannot proceed. Please make sure your new passwords match exactly!");
                return false;
            }
            return true;
        }
    </script>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
    </div>
</body>
</html>
