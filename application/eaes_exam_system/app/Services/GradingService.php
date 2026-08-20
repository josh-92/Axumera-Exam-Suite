<?php

namespace App\Services;

use App\Repositories\QuestionRepository;

class GradingService
{
    /**
     * @param array<int|string, string> $studentAnswers question_number => chosen option
     * @return array{score:int, total:int}
     */
    public static function grade(int $examId, array $studentAnswers): array
    {
        $answerKey = QuestionRepository::answerKey($examId);
        $score = 0;

        foreach ($answerKey as $questionNumber => $correctAnswer) {
            $chosen = $studentAnswers[$questionNumber] ?? $studentAnswers[(string) $questionNumber] ?? null;
            if ($chosen === null) {
                continue;
            }
            $chosen = strtolower(trim((string) $chosen));
            if ($chosen === $correctAnswer && $correctAnswer !== '') {
                $score++;
            }
        }

        return ['score' => $score, 'total' => count($answerKey)];
    }
}
