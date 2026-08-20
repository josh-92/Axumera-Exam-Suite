<?php
namespace App\Services;

use App\Repositories\RecommendationRepository;
use App\Services\CurriculumIntelligenceService;

class ExamRecommendationEngine {
    private RecommendationRepository $repo;
    private CurriculumIntelligenceService $curriculumService;

    public function __construct() {
        $this->repo = new RecommendationRepository();
        $this->curriculumService = new CurriculumIntelligenceService();
    }

    public function generateRecommendations(int $examId): array {
        $questions = $this->repo->getExamQuestionMetrics($examId);
        $recommendations = [];
        
        $totalDifficulty = 0;
        $validDifficultyCount = 0;

        foreach ($questions as $q) {
            $qId = (int)$q['question_id'];
            $topicId = (int)$q['curriculum_topic_id'];
            $attempts = (int)$q['total_attempts'];
            $classification = $q['classification'] ?? 'Unknown';
            $diffIndex = (float)($q['difficulty_index'] ?? 0);
            
            if ($attempts > 0) {
                $totalDifficulty += $diffIndex;
                $validDifficultyCount++;
            }

            // Rule 1: Retire Poor Questions
            if ($classification === 'Retire') {
                $alt = $this->repo->findBetterAlternative($topicId, $qId);
                $recommendations[] = $this->formatRecommendation(
                    'CRITICAL_REPLACE', $qId, $q['question'],
                    "Question has negative discrimination or >25% skip rate. It is harming exam reliability.",
                    $alt
                );
                continue;
            }

            // Rule 2: Replace Weak Questions
            if ($classification === 'Needs Review') {
                $alt = $this->repo->findBetterAlternative($topicId, $qId);
                if ($alt) {
                    $recommendations[] = $this->formatRecommendation(
                        'SUGGEST_REPLACE', $qId, $q['question'],
                        "Question quality score is sub-optimal ({$q['quality_score']}). A higher-quality alternative exists.",
                        $alt
                    );
                }
            }

            // Rule 3: Validate Underused Questions
            if ($attempts > 0 && $attempts < 10 && $classification === 'Good') {
                $recommendations[] = [
                    'type' => 'NEEDS_VALIDATION',
                    'question_id' => $qId,
                    'text' => $q['question'],
                    'explanation' => "This question is underused ({$attempts} attempts). Keep it in this exam to build statistical reliability."
                ];
            }
        }

        // Rule 4: Balanced Difficulty Assessment
        $avgDifficulty = $validDifficultyCount > 0 ? ($totalDifficulty / $validDifficultyCount) : 0.5;
        $examBalance = 'Optimal';
        if ($avgDifficulty < 0.3) {
            $examBalance = 'Too Hard';
            $recommendations[] = ['type' => 'EXAM_BALANCE', 'explanation' => "Overall exam difficulty index is {$avgDifficulty} (Too Hard). Consider swapping some 'Needs Review' items with easier alternatives."];
        } elseif ($avgDifficulty > 0.8) {
            $examBalance = 'Too Easy';
            $recommendations[] = ['type' => 'EXAM_BALANCE', 'explanation' => "Overall exam difficulty index is {$avgDifficulty} (Too Easy). Consider adding questions with a lower p-value."];
        }

        // Rule 5: Curriculum Balance (calling the previous engine)
        $curriculumReport = $this->curriculumService->analyzeCoverage($examId);
        foreach ($curriculumReport['missing_topics'] as $missing) {
            $recommendations[] = [
                'type' => 'CURRICULUM_GAP',
                'explanation' => "Missing Topic: {$missing}. Consider adding a question from this topic."
            ];
        }

        return [
            'exam_id' => $examId,
            'average_difficulty' => round($avgDifficulty, 2),
            'difficulty_status' => $examBalance,
            'recommendations' => $recommendations
        ];
    }

    private function formatRecommendation(string $type, int $oldId, string $oldText, string $reason, ?array $alt): array {
        $rec = [
            'type' => $type,
            'question_id' => $oldId,
            'text' => $oldText,
            'explanation' => $reason,
            'has_alternative' => false
        ];
        if ($alt) {
            $rec['has_alternative'] = true;
            $rec['alternative_id'] = $alt['new_question_id'];
            $rec['alternative_text'] = $alt['new_question_text'];
            $rec['alternative_explanation'] = "Suggested replacement has classification '{$alt['classification']}' and a Quality Score of {$alt['quality_score']} (Attempts: {$alt['total_attempts']}).";
        }
        return $rec;
    }
}