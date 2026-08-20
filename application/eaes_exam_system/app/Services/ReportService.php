<?php

namespace App\Services;

use App\Repositories\AttemptRepository;
use App\Repositories\QuestionRepository;

class ReportService
{
    /** Stream a per-student scoreboard CSV for one exam directly to the browser. */
    public static function streamScoreboardCsv(array $exam): void
    {
        $examId = (int) $exam['id'];
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $exam['exam_name']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '_Scoreboard_' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // Excel UTF-8 BOM

        fputcsv($out, ['Roll Number', 'Full Name', 'Stream', 'Section', 'Score', 'Total Questions', 'Percentage (%)', 'Status', 'Started At', 'Submitted At', 'Integrity Violations', 'Integrity Status']);

        $totalQuestions = QuestionRepository::countForExam($examId) ?: 1;
        $attempts = AttemptRepository::forExam($examId);

        if (!$attempts) {
            fputcsv($out, ['--', 'No attempts recorded for this exam yet.', '--', '--', '--', '--', '--', '--', '--', '--', '--', '--']);
        }

        foreach ($attempts as $a) {
            $isDone = in_array($a['status'], ['submitted', 'auto_submitted'], true);
            $score = $isDone ? $a['score'] : null;
            $pct = ($isDone && $a['total_questions'] > 0) ? round(($a['score'] / $a['total_questions']) * 100, 1) . '%' : 'N/A';
            $statusLabel = match ($a['status']) {
                'submitted'      => 'Completed',
                'auto_submitted' => 'Auto-submitted (time expired)',
                default          => 'In progress / incomplete',
            };

            fputcsv($out, [
                $a['roll_number'],
                strtoupper($a['full_name']),
                ucfirst($a['stream']),
                'Section ' . strtoupper($a['section']),
                $score ?? 'N/A',
                $isDone ? $a['total_questions'] : $totalQuestions,
                $pct,
                $statusLabel,
                $a['started_at'],
                $a['submitted_at'] ?? '',
                (int) ($a['violation_count'] ?? 0),
                ((string) ($a['integrity_status'] ?? 'clean')) === 'flagged' ? 'Flagged for review' : 'Clean',
            ]);
        }

        fclose($out);
    }

    /** Stream a per-question difficulty/accuracy report for one exam. */
    public static function streamQuestionReportCsv(array $exam, array $questionStats): void
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $exam['exam_name']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '_QuestionAnalysis_' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Question #', 'Times Answered', 'Times Correct', 'Accuracy (%)']);

        foreach ($questionStats as $row) {
            fputcsv($out, [
                $row['question_number'],
                $row['answered'],
                $row['correct'],
                $row['accuracy_pct'] ?? 'N/A',
            ]);
        }

        fclose($out);
    }
}
