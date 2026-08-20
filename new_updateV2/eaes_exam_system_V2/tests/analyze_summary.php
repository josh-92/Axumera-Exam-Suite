<?php
/** Pretty-print tests/out/<name>.summary.json as metrics tables. */
declare(strict_types=1);
$file = $argv[1] ?? 'tests/out/loadtest.summary.json';
if (!is_file($file)) { fwrite(STDERR, "no such file: $file\n"); exit(1); }
$d = json_decode((string) file_get_contents($file), true);
if (!$d) { fwrite(STDERR, "bad json\n"); exit(1); }

$o = $d['overall'];
printf("WALL: %ss | VU: %d | ramp: %ss | planned: %d | completed: %d\n", $d['wall_seconds'], $d['vus'], $d['ramp_seconds'], $d['planned_requests'], $d['completed_requests']);
printf("%-10s %6s %8s %8s %8s %8s %8s\n", 'metric', 'ok', 'fail', 'avg(ms)', 'med(ms)', 'p95(ms)', 'p99(ms)');
printf("%-10s %6d %8d %8.1f %8.1f %8.1f %8.1f  (max %dms, rps %.2f)\n", 'OVERALL', $o['success'], $o['failure'], $o['avg_ms'], $o['median_ms'], $o['p95_ms'], $o['p99_ms'], $o['max_ms'], $o['rps']);
echo str_repeat('-', 66) . "\n";
foreach ($d['by_step'] as $step => $s) {
    $codes = implode(',', array_map(fn ($c, $n) => "$c:$n", array_keys($s['status_codes']), array_values($s['status_codes'])));
    printf("%-10s %6d %8d %8.1f %8.1f %8.1f %8.1f  [%s]\n", $step, $s['success'], $s['failure'], $s['avg_ms'], $s['median_ms'], $s['p95_ms'], $s['max_ms'], $codes);
}
