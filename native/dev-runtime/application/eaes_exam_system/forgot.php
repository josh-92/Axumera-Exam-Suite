<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\StudentRepository;

$error = '';
$info = '';

// Guard: the identity step sets a verified flag in the session; only then
// is the new-password form made available. A locked session (too many
// failed verification attempts) blocks further tries.
$verifiedId = isset($_SESSION['pwd_reset_verified_id']) ? (int) $_SESSION['pwd_reset_verified_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'verify';

        if ($action === 'verify' && $verifiedId === 0) {
            $roll = Validator::rollNumber($_POST['rollnumber'] ?? null);
            $stream = Validator::inArray($_POST['stream'] ?? '', ['Natural Science', 'Social Science']);
            $fullName = Validator::string($_POST['fullname'] ?? '', 100);
            $section = Validator::inArray($_POST['section'] ?? '', ['A', 'B', 'C']);

            if ($roll === null || $stream === null || $fullName === '' || $section === null) {
                $error = 'Please fill in all fields with valid values.';
            } else {
                // DB-backed lockout (survives cookie clears / new sessions) and
                // per-IP throttling, shared with the login endpoints. The
                // session still guards the post-verification window below.
                $resetKey = 'pwdreset:' . (string) $roll;
                if (RateLimiter::accountLocked($resetKey) || RateLimiter::ipLocked(Logger::clientIp())) {
                    $error = 'Too many failed attempts. The reset form is temporarily locked — or ask your teacher to reset your password from the admin panel.';
                } else {
                    $student = StudentRepository::verifyIdentity((string) $roll, $stream, $fullName, $section);
                    if ($student) {
                        // The verified flag is short-lived so a browser left open
                        // on the reset form cannot be used indefinitely.
                        $_SESSION['pwd_reset_verified_id'] = (int) $student['id'];
                        $_SESSION['pwd_reset_verified_name'] = (string) $student['full_name'];
                        $_SESSION['pwd_reset_verified_at'] = time();
                        unset($_SESSION['pwd_reset_attempts'], $_SESSION['pwd_reset_locked_until']);
                        RateLimiter::clearAccountFailures($resetKey);
                        RateLimiter::clearIpFailures(Logger::clientIp());
                        $verifiedId = (int) $student['id'];
                        Logger::audit('student', (string) $roll, 'password_reset_verify', ['stream' => $stream, 'section' => $section]);
                        $info = 'Identity verified. Choose a new password below.';
                    } else {
                        RateLimiter::recordAccountFailure($resetKey);
                        $error = 'The details you entered do not match a registered account. Double-check them and try again.';
                    }
                }
            }
        } elseif ($action === 'reset' && $verifiedId > 0) {
            // Reject a stale verification (identity was confirmed more than
            // 15 minutes ago) — forces the student to verify again.
            $verifiedAt = (int) ($_SESSION['pwd_reset_verified_at'] ?? 0);
            if ($verifiedAt === 0 || time() - $verifiedAt > 900) {
                unset($_SESSION['pwd_reset_verified_id'], $_SESSION['pwd_reset_verified_name'], $_SESSION['pwd_reset_verified_at']);
                $error = 'Your identity verification expired. Please verify your details again.';
            } else {
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? '');
            if (mb_strlen($password) < 6) {
                $error = 'Password must be at least 6 characters long.';
            } elseif (mb_strlen($password) > 72) {
                $error = 'Password is too long (max 72 characters).';
            } elseif ($password !== $confirm) {
                $error = 'The two passwords do not match.';
            } else {
                StudentRepository::setPassword($verifiedId, $password);
                $name = (string) ($_SESSION['pwd_reset_verified_name'] ?? '');
                Logger::audit('student', (string) $verifiedId, 'password_reset_complete', []);
                unset($_SESSION['pwd_reset_verified_id'], $_SESSION['pwd_reset_verified_name'], $_SESSION['pwd_reset_verified_at']);
                $done = true;
            }
            }
        } else {
            $error = 'Invalid request. Please start again.';
        }
    }
}

$done = $done ?? false;
$verifiedId = isset($_SESSION['pwd_reset_verified_id']) ? (int) $_SESSION['pwd_reset_verified_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo htmlspecialchars(b_K5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .login-card { width: 100%; max-width: 440px; padding: 40px 30px; text-align: center; }
        .login-card img { max-height: 100px; margin-bottom: 10px; }
        h2 { color: var(--color-ink-soft); font-size: 22px; font-weight: 600; margin: 15px 0 8px; }
        .login-sub { color: var(--color-muted); font-size: 13px; margin: 0 0 22px; line-height: 1.5; }
        input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        .submit-btn { width: 100%; margin-top: 8px; text-transform: uppercase; letter-spacing: .5px; }
        .pw-hint { text-align: left; font-size: 12px; color: var(--color-muted); margin: 4px 0 0; }
        .back-link { display: inline-block; margin-top: 20px; font-size: 13px; color: var(--color-muted); text-decoration: none; }
        .back-link:hover { color: var(--color-primary); }
    </style>
</head>
<body>
    <div class="login-card card">
        <img src="assets/img/logo.png" alt="Logo" onerror="this.style.display='none';">

        <?php if (!empty($done)) { ?>
            <h2>Password Updated ✅</h2>
            <p class="login-sub">Your password has been changed successfully. Log in with the new password to continue.</p>
            <a href="slogin.php" class="btn btn-primary submit-btn" style="text-decoration:none;">Go to Login</a>
        <?php } elseif ($verifiedId > 0) { ?>
            <h2>Choose a New Password</h2>
            <p class="login-sub">Hello <strong><?php echo htmlspecialchars((string) ($_SESSION['pwd_reset_verified_name'] ?? '')); ?></strong> — set the password you will use to log in.</p>

            <?php if ($error !== '') { ?>
                <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php } ?>
            <?php if ($info !== '') { ?>
                <div class="alert alert-info">✅ <?php echo htmlspecialchars($info); ?></div>
            <?php } ?>

            <form method="POST" action="forgot.php">
                <?php echo Csrf::field(); ?>
                <input type="hidden" name="action" value="reset">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" placeholder="At least 6 characters" required autocomplete="new-password" minlength="6" maxlength="72">
                    <p class="pw-hint">At least 6 characters. Do not reuse an old password.</p>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm New Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" placeholder="Type it again" required autocomplete="new-password" minlength="6" maxlength="72">
                </div>
                <button type="submit" class="btn btn-primary submit-btn">Save New Password</button>
            </form>
        <?php } else { ?>
            <h2>Forgot Your Password?</h2>
            <p class="login-sub">
                Verify your identity with the details you registered with. If your
                details do not match, your teacher can reset your password from the
                admin panel.
            </p>

            <?php if ($error !== '') { ?>
                <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php } ?>

            <form method="POST" action="forgot.php">
                <?php echo Csrf::field(); ?>
                <input type="hidden" name="action" value="verify">
                <div class="form-group">
                    <label for="fullname">Full Name</label>
                    <input type="text" id="fullname" name="fullname" placeholder="Exactly as registered" required autocomplete="off" maxlength="100">
                </div>
                <div class="form-group">
                    <label for="rollnumber">Roll Number (ID)</label>
                    <input type="number" id="rollnumber" name="rollnumber" placeholder="e.g. 105" min="1" max="999" required>
                </div>
                <div class="form-group">
                    <label for="stream">Stream</label>
                    <select id="stream" name="stream" required>
                        <option value="" disabled selected>Choose your stream</option>
                        <option value="Natural Science">Natural Science</option>
                        <option value="Social Science">Social Science</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="section">Section</label>
                    <select id="section" name="section" required>
                        <option value="" disabled selected>Choose your section</option>
                        <option value="A">Section A</option>
                        <option value="B">Section B</option>
                        <option value="C">Section C</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary submit-btn">Verify My Identity</button>
            </form>
        <?php } ?>

        <a href="slogin.php" class="back-link">← Back to login</a>
    </div>

    <script>
        <?php if ($verifiedId > 0 && empty($done)) { ?>
        document.querySelector('form').addEventListener('submit', function (e) {
            const pw = document.getElementById('password').value;
            if (pw.length < 6) {
                e.preventDefault();
                alert("Password must be at least 6 characters long.");
                return;
            }
            if (pw !== document.getElementById('password_confirm').value) {
                e.preventDefault();
                alert("The two passwords do not match.");
            }
        });
        <?php } else { ?>
        const rollInput = document.getElementById('rollnumber');
        if (rollInput) {
            document.querySelector('form').addEventListener('submit', function (e) {
                const value = parseFloat(rollInput.value);
                if (!Number.isInteger(value) || value < 1 || value > 999) {
                    e.preventDefault();
                    alert("Please enter a valid whole Roll Number between 1 and 999.");
                }
            });
        }
        <?php } ?>
    </script>
</body>
</html>
