<?php
/**
 * Concurrent load test for the EAES exam system.
 * -----------------------------------------------
 * Simulates N virtual users doing a realistic student journey:
 *   1. GET  /slogin.php               (login page, obtains CSRF + session cookie)
 *   2. POST /slogin.php               (student login → upsert + session)
 *   3. GET  /waite.php?check_status=1 (JSON poll — live exam?)
 *   4. GET  /examportal.php            (fetch exam questions — heavy read)
 *   5. POST /autosave.php             (submit partial answers — write)
 *   6. POST /submit_exam.php          (final submit — write + grading)
 *
 * Uses curl_multi for true concurrency, a per-user session cookie, and
 * correct CSRF tokens. Emits a JSON summary plus a CSV line per request.
 *
 * Usage:
 *   php tests/load_test.php <vus> <ramp_seconds> <exam_id> <out_prefix>
 *
 * Example:
 *   php tests/load_test.php 200 30 3 results  → results.summary.json + results.requests.csv
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$VUS        = (int) ($argv[1] ?? 200);
$RAMP       = (float) ($argv[2] ?? 30);   // seconds over which users come online
$EXAM_ID    = (int) ($argv[3] ?? 0);
$PREFIX     = (string) ($argv[4] ?? 'loadtest');
$BASE       = 'http://localhost/eaes_exam_system_protected';
$STEPS      = ['login_page', 'login_post', 'wait_poll', 'fetch_exam', 'autosave', 'submit'];
$RESULTS    = [];   // per-request: [step, status, ms, curl_errno, curl_error]
$STARTED    = microtime(true);

function now(): float { return microtime(true); }

// ---------------------------------------------------------------------------
// Per-VU state machine: each user walks STEPS with its own cookie jar + CSRF.
// ---------------------------------------------------------------------------

function makeUser(int $i): array
{
    return [
        'id' => $i,
        'step' => 0,
        'cookie' => tempnam(sys_get_temp_dir(), 'ltr') ?: '',
        'csrf' => null,
        'done' => false,
        'next_at' => 0.0, // pacing: earliest time this user may fire its next request
    ];
}

/** Realistic think time between a user's steps (students type, read, answer).
 *  Set LT_FAST=1 to disable pacing (burst mode — all requests fire back-to-back). */
function thinkFor(string $step): float
{
    if (getenv('LT_FAST') === '1') return 0.0;
    return match ($step) {
        'login_page' => mt_rand(30, 100) / 100,   // 0.3-1.0s typing name/roll
        'login_post' => mt_rand(100, 250) / 100,  // 1.0-2.5s
        'wait_poll'  => mt_rand(80, 150) / 100,   // 0.8-1.5s (real client polls every 2s)
        'fetch_exam' => mt_rand(300, 600) / 100,  // 3-6s reading the question paper
        'autosave'   => mt_rand(300, 600) / 100,  // 3-6s answering more questions
        default      => 0.0,
    };
}

/** Build the next curl handle for a user (null when journey complete). */
function nextHandle(array &$u, int $examId, string $base): ?object
{
    $steps = [
        'login_page' => ['method' => 'GET', 'url' => '/slogin.php', 'headers' => [], 'body' => null],
        'login_post' => ['method' => 'POST', 'url' => '/slogin.php', 'headers' => [], 'body' => null],
        'wait_poll'  => ['method' => 'GET', 'url' => '/waite.php?check_status=1', 'headers' => [], 'body' => null],
        // examportal.php ignores the exam_id param and serves the LIVE exam from
        // the session/DB (it is what waite.php redirects to in the real flow).
        'fetch_exam' => ['method' => 'GET', 'url' => '/examportal.php', 'headers' => [], 'body' => null],
        'autosave'   => ['method' => 'POST', 'url' => '/autosave.php', 'headers' => [], 'body' => null],
        'submit'     => ['method' => 'POST', 'url' => '/submit_exam.php', 'headers' => [], 'body' => null],
    ];
    if ($u['step'] >= count($steps)) {
        $u['done'] = true;
        return null;
    }
    $name = $steps[$u['step']]['name'] ?? array_keys($steps)[$u['step']];
    $cfg = $steps[array_keys($steps)[$u['step']]];

    $ch = curl_init();
    $url = $base . $cfg['url'];
    $headers = ['User-Agent: LoadTest-Bot/' . $u['id']];

    if ($cfg['method'] === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $headers[] = 'X-CSRF-Token: ' . ($u['csrf'] ?? '');
        $headers[] = 'X-Requested-With: XMLHttpRequest';
        $headers[] = 'Content-Type: application/json';
        if ($name === 'login_post') {
            $form = http_build_query([
                'fullname'   => "Load User {$u['id']}",
                // Validator::rollNumber requires 1..999; 400..599 is a clean,
                // currently unused range (existing students: roll 241).
                'rollnumber' => 400 + $u['id'],
                'stream'     => 'Natural Science',
                'section'    => 'A',
                'csrf_token' => $u['csrf'] ?? '',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: LoadTest-Bot/' . $u['id'], 'Content-Type: application/x-www-form-urlencoded']);
        } else {
            // autosave / submit — small answer payload
            $payload = json_encode([
                'csrf_token' => $u['csrf'] ?? '',
                'answers' => ['1' => 'a', '2' => 'b', '3' => 'c'],
                'flags' => [],
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => $u['cookie'],
        CURLOPT_COOKIEJAR => $u['cookie'],
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$u, $name) {
            // capture Set-Cookie (kept by curl's jar) and CSRF from redirects
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $m)) {
                if (strtolower($m[1]) === 'eaessessid' && isset($u['_sid']) === false) {
                    $u['_sid'] = $m[2];
                }
            }
            if (preg_match('/^Location:\s*(.*)$/i', $line, $m2)) {
                $u['_loc'] = trim($m2[1]);
            }
            return strlen($line);
        },
    ]);
    // curl_multi's cookie-jar write/read timing is racy (the POST fired without
    // the GET's cookie), so pass the session id explicitly for every request
    // after login_page. CURLOPT_COOKIE overrides the jar for the outgoing header.
    if (!empty($u['_sid'])) {
        curl_setopt($ch, CURLOPT_COOKIE, 'EAESSESSID=' . $u['_sid']);
    }
    $u['step']++;
    $u['_name'] = $name;
    return (object) ['ch' => $ch, 'name' => $name, 'user' => &$u, 'start' => now()];
}

// ---------------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------------

$mh = curl_multi_init();
$users = [];
for ($i = 0; $i < $VUS; $i++) {
    $users[$i] = makeUser($i);
}
$active = 0;
$spawned = 0;
$completed = 0;
$planned = $VUS * count($STEPS);
$handles = []; // resource-id => handle-object

/** True if the given user id already has an in-flight curl handle. */
function hasInflight(array $handles, int $uid): bool {
    foreach ($handles as $h) {
        if ($h->user['id'] === $uid) return true;
    }
    return false;
}

while ($completed < $planned && (now() - $STARTED) < ($RAMP + 240)) {
    // Ramp: bring users online linearly over RAMP seconds. Each user is spawned
    // exactly once (their current step), so the ramp is a real arrival curve.
    $elapsed = now() - $STARTED;
    $target = (int) min($VUS, floor($VUS * ($elapsed / $RAMP)));
    while ($spawned < $target) {
        if (!hasInflight($handles, $users[$spawned]['id'])) {
            $h = nextHandle($users[$spawned], $EXAM_ID, $BASE);
            if ($h) {
                curl_multi_add_handle($mh, $h->ch);
                $handles[(int) $h->ch] = $h;
                $active++;
            }
        }
        $spawned++;
    }

    // Feed the multi loop: advance ramped-in users with no in-flight request,
    // subject to their per-step think time (realistic pacing).
    foreach ($users as &$u) {
        if ($u['done'] || $u['id'] >= $target) continue;
        if (now() < $u['next_at']) continue; // still "thinking"
        if (hasInflight($handles, $u['id'])) continue; // already has an in-flight request
        $h = nextHandle($u, $EXAM_ID, $BASE);
        if ($h) {
            curl_multi_add_handle($mh, $h->ch);
            $handles[(int) $h->ch] = $h;
            $active++;
        }
    }
    unset($u);

    do {
        $mrc = curl_multi_exec($mh, $running);
    } while ($mrc === CURLM_CALL_MULTI_PERFORM);

    // Harvest completed transfers
    while (($info = curl_multi_info_read($mh)) !== false) {
        $ch = $info['handle'];
        $rid = (int) $ch;
        if (!isset($handles[$rid])) continue;
        $h = $handles[$rid];
        $ms = (now() - $h->start) * 1000;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $RESULTS[] = [
            'uid' => $h->user['id'],
            't' => round(now() - $STARTED, 3),
            'step' => $h->name,
            'status' => $status,
            'ms' => round($ms, 1),
            'errno' => $errno,
            'error' => $error,
            'loc' => $h->user['_loc'] ?? '',
        ];
        // Pace the user's next step (realistic exam behaviour).
        $h->user['next_at'] = now() + thinkFor($h->name);
        // On login page (GET), scrape the CSRF token from the HTML
        if ($h->name === 'login_page') {
            $body = curl_multi_getcontent($ch);
            if ($body && preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m)) {
                $h->user['csrf'] = $m[1];
            } elseif ($body && preg_match('/value="([a-f0-9]{64})"/', $body, $m)) {
                $h->user['csrf'] = $m[1];
            }
        }
        if (getenv('DBG') === '1' && ($h->name === 'login_post' || $h->name === 'autosave')) {
            $body = curl_multi_getcontent($ch);
            $info = 'status=' . $status
                . '\ncsrf_used=' . ($h->user['csrf'] ?? 'NULL')
                . '\njar=' . (is_file($h->user['cookie']) ? file_get_contents($h->user['cookie']) : 'NO JAR')
                . '\n\n' . substr($body ?: '', 0, 6000);
            file_put_contents("tests/out/dbg_{$h->name}.txt", $info);
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($handles[$rid]);
        $active--;
        $completed++;
    }

    if ($running > 0) {
        curl_multi_select($mh, 0.02);
    } elseif ($completed < $planned) {
        usleep(2000);
    }
}

// ---------------------------------------------------------------------------
// Aggregate
// ---------------------------------------------------------------------------

$durations = array_column($RESULTS, 'ms');
$byStep = [];
foreach ($RESULTS as $r) {
    $byStep[$r['step']][] = $r;
}

function pct(array $arr, float $p): float
{
    sort($arr);
    if (!$arr) return 0.0;
    $idx = (int) ceil($p / 100 * count($arr)) - 1;
    return (float) ($arr[max(0, $idx)] ?? $arr[count($arr) - 1]);
}

$summary = [
    'vus' => $VUS,
    'ramp_seconds' => $RAMP,
    'exam_id' => $EXAM_ID,
    'wall_seconds' => round(now() - $STARTED, 2),
    'planned_requests' => $planned,
    'completed_requests' => count($RESULTS),
    'total_ms' => round(array_sum($durations), 1),
    'overall' => [
        'success' => count(array_filter($RESULTS, fn ($r) => $r['status'] >= 200 && $r['status'] < 400 && $r['errno'] === 0)),
        'failure' => count(array_filter($RESULTS, fn ($r) => !($r['status'] >= 200 && $r['status'] < 400 && $r['errno'] === 0))),
        'avg_ms' => $durations ? round(array_sum($durations) / count($durations), 1) : 0,
        'median_ms' => round(pct($durations, 50), 1),
        'p95_ms' => round(pct($durations, 95), 1),
        'p99_ms' => round(pct($durations, 99), 1),
        'max_ms' => $durations ? round(max($durations), 1) : 0,
        'rps' => $durations ? round(count($durations) / (now() - $STARTED), 2) : 0,
    ],
    'by_step' => [],
];

foreach ($byStep as $step => $rows) {
    $d = array_column($rows, 'ms');
    $ok = count(array_filter($rows, fn ($r) => $r['status'] >= 200 && $r['status'] < 400 && $r['errno'] === 0));
    $summary['by_step'][$step] = [
        'requests' => count($rows),
        'success' => $ok,
        'failure' => count($rows) - $ok,
        'avg_ms' => round(array_sum($d) / count($d), 1),
        'median_ms' => round(pct($d, 50), 1),
        'p95_ms' => round(pct($d, 95), 1),
        'max_ms' => round(max($d), 1),
    ];
    // HTTP status breakdown for failures
    $codes = [];
    foreach ($rows as $r) {
        $c = $r['status'] ?: "err{$r['errno']}";
        $codes[$c] = ($codes[$c] ?? 0) + 1;
    }
    $summary['by_step'][$step]['status_codes'] = $codes;
}

// Also dump any 3xx redirect targets in the summary for diagnosis
$summary['redirects'] = [];
foreach ($RESULTS as $r) {
    if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['loc'])) {
        $summary['redirects'][$r['step']][] = $r['loc'];
    }
}

// Peak concurrent USERS: a user counts as active between their first and last
// request (their whole journey). Bucket the wall into 1s windows.
$userSpan = [];
foreach ($RESULTS as $r) {
    $uid = $r['uid'];
    $userSpan[$uid]['first'] = min($userSpan[$uid]['first'] ?? PHP_FLOAT_MAX, $r['t']);
    $userSpan[$uid]['last']  = max($userSpan[$uid]['last']  ?? 0.0, $r['t']);
}
$summary['concurrency'] = ['peak_users' => 0, 'peak_at_s' => 0, 'per_second' => []];
$wall = (float) $summary['wall_seconds'];
for ($s = 0; $s <= (int) ceil($wall); $s++) {
    $n = 0;
    foreach ($userSpan as $sp) {
        if ($sp['first'] <= $s && $sp['last'] >= $s) $n++;
    }
    $summary['concurrency']['per_second'][$s] = $n;
    if ($n > $summary['concurrency']['peak_users']) {
        $summary['concurrency']['peak_users'] = $n;
        $summary['concurrency']['peak_at_s'] = $s;
    }
}

file_put_contents($PREFIX . '.summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$csv = fopen($PREFIX . '.requests.csv', 'w');
fputcsv($csv, ['uid', 't', 'step', 'status', 'ms', 'errno', 'error', 'loc']);
foreach ($RESULTS as $r) {
    fputcsv($csv, $r);
}
fclose($csv);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
