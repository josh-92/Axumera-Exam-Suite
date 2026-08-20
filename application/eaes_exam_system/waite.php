<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Repositories\ExamRepository;

if (!isset($_SESSION['full_name'], $_SESSION['student_id'])) {
    header("Location: slogin.php");
    exit();
}

if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    $exam = ExamRepository::liveExam();
    if ($exam) {
        $_SESSION['active_exam_id'] = (int) $exam['id'];
        echo json_encode(['status' => 'start']);
    } else {
        echo json_encode(['status' => 'waiting']);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Please Wait — <?php echo htmlspecialchars(config('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .wait-card { width: 100%; max-width: 600px; padding: 60px 40px; text-align: center; background: #ffffffc6; }
        .student-badge { background: #f1f5f9; border: 1px solid var(--color-border); padding: 12px 20px; border-radius: var(--radius-md); margin-bottom: 30px; text-align: left; font-size: 15px; line-height: 1.5; }
        .student-badge strong { color: #4f46e5; }
        .gif-container { width: 220px; height: 220px; margin: 0 auto 30px; }
        .gif-container img { width: 100%; height: 100%; object-fit: contain; }
        h2 { font-size: 30px; margin-bottom: 12px; }
        p { color: var(--color-muted); font-size: 17px; }
    </style>
</head>
<body>
    <div class="wait-card card">
        <div class="student-badge">
            Candidate: <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong><br>
            Stream: <span><?php echo htmlspecialchars($_SESSION['stream']); ?></span> |
            Section: <span><?php echo htmlspecialchars($_SESSION['section']); ?></span> |
            Roll No: <span><?php echo htmlspecialchars((string) $_SESSION['roll_number']); ?></span>
        </div>
        <div class="gif-container"><img src="assets/img/loading.svg" alt="Loading..."></div>
        <h2>Please Wait</h2>
        <p>The exam will begin automatically once your administrator starts it.</p>
    </div>

    <script>
        let pollTimer;
        let isRedirecting = false;
        function checkExamStatus() {
            if (isRedirecting) return;
            fetch('waite.php?check_status=1')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'start') {
                        isRedirecting = true;
                        clearInterval(pollTimer);
                        window.location.href = "examportal.php";
                    }
                })
                .catch(() => {});
        }
        pollTimer = setInterval(checkExamStatus, 2000);
    </script>
</body>
</html>
