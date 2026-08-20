<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Repositories\AnalyticsRepository;
use App\Repositories\ExamRepository;

if (empty($_SESSION['admin_logged_in'])) {
    header("Location: adminlogin.php");
    exit();
}

$overview = AnalyticsRepository::overview();
$perExam = AnalyticsRepository::perExamSummary();

$selectedExamId = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : (($perExam[0]['id'] ?? null) ? (int) $perExam[0]['id'] : 0);
$distribution = $selectedExamId ? AnalyticsRepository::scoreDistribution($selectedExamId) : array_fill(0, 10, 0);
$selectedExam = $selectedExamId ? ExamRepository::find($selectedExamId) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — <?php echo htmlspecialchars(config('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <?php include __DIR__ . '/partials/admin_header.php'; ?>

    <main class="dashboard-content">
        <h2>Analytics Overview</h2>
        <div class="stat-grid">
            <div class="stat-card"><div class="value"><?php echo $overview['total_exams']; ?></div><div class="label">Exam Profiles</div></div>
            <div class="stat-card"><div class="value"><?php echo $overview['total_students']; ?></div><div class="label">Registered Students</div></div>
            <div class="stat-card"><div class="value"><?php echo $overview['total_attempts']; ?></div><div class="label">Total Attempts</div></div>
            <div class="stat-card"><div class="value"><?php echo $overview['completion_rate']; ?>%</div><div class="label">Completion Rate</div></div>
            <div class="stat-card"><div class="value"><?php echo $overview['avg_score_pct'] !== null ? $overview['avg_score_pct'] . '%' : '—'; ?></div><div class="label">Average Score</div></div>
        </div>

        <div class="section-title">Exam Performance Summary</div>
        <table class="exam-table">
            <thead>
                <tr><th>Exam</th><th>Stream</th><th>Status</th><th>Attempts</th><th>Submitted</th><th>Avg %</th><th>Min %</th><th>Max %</th></tr>
            </thead>
            <tbody>
                <?php foreach ($perExam as $row): ?>
                    <tr>
                        <td><a href="analytics.php?exam_id=<?php echo (int) $row['id']; ?>"><?php echo htmlspecialchars($row['exam_name']); ?></a></td>
                        <td><?php echo htmlspecialchars($row['stream']); ?></td>
                        <td><?php echo $row['is_live'] ? '<span class="badge badge-live">LIVE</span>' : '<span class="badge badge-inactive">Inactive</span>'; ?></td>
                        <td><?php echo (int) $row['attempts']; ?></td>
                        <td><?php echo (int) $row['submitted']; ?></td>
                        <td><?php echo $row['avg_pct'] !== null ? round($row['avg_pct'], 1) . '%' : '—'; ?></td>
                        <td><?php echo $row['min_pct'] !== null ? round($row['min_pct'], 1) . '%' : '—'; ?></td>
                        <td><?php echo $row['max_pct'] !== null ? round($row['max_pct'], 1) . '%' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$perExam): ?><tr><td colspan="8">No exams created yet.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php if ($selectedExam): ?>
            <div class="section-title">Score Distribution — <?php echo htmlspecialchars($selectedExam['exam_name']); ?></div>
            <div class="card" style="padding: 24px; max-width: 800px;">
                <canvas id="distributionChart" height="90"></canvas>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($selectedExam): ?>
    <script>
        const ctx = document.getElementById('distributionChart');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(fn($i) => ($i * 10) . '-' . ($i * 10 + 9) . '%', range(0, 9))); ?>,
                datasets: [{
                    label: 'Students',
                    data: <?php echo json_encode($distribution); ?>,
                    backgroundColor: '#2563eb'
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
