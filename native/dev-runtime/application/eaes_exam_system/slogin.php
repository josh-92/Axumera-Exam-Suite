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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $roll = Validator::rollNumber($_POST['rollnumber'] ?? null);
        $stream = Validator::inArray($_POST['stream'] ?? '', ['Natural Science', 'Social Science']);
        $password = (string) ($_POST['password'] ?? '');

        if ($roll === null || $stream === null) {
            $error = 'Please choose a valid Roll Number (1-999) and Stream.';
        } else {
            $rollKey = (string) $roll;
            if (RateLimiter::ipLocked(Logger::clientIp())) {
                $error = 'Too many failed attempts from this network. Try again in a few minutes.';
            } elseif (StudentRepository::authLocked($rollKey)) {
                $mins = (int) ceil(StudentRepository::authLockSeconds($rollKey) / 60);
                $error = "Too many failed attempts for this roll number. Try again in about {$mins} minute(s).";
            } else {
                $student = StudentRepository::findByRollAndStream($rollKey, $stream);
                if (!$student) {
                    StudentRepository::recordAuthFailure($rollKey);
                    // Same wording as the wrong-password case on purpose: a
                    // distinct message here would let anyone probe which roll
                    // numbers are registered.
                    $error = 'The Roll Number, Stream, or password you entered is incorrect. If you don\'t have an account yet, ask your teacher to register you.';
                } elseif (!StudentRepository::hasPassword($student)) {
                    $info = 'This roll number has been seen before but has no password yet - ask your teacher to activate your account and give you a temporary password.';
                } elseif (StudentRepository::verifyPassword($student, $password)) {
                    StudentRepository::recordAuthSuccess($rollKey);
                    RateLimiter::clearIpFailures(Logger::clientIp());
                    StudentRepository::recordLogin((int) $student['id']);
                    Session::regenerate();
                    $_SESSION['student_id'] = (int) $student['id'];
                    $_SESSION['full_name'] = (string) $student['full_name'];
                    $_SESSION['roll_number'] = (int) $student['roll_number'];
                    $_SESSION['stream'] = (string) $student['stream'];
                    $_SESSION['section'] = (string) $student['section'];
                    unset($_SESSION['exam_submitted']);
                    Logger::audit('student', $rollKey, 'login', ['stream' => $stream]);
                    header('Location: waite.php');
                    exit;
                } else {
                    StudentRepository::recordAuthFailure($rollKey);
                    $error = 'The Roll Number, Stream, or password you entered is incorrect. Please try again, or use "Forgot password?".';
                }
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
    <title>Student Login - <?php echo htmlspecialchars(b_K5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .login-card { width: 100%; max-width: 450px; padding: 40px 30px; text-align: center; }
        .login-card img { max-height: 110px; margin-bottom: 10px; }
        h2 { color: var(--color-ink-soft); font-size: 22px; font-weight: 600; margin: 15px 0 8px; }
        .login-sub { color: var(--color-muted); font-size: 13px; margin: 0 0 25px; }
        input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        .submit-btn { width: 100%; margin-top: 8px; text-transform: uppercase; letter-spacing: .5px; }
        .auth-links { display: flex; justify-content: space-between; margin-top: 18px; font-size: 13px; }
        .auth-links a { color: var(--color-muted); text-decoration: none; }
        .auth-links a:hover { color: var(--color-primary); }
        .portal-switch { display: inline-block; margin-top: 22px; font-size: 13px; color: var(--color-muted); text-decoration: none; }
        .portal-switch:hover { color: var(--color-primary); }
    </style>
</head>
<body>
    <div class="page-shell">
    <main class="login-main">
    <div class="login-card card">
        <img src="assets/img/logo.png" alt="Logo" onerror="this.style.display='none';">
        <h2>Student Exam Portal Login</h2>
        <p class="login-sub">Enter your Roll Number and the password given to you by your teacher.</p>

        <?php if ($error !== '') { ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php } ?>
        <?php if ($info !== '') { ?>
            <div class="alert alert-info">ℹ️ <?php echo htmlspecialchars($info); ?></div>
        <?php } ?>

        <form method="POST" action="slogin.php" onsubmit="return validateForm()">
            <?php echo Csrf::field(); ?>
            <div class="form-group">
                <label for="rollnumber">Roll Number (ID)</label>
                <input type="number" id="rollnumber" name="rollnumber" placeholder="e.g. 105" min="1" max="999" required autofocus>
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
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Your password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary submit-btn">Log In</button>
        </form>

        <div class="auth-links">
            <a href="forgot.php">🔑 Forgot password?</a>
        </div>

        
    </div>
    </main>

    <script>
        function validateForm() {
            const value = parseFloat(document.getElementById('rollnumber').value);
            if (!Number.isInteger(value) || value < 1 || value > 999) {
                alert("Please enter a valid whole Roll Number between 1 and 999.");
                return false;
            }
            if (document.getElementById('password').value.length < 1) {
                alert("Please enter your password.");
                return false;
            }
            return true;
        }
    </script>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
    </div>
</body>
</html>
