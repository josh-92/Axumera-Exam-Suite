<?php
namespace App\Services;

use App\Repositories\CurriculumRepository;
use App\Repositories\ExamGeneratorRepository;
use App\Repositories\BlueprintRepository;
use Exception;

class CurriculumIntelligenceService {
    private CurriculumRepository $curriculumRepo;
    private ExamGeneratorRepository $examRepo;
    private BlueprintRepository $blueprintRepo;

    public function __construct() {
        $this->curriculumRepo = new CurriculumRepository();
        $this->examRepo = new ExamGeneratorRepository();
        $this->blueprintRepo = new BlueprintRepository();
    }

    public function analyzeCoverage(int $examId): array {
        $exam = $this->examRepo->getExam($examId);
        if (!$exam) throw new Exception("Exam not found.");

        $blueprint = $this->blueprintRepo->getBlueprint($exam['blueprint_id']);
        if (!$blueprint) throw new Exception("Associated blueprint not found.");

        $curriculumStructure = $this->curriculumRepo->getChaptersBySubjectAndGrade($blueprint['subject'], $blueprint['grade']);
        $examBreakdown = $this->curriculumRepo->getExamCurriculumBreakdown($examId);

        $chapters = [];
        foreach ($curriculumStructure as $row) {
            $cId = $row['id'];
            if (!isset($chapters[$cId])) {
                $chapters[$cId] = [
                    'chapter_name' => $row['name'],
                    'target_weight' => $row['target_weight'],
                    'actual_questions' => 0,
                    'actual_marks' => 0,
                    'topics' => []
                ];
            }
            if ($row['topic_id']) {
                $chapters[$cId]['topics'][$row['topic_id']] = [
                    'topic_name' => $row['topic_name'],
                    'target_weight' => $row['topic_weight'],
                    'actual_questions' => 0,
                    'actual_marks' => 0
                ];
            }
        }

        $totalExamMarks = (float)$exam['total_marks'];
        $uncoveredChapters = [];
        $missingTopics = [];
        $recommendations = [];

        foreach ($examBreakdown as $b) {
            $cId = $b['chapter_id'];
            $tId = $b['curriculum_topic_id'];
            $marks = (float)$b['total_marks'];
            $qs = (int)$b['question_count'];

            if ($cId && isset($chapters[$cId])) {
                $chapters[$cId]['actual_questions'] += $qs;
                $chapters[$cId]['actual_marks'] += $marks;

                if ($tId && isset($chapters[$cId]['topics'][$tId])) {
                    $chapters[$cId]['topics'][$tId]['actual_questions'] = $qs;
                    $chapters[$cId]['topics'][$tId]['actual_marks'] = $marks;
                }
            }
        }

        foreach ($chapters as $cId => &$chap) {
            $actualMarks = $chap['actual_marks'];
            $actualPercentage = $totalExamMarks > 0 ? ($actualMarks / $totalExamMarks) * 100 : 0;
            $chap['actual_percentage'] = round($actualPercentage, 2);

            if ($chap['actual_questions'] === 0) {
                $uncoveredChapters[] = $chap['chapter_name'];
                $recommendations[] = "Add questions from Chapter: '{$chap['chapter_name']}' to align with curriculum standards.";
            }

            foreach ($chap['topics'] as $tId => &$top) {
                $topPercentage = $totalExamMarks > 0 ? ($top['actual_marks'] / $totalExamMarks) * 100 : 0;
                $top['actual_percentage'] = round($topPercentage, 2);
                
                if ($top['actual_questions'] === 0) {
                    $missingTopics[] = "{$chap['chapter_name']} > {$top['topic_name']}";
                    $recommendations[] = "Include questions covering Topic: '{$top['topic_name']}' under Chapter '{$chap['chapter_name']}'.";
                }
            }
        }
        unset($chap, $top);

        return [
            'exam_id' => $examId,
            'subject' => $blueprint['subject'],
            'grade' => $blueprint['grade'],
            'total_exam_marks' => $totalExamMarks,
            'chapters_breakdown' => array_values($chapters),
            'uncovered_chapters' => $uncoveredChapters,
            'missing_topics' => $missingTopics,
            'recommendations' => $recommendations
        ];
    }
}