<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\Logger; use App\Core\RateLimiter; use App\Core\Session; use App\Repositories\AdminRepository; if (empty($_SESSION['admin_logged_in'])) { goto h69Cz; } header("Location: adminpanel.php"); exit; h69Cz: $bNlc8 = ""; if (!($_SERVER["REQUEST_METHOD"] == "POST")) { goto K7hAX; } if (!Csrf::verify($_POST['csrf_token'] ?? null)) { goto Zwz8K; } $EuShz = trim((string) ($_POST['username'] ?? '')); $n01rw = (string) ($_POST['password'] ?? ''); if ($EuShz === '' || $n01rw === '') { goto kSnCo; } if (RateLimiter::isLocked($EuShz)) { goto NVo3v; } if (RateLimiter::ipLocked(Logger::clientIp())) { goto Sj8pL; } $cuaBl = AdminRepository::findByUsername($EuShz); if ($cuaBl && AdminRepository::verifyPassword($cuaBl, $n01rw)) { goto iF7wZ; } if (!$cuaBl) { goto g_XoG; } RateLimiter::recordFailure($EuShz); g_XoG: Logger::audit('admin', $EuShz, 'login_failed'); $bNlc8 = "Invalid username or password."; goto tzd5x; iF7wZ: RateLimiter::recordSuccess($EuShz); RateLimiter::clearIpFailures(Logger::clientIp()); Session::regenerate(); $_SESSION['admin_logged_in'] = true; $_SESSION['admin_id'] = (int) $cuaBl['id']; $_SESSION['admin_username'] = $cuaBl['username']; Logger::audit('admin', $EuShz, 'login_success'); header("Location: adminpanel.php"); exit; tzd5x: goto QfmgZ; kSnCo: $bNlc8 = "Please enter both a username and password."; goto QfmgZ; NVo3v: $BL981 = (int) ceil(RateLimiter::secondsUntilUnlock($EuShz) / 60); $bNlc8 = "This account is temporarily locked due to repeated failed attempts. Try again in {$BL981} minute(s)."; QfmgZ: goto yxEw5; Sj8pL: $bNlc8 = "Too many failed attempts from this network. Try again in a few minutes."; goto yxEw5; Zwz8K: $bNlc8 = "Your session expired. Please try again."; yxEw5: K7hAX: ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login — <?php  echo htmlspecialchars(b_k5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: var(--color-bg); }
        body::before { content: ""; position: fixed; top: 0; left: 0; right: 0; height: 4px; background: #d3a029; z-index: 2; }
        .login-card { width: 100%; max-width: 400px; padding: 44px 38px 40px; text-align: center; border-radius: 16px; border: 1px solid #e5eaf0; box-shadow: 0 12px 40px rgba(12, 32, 54, 0.08); }
        .login-logo { width: 168px; height: auto; margin: 0 auto 22px; display: block; }
        .admin-badge { display: inline-block; background: #f1f5f9; color: #334155; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 6px 16px; border-radius: 999px; margin-bottom: 14px; }
        h2 { color: var(--color-ink-soft); font-size: 22px; font-weight: 600; margin: 0 0 28px; }
        .login-card input:focus { border-color: #0c2036; box-shadow: 0 0 0 3px rgba(12, 32, 54, 0.10); }
        .login-card .submit-btn.btn-primary { width: 100%; text-transform: uppercase; letter-spacing: .5px; margin-top: 10px; background: #0c2036; }
        .login-card .submit-btn.btn-primary:hover { background: #16324f; }
        .portal-switch { display: inline-block; margin-top: 24px; font-size: 14px; color: var(--color-muted); text-decoration: none; }
        .portal-switch:hover { color: var(--color-primary); }
        @media (max-width: 480px) {
            .login-card { padding: 32px 24px; }
        }
    </style>
</head>
<body>
    <div class="page-shell">
    <main class="login-main">
    <div class="login-card card">
        <img src="assets/img/logo.png" alt="Axumera" class="login-logo">
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

        
    </div>
    </main>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
    </div>
</body>
</html>
