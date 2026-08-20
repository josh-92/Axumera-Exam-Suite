<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Validator;
use App\Repositories\StudentRepository;

$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error_message = "Your session expired. Please try again.";
    } else {
        $fullname   = Validator::string($_POST['fullname'] ?? '', 100);
        $rollnumber = Validator::rollNumber($_POST['rollnumber'] ?? null);
        $stream     = Validator::inArray($_POST['stream'] ?? '', ['Natural Science', 'Social Science']);
        $section    = Validator::inArray($_POST['section'] ?? '', ['A', 'B', 'C']);

        if ($fullname === '' || $rollnumber === null || $stream === null || $section === null) {
            $error_message = "Please fill in all fields with valid values (Roll Number must be 1-999).";
        } else {
            try {
                $studentId = StudentRepository::upsert($fullname, (string) $rollnumber, $stream, $section);

                $_SESSION['student_id']  = $studentId;
                $_SESSION['full_name']   = $fullname;
                $_SESSION['roll_number'] = $rollnumber;
                $_SESSION['stream']      = $stream;
                $_SESSION['section']     = $section;
                unset($_SESSION['exam_submitted']);

                \App\Core\Logger::audit('student', (string) $rollnumber, 'login', ['stream' => $stream]);

                header("Location: waite.php");
                exit();
            } catch (\Throwable $e) {
                \App\Core\Logger::error('Student login failed: ' . $e->getMessage());
                $error_message = "A server error occurred while registering you. Please try again.";
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
    <title>Student Login — <?php echo htmlspecialchars(config('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .login-card { width: 100%; max-width: 450px; padding: 40px 30px; text-align: center; }
        .login-card img { max-height: 110px; margin-bottom: 10px; }
        h2 { color: var(--color-ink-soft); font-size: 22px; font-weight: 600; margin: 15px 0 25px; }
        input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        .submit-btn { width: 100%; margin-top: 8px; text-transform: uppercase; letter-spacing: .5px; }
        .portal-switch { display: inline-block; margin-top: 22px; font-size: 13px; color: var(--color-muted); text-decoration: none; }
        .portal-switch:hover { color: var(--color-primary); }
    </style>
</head>
<body>
    <div class="login-card card">
        <img src="assets/img/logo.png" alt="Logo" onerror="this.style.display='none';">
        <h2>Student Exam Portal Login</h2>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="slogin.php" onsubmit="return validateForm()">
            <?php echo Csrf::field(); ?>
            <div class="form-group">
                <label for="fullname">Full Name</label>
                <input type="text" id="fullname" name="fullname" placeholder="Enter your full name" required autocomplete="off" maxlength="100" value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="rollnumber">Roll Number</label>
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
                <label for="section">Class Section</label>
                <select id="section" name="section" required>
                    <option value="" disabled selected>Choose your section</option>
                    <option value="A">Section A</option>
                    <option value="B">Section B</option>
                    <option value="C">Section C</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary submit-btn">Log In</button>
        </form>

        <a href="adminlogin.php" class="portal-switch">Administrator Portal →</a>
    </div>

    <script>
        function validateForm() {
            const value = parseFloat(document.getElementById('rollnumber').value);
            if (!Number.isInteger(value) || value < 1 || value > 999) {
                alert("Please enter a valid whole Roll Number between 1 and 999.");
                return false;
            }
            return true;
        }
    </script>
</body>
</html>
