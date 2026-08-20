<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Session;
use App\Repositories\AdminRepository;

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    header("Location: adminlogin.php");
    exit();
}

$message = "";
$status = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $message = "Your session expired. Please try again.";
        $status = "error";
    } else {
        $current_pass = (string) ($_POST['currentPassword'] ?? '');
        $new_pass = (string) ($_POST['newPassword'] ?? '');
        $confirm_pass = (string) ($_POST['confirmPassword'] ?? '');

        $admin = AdminRepository::findById((int) $_SESSION['admin_id']);

        if (!$admin) {
            $message = "Your admin account could not be found.";
            $status = "error";
        } elseif (!AdminRepository::verifyPassword($admin, $current_pass)) {
            $message = "Current password is incorrect!";
            $status = "error";
        } elseif ($new_pass !== $confirm_pass) {
            $message = "New passwords do not match!";
            $status = "error";
        } elseif (strlen($new_pass) < 8) {
            $message = "New password must be at least 8 characters long.";
            $status = "error";
        } else {
            AdminRepository::updatePassword((int) $admin['id'], $new_pass);
            Logger::audit('admin', $admin['username'], 'password_changed');
            Session::destroy();
            $message = "Password changed successfully! Please log in again using your new credentials.";
            $status = "success";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — <?php echo htmlspecialchars(config('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { height: 100vh; display: flex; justify-content: center; align-items: center; }
        .password-card { width: 100%; max-width: 500px; padding: 40px 50px; border-top: 5px solid var(--color-danger); text-align: center; }
        .card-title { font-size: 26px; font-weight: bold; margin-bottom: 10px; }
        .card-subtitle { font-size: 14px; color: var(--color-muted); margin-bottom: 30px; }
        .btn-block { width: 100%; margin-top: 8px; }
        .btn-cancel { display: inline-block; margin-top: 20px; font-size: 14px; color: var(--color-muted); text-decoration: none; }
        .error-msg { color: var(--color-danger); font-size: 13px; margin-top: 8px; display: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="password-card card">
        <h1 class="card-title">Change Password</h1>
        <p class="card-subtitle">Update your credentials for the exam management system</p>

        <?php if ($message): ?>
            <div class="alert <?php echo $status === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo ($status === 'success' ? '✅ ' : '⚠️ ') . htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($status === 'success'): ?>
            <a href="adminlogin.php" class="btn btn-success btn-block">Go Back to Login</a>
        <?php else: ?>
            <form id="passwordForm" action="changepassword.php" method="POST" onsubmit="return handleClientValidate()">
                <?php echo Csrf::field(); ?>
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
        <?php endif; ?>
    </div>

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
</body>
</html>
