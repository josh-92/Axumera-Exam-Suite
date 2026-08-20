<?php
require_once __DIR__ . '/app/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - <?php echo htmlspecialchars(b_K5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .login-card { width: 100%; max-width: 450px; padding: 40px 30px; text-align: center; }
        .login-card img { max-height: 110px; margin-bottom: 10px; }
        h2 { color: var(--color-ink-soft); font-size: 22px; font-weight: 600; margin: 15px 0 8px; }
        .login-sub { color: var(--color-muted); font-size: 13px; margin: 0 0 25px; line-height: 1.6; }
        .back-link { display: inline-block; margin-top: 20px; font-size: 13px; color: var(--color-muted); text-decoration: none; }
        .back-link:hover { color: var(--color-primary); }
    </style>
</head>
<body>
    <div class="login-card card">
        <img src="assets/img/logo.png" alt="Logo" onerror="this.style.display='none';">
        <h2>Student Registration</h2>
        <p class="login-sub">
            Student accounts are created and managed by your teacher or the school administrator.
            If you do not have login credentials yet, please ask your teacher to register you.
            They will give you a temporary password to log in with.
        </p>
        <a href="slogin.php" class="btn btn-primary">← Back to Login</a>
    </div>
</body>
</html>
