<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 require_once __DIR__ . '/app/bootstrap.php'; use App\Repositories\AnalyticsRepository; use App\Repositories\ExamRepository; if (!empty($_SESSION['admin_logged_in'])) { goto U31_F; } header("Location: adminlogin.php"); exit; U31_F: $e1vbn = AnalyticsRepository::overview(); $Cbx2t = AnalyticsRepository::perExamSummary(); $lYj7O = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : ($Cbx2t[0]['id'] ?? null ? (int) $Cbx2t[0]['id'] : 0); $E7ZwL = $lYj7O ? AnalyticsRepository::scoreDistribution($lYj7O) : array_fill(0, 10, 0); $U8fWe = $lYj7O ? ExamRepository::find($lYj7O) : null; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — <?php  echo htmlspecialchars(b_K5t('app.name')); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <?php  include __DIR__ . '/partials/admin_header.php'; ?>

    <main class="dashboard-content">
        <h2>Analytics Overview</h2>
        <div class="stat-grid">
            <div class="stat-card"><div class="value"><?php  echo $e1vbn['total_exams']; ?></div><div class="label">Exam Profiles</div></div>
            <div class="stat-card"><div class="value"><?php  echo $e1vbn['total_students']; ?></div><div class="label">Registered Students</div></div>
            <div class="stat-card"><div class="value"><?php  echo $e1vbn['total_attempts']; ?></div><div class="label">Total Attempts</div></div>
            <div class="stat-card"><div class="value"><?php  echo $e1vbn['completion_rate']; ?>%</div><div class="label">Completion Rate</div></div>
            <div class="stat-card"><div class="value"><?php  echo $e1vbn['avg_score_pct'] !== null ? $e1vbn['avg_score_pct'] . '%' : '—'; ?></div><div class="label">Average Score</div></div>
        </div>

        <div class="section-title">Exam Performance Summary</div>
        <table class="exam-table">
            <thead>
                <tr><th>Exam</th><th>Stream</th><th>Status</th><th>Attempts</th><th>Submitted</th><th>Avg %</th><th>Min %</th><th>Max %</th></tr>
            </thead>
            <tbody>
                <?php  foreach ($Cbx2t as $RmthD) { ?>
                    <tr>
                        <td><a href="analytics.php?exam_id=<?php  echo (int) $RmthD['id']; ?>"><?php  echo htmlspecialchars($RmthD['exam_name']); ?></a></td>
                        <td><?php  echo htmlspecialchars($RmthD['stream']); ?></td>
                        <td><?php  echo $RmthD['is_live'] ? '<span class="badge badge-live">LIVE</span>' : '<span class="badge badge-inactive">Inactive</span>'; ?></td>
                        <td><?php  echo (int) $RmthD['attempts']; ?></td>
                        <td><?php  echo (int) $RmthD['submitted']; ?></td>
                        <td><?php  echo $RmthD['avg_pct'] !== null ? round($RmthD['avg_pct'], 1) . '%' : '—'; ?></td>
                        <td><?php  echo $RmthD['min_pct'] !== null ? round($RmthD['min_pct'], 1) . '%' : '—'; ?></td>
                        <td><?php  echo $RmthD['max_pct'] !== null ? round($RmthD['max_pct'], 1) . '%' : '—'; ?></td>
                    </tr>
                <?php  CkiaY: } pZpFK: ?>
                <?php  if ($Cbx2t) { goto Tq3Hj; } ?><tr><td colspan="8">No exams created yet.</td></tr><?php  Tq3Hj: ?>
            </tbody>
        </table>

        <?php  if (!$U8fWe) { goto PXAII; } ?>
            <div class="section-title">Score Distribution — <?php  echo htmlspecialchars($U8fWe['exam_name']); ?></div>
            <div class="card" style="padding: 24px; max-width: 800px;">
                <canvas id="distributionChart" height="90"></canvas>
            </div>
        <?php  PXAII: ?>
    </main>

    <?php  if (!$U8fWe) { goto upclD; } ?>
    <script>
        const ctx = document.getElementById('distributionChart');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php  echo json_encode(array_map(fn($O3T6G) => $O3T6G * 10 . '-' . ($O3T6G * 10 + 9) . '%', range(0, 9))); ?>,
                datasets: [{
                    label: 'Students',
                    data: <?php  echo json_encode($E7ZwL); ?>,
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
    <?php  upclD: ?>
    <?php include __DIR__ . '/partials/copyright_footer.php'; ?>
</body>
</html>
