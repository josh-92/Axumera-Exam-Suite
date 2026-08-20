<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Repositories\AdminRepository;

if (!empty($_SESSION['admin_logged_in'])) {
    header("Location: adminpanel.php");
    exit();
}

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error_message = "Your session expired. Please try again.";
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error_message = "Please enter both a username and password.";
        } elseif (RateLimiter::isLocked($username)) {
            $mins = (int) ceil(RateLimiter::secondsUntilUnlock($username) / 60);
            $error_message = "This account is temporarily locked due to repeated failed attempts. Try again in {$mins} minute(s).";
        } else {
            $admin_data = AdminRepository::findByUsername($username);

            if ($admin_data && AdminRepository::verifyPassword($admin_data, $password)) {
                RateLimiter::recordSuccess($username);
                Session::regenerate();
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = (int) $admin_data['id'];
                $_SESSION['admin_username'] = $admin_data['username'];
                Logger::audit('admin', $username, 'login_success');

                header("Location: adminpanel.php");
                exit();
            } else {
                if ($admin_data) {
                    RateLimiter::recordFailure($username);
                }
                Logger::audit('admin', $username, 'login_failed');
                $error_message = "Invalid username or password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login — <?php echo htmlspecialchars(config('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: var(--color-ink); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .login-card { width: 100%; max-width: 420px; padding: 45px 35px; text-align: center; }
        .admin-badge { display: inline-block; background: var(--color-primary-light); color: var(--color-primary); font-size: 12px; font-weight: 700; text-transform: uppercase; padding: 6px 16px; border-radius: 20px; margin-bottom: 15px; letter-spacing: .5px; }
        h2 { color: var(--color-ink-soft); font-size: 24px; font-weight: 600; margin-bottom: 30px; }
        .submit-btn { width: 100%; text-transform: uppercase; letter-spacing: .5px; margin-top: 10px; }
        .portal-switch { display: inline-block; margin-top: 25px; font-size: 14px; color: var(--color-muted); text-decoration: none; }
        .portal-switch:hover { color: var(--color-primary); }
    </style>
</head>
<body>
    <div class="login-card card">
        <span class="admin-badge">Management Portal</span>
        <h2>Exam Coordinator Login</h2>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="adminlogin.php" method="POST">
            <?php echo Csrf::field(); ?>
            <div class="form-group">
                <label for="username">Admin Username</label>
                <input type="text" id="username" name="username" placeholder="Enter administrative user ID" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="password">Security Password</label>
                <input type="password" id="password" name="password" placeholder="Enter portal security key" required>
            </div>
            <button type="submit" class="btn btn-primary submit-btn">Authorize Entry</button>
        </form>

        <a href="slogin.php" class="portal-switch">← Return to Student Examination Gate</a>
    </div>
</body>
</html>
