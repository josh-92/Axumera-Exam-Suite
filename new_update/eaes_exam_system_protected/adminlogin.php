<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\Logger; use App\Core\RateLimiter; use App\Core\Session; use App\Repositories\AdminRepository; if (empty($_SESSION['admin_logged_in'])) { goto h69Cz; } header("Location: adminpanel.php"); exit; h69Cz: $bNlc8 = ""; if (!($_SERVER["REQUEST_METHOD"] == "POST")) { goto K7hAX; } if (!Csrf::verify($_POST['csrf_token'] ?? null)) { goto Zwz8K; } $EuShz = trim((string) ($_POST['username'] ?? '')); $n01rw = (string) ($_POST['password'] ?? ''); if ($EuShz === '' || $n01rw === '') { goto kSnCo; } if (RateLimiter::isLocked($EuShz)) { goto NVo3v; } $cuaBl = AdminRepository::findByUsername($EuShz); if ($cuaBl && AdminRepository::verifyPassword($cuaBl, $n01rw)) { goto iF7wZ; } if (!$cuaBl) { goto g_XoG; } RateLimiter::recordFailure($EuShz); g_XoG: Logger::audit('admin', $EuShz, 'login_failed'); $bNlc8 = "Invalid username or password."; goto tzd5x; iF7wZ: RateLimiter::recordSuccess($EuShz); Session::regenerate(); $_SESSION['admin_logged_in'] = true; $_SESSION['admin_id'] = (int) $cuaBl['id']; $_SESSION['admin_username'] = $cuaBl['username']; Logger::audit('admin', $EuShz, 'login_success'); header("Location: adminpanel.php"); exit; tzd5x: goto QfmgZ; kSnCo: $bNlc8 = "Please enter both a username and password."; goto QfmgZ; NVo3v: $BL981 = (int) ceil(RateLimiter::secondsUntilUnlock($EuShz) / 60); $bNlc8 = "This account is temporarily locked due to repeated failed attempts. Try again in {$BL981} minute(s)."; QfmgZ: goto yxEw5; Zwz8K: $bNlc8 = "Your session expired. Please try again."; yxEw5: K7hAX: ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login — <?php  echo htmlspecialchars(b_k5t('app.name')); ?></title>
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

        <?php  if (empty($bNlc8)) { goto mihbb; } ?>
            <div class="alert alert-error">⚠️ <?php  echo htmlspecialchars($bNlc8); ?></div>
        <?php  mihbb: ?>

        <form action="adminlogin.php" method="POST">
            <?php  echo Csrf::field(); ?>
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
        <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
    </div>
</body>
</html>
