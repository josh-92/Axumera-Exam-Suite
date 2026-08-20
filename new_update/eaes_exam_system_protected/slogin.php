<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:06              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Core\Csrf; use App\Core\Validator; use App\Repositories\StudentRepository; $bNlc8 = ""; if (!($_SERVER['REQUEST_METHOD'] === 'POST')) { goto FlzzQ; } if (!Csrf::verify($_POST['csrf_token'] ?? null)) { goto iriIJ; } $GPGxK = Validator::string($_POST['fullname'] ?? '', 100); $pzrOQ = Validator::rollNumber($_POST['rollnumber'] ?? null); $MKh3C = Validator::inArray($_POST['stream'] ?? '', ['Natural Science', 'Social Science']); $oOnaN = Validator::inArray($_POST['section'] ?? '', ['A', 'B', 'C']); if ($GPGxK === '' || $pzrOQ === null || $MKh3C === null || $oOnaN === null) { goto uqycc; } try { $ii9hU = StudentRepository::upsert($GPGxK, (string) $pzrOQ, $MKh3C, $oOnaN); $_SESSION['student_id'] = $ii9hU; $_SESSION['full_name'] = $GPGxK; $_SESSION['roll_number'] = $pzrOQ; $_SESSION['stream'] = $MKh3C; $_SESSION['section'] = $oOnaN; unset($_SESSION['exam_submitted']); \App\Core\Logger::audit('student', (string) $pzrOQ, 'login', ['stream' => $MKh3C]); header("Location: waite.php"); exit; } catch (\Throwable $NacY1) { \App\Core\Logger::error('Student login failed: ' . $NacY1->getMessage()); $bNlc8 = "A server error occurred while registering you. Please try again."; } goto tJ7kv; uqycc: $bNlc8 = "Please fill in all fields with valid values (Roll Number must be 1-999)."; tJ7kv: goto by3hW; iriIJ: $bNlc8 = "Your session expired. Please try again."; by3hW: FlzzQ: ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login — <?php  echo htmlspecialchars(b_K5t('app.name')); ?></title>
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

        <?php  if (empty($bNlc8)) { goto b9NoX; } ?>
            <div class="alert alert-error">⚠️ <?php  echo htmlspecialchars($bNlc8); ?></div>
        <?php  b9NoX: ?>

        <form method="POST" action="slogin.php" onsubmit="return validateForm()">
            <?php  echo Csrf::field(); ?>
            <div class="form-group">
                <label for="fullname">Full Name</label>
                <input type="text" id="fullname" name="fullname" placeholder="Enter your full name" required autocomplete="off" maxlength="100" value="<?php  echo htmlspecialchars($_POST['fullname'] ?? ''); ?>">
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
        <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
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
