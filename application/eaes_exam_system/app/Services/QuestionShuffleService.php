<?php

namespace App\Services;

use App\Repositories\ExamQuestionShuffleRepository;

/**
 * Generates (once) and thereafter simply replays a per-student presentation
 * order for an exam's questions and, optionally, each question's multiple
 * choice options.
 *
 * Design notes
 * ------------
 *  - Randomization is CSPRNG-based (random_int via Fisher-Yates), never
 *    PHP's Mersenne-Twister shuffle()/mt_rand(), and only ever runs
 *    server-side — the client never sees or influences the ordering logic.
 *  - "Reading passage" blocks (the existing `is_passage = 1` rows) move as a
 *    single unit together with the real questions that follow them, up to
 *    the next passage block. Shuffling passage-linked questions
 *    independently of their passage would scatter a comprehension
 *    passage's sub-questions across the exam — a correctness/UX
 *    regression, not an anti-cheating improvement. Freestanding questions
 *    (no passage) are still fully independent and shuffle individually.
 *  - Choice order NEVER changes which letter is correct. Every option is
 *    always identified by its ORIGINAL letter (a/b/c/d); we only shuffle
 *    the order those original letters are displayed in, and the client is
 *    responsible for always submitting the original letter (see exam.js).
 *    This means GradingService, autosave, and the `answers` JSON blob never
 *    need to know shuffling exists — zero changes required there, and zero
 *    risk of ever losing or misattributing a correct answer.
 *  - Generation happens exactly once per (student, exam): see
 *    ExamQuestionShuffleRepository, which enforces this with a database
 *    unique key rather than application-level locking, so it is correct
 *    even under concurrent requests (duplicate tab, retried request, etc.).
 */
class QuestionShuffleService
{
    /**
     * @param array $exam exams row — needs shuffle_questions / shuffle_choices
     * @param array $questionRows QuestionRepository::forExam() rows, already ordered by question_number ASC
     * @return array{question_order:int[], choice_order:array<int,string>}
     */
    public static function getOrCreateForStudent(int $studentId, int $examId, int $attemptId, array $exam, array $questionRows): array
    {
        $existing = ExamQuestionShuffleRepository::find($studentId, $examId);
        if ($existing) {
            return self::decode($existing, $questionRows);
        }

        $questionOrder = self::buildQuestionOrder($questionRows, !empty($exam['shuffle_questions']));
        $choiceOrder = !empty($exam['shuffle_choices']) ? self::buildChoiceOrder($questionRows) : [];

        $saved = ExamQuestionShuffleRepository::createIfNotExists($studentId, $examId, $attemptId, $questionOrder, $choiceOrder);

        return self::decode($saved, $questionRows);
    }

    private static function decode(array $row, array $questionRows): array
    {
        $order = json_decode((string) ($row['question_order'] ?? ''), true);
        $choices = json_decode((string) ($row['choice_order'] ?? ''), true);

        return [
            'question_order' => is_array($order) && $order !== []
                ? $order
                : array_map(static fn($r) => (int) $r['question_number'], $questionRows),
            'choice_order' => is_array($choices) ? $choices : [],
        ];
    }

    /**
     * @param array $questionRows rows for the whole exam (passages + real questions), in original order
     * @return int[] every question_number (real + passage) in this student's display order — same
     *               multiset as the input, just reordered; questions are never dropped or duplicated
     */
    public static function buildQuestionOrder(array $questionRows, bool $shuffle): array
    {
        if (!$shuffle || count($questionRows) < 2) {
            return array_map(static fn($r) => (int) $r['question_number'], $questionRows);
        }

        // Group into blocks: a passage row starts a new block; subsequent
        // real-question rows join that block until the next passage row.
        // A freestanding real question (no passage before it) is its own
        // single-item block, so it shuffles independently of everything else.
        $blocks = [];
        $current = null;
        foreach ($questionRows as $row) {
            if ((int) $row['is_passage'] === 1) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = [$row];
            } elseif ($current !== null) {
                $current[] = $row;
            } else {
                $blocks[] = [$row];
            }
        }
        if ($current !== null) {
            $blocks[] = $current;
        }

        $blocks = self::secureShuffle($blocks);

        $order = [];
        foreach ($blocks as $block) {
            foreach ($block as $row) {
                $order[] = (int) $row['question_number'];
            }
        }
        return $order;
    }

    /**
     * @param array $questionRows rows for the whole exam
     * @return array<int,string> question_number => string of the option letters that ARE populated,
     *                            in the order they should be displayed (e.g. "cadb", or "bca" for a
     *                            3-option question). Passage rows are skipped — they have no options.
     */
    public static function buildChoiceOrder(array $questionRows): array
    {
        $map = [];
        foreach ($questionRows as $row) {
            if ((int) $row['is_passage'] === 1) {
                continue;
            }
            $letters = ['a', 'b', 'c', 'd'];
            // Only shuffle letters that actually have a non-empty option, so a
            // 3-option question never displays a blank slot in a shuffled spot.
            $populated = array_values(array_filter(
                $letters,
                static fn($l) => trim((string) ($row['option_' . $l] ?? '')) !== ''
            ));
            $map[(int) $row['question_number']] = implode('', self::secureShuffle($populated));
        }
        return $map;
    }

    /**
     * Fisher-Yates shuffle using a cryptographically secure RNG.
     * Deliberately NOT shuffle()/mt_rand() — those use PHP's Mersenne
     * Twister, which is not cryptographically secure and is unsuitable for
     * anything with exam-integrity implications.
     */
    public static function secureShuffle(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }
}
