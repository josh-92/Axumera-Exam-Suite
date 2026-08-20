<?php

namespace App\Services;

/**
 * DocxQuestionParser
 * ------------------
 * Turns a Microsoft Word document (or plain-text file) into structured
 * question rows WITHOUT requiring teachers to follow a template.
 *
 * What it understands (the conventional ways teachers write questions):
 *   - numbered stems      "1. What is …", "1) …", "Q3. …", "Question 3: …"
 *   - lettered options    "A. … B. … C. … D. …" (lowercase accepted too)
 *   - True/False items    stem followed by "True"/"False" lines, or a
 *                         "(True/False)" / "True or False" tag in the stem
 *   - correct answers     "Answer: C", "Ans. B", "The correct answer is A",
 *                         a BOLDED or UNDERLINED option, or an answer-key
 *                         block at the end ("Answers: 1-B 2-A 3-C")
 *   - table layouts       question in row 1, options in cells below
 *   - multi-line stems    wrapped paragraphs are joined back together
 *
 * Everything is best-effort: the parser never decides silently. Questions
 * missing a detected answer come back with correct_answer = null and a note,
 * so the UI can ask the teacher to confirm before anything is saved.
 *
 * Implementation notes:
 *   - .docx files are ZIP archives; ZipArchive is NOT assumed (it is often
 *     disabled), so a tiny ZIP reader extracts word/document.xml and
 *     decompresses it with gzinflate (zlib is always bundled).
 *   - No Composer, no external libraries — fits the project's style.
 */

class DocxQuestionParser
{
    /** Cap on parsed questions per file (sanity bound for the UI + import). */
    public const MAX_QUESTIONS = 500;

    /**
     * Inflate guard: document.xml inside a 5 MB upload can never legitimately
     * exceed this, so it stops zip-bomb decompression from exhausting memory.
     */
    private const MAX_DOCUMENT_XML_BYTES = 64 * 1024 * 1024;

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    /**
     * Parse an uploaded file. $ext is 'docx' or 'txt'.
     *
     * @return array{questions: array<int,array<string,mixed>>, warnings: array<int,string>}
     */
    public static function parseFile(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        if ($ext === 'docx') {
            $blocks = self::blocksFromDocx($path);
        } else {
            $content = (string) file_get_contents($path);
            $blocks = self::blocksFromText($content);
        }
        return self::parseBlocks($blocks);
    }

    /**
     * Public seam for callers that already hold raw text (e.g. tests).
     */
    public static function parseText(string $text): array
    {
        return self::parseBlocks(self::blocksFromText($text));
    }

    /**
     * Convert parsed questions into QuestionBankRepository::import() rows
     * (CSV-style keys). correct_answer stays empty when undetected so the
     * bank validator rejects the row instead of storing a wrong key.
     *
     * @param array<int,array<string,mixed>> $questions
     * @return array<int,array<string,mixed>>
     */
    public static function toImportRows(array $questions, array $defaults = []): array
    {
        $rows = [];
        foreach ($questions as $i => $q) {
            $rows[] = [
                '_line' => $i + 1,
                'question' => (string) $q['question'],
                // The bank has no Passage type — a detected passage is stored
                // as an Essay reference question (or can be unchecked in review).
                'type' => ($q['type'] ?? '') === 'Passage' ? 'Essay' : (string) $q['type'],
                'difficulty' => (string) ($q['difficulty'] ?? $defaults['difficulty'] ?? ''),
                'subject' => (string) ($defaults['subject'] ?? ''),
                'grade' => (string) ($defaults['grade'] ?? ''),
                'topic' => '',
                'tags' => '',
                'option_a' => (string) ($q['options']['a'] ?? ''),
                'option_b' => (string) ($q['options']['b'] ?? ''),
                'option_c' => (string) ($q['options']['c'] ?? ''),
                'option_d' => (string) ($q['options']['d'] ?? ''),
                'correct_answer' => (string) ($q['correct_answer'] ?? ''),
            ];
        }
        return $rows;
    }

    // =====================================================================
    // 1. Document → ordered blocks
    // =====================================================================

    /** A block is ['text', 'bold', 'underline', 'style', 'table']. */
    private static function blocksFromDocx(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $documentXml = self::zipEntry($raw, 'word/document.xml');
        if ($documentXml === null) {
            throw new \InvalidArgumentException(
                'This file does not look like a Word document (no word/document.xml inside).'
            );
        }

        $xml = @simplexml_load_string($documentXml);
        if ($xml === false) {
            throw new \InvalidArgumentException('The Word document could not be read — is the file damaged?');
        }
        $xml->registerXPathNamespace('w', self::WORD_NS);

        $body = $xml->xpath('//w:body');
        $root = $body !== [] ? $body[0] : $xml;

        $blocks = [];
        foreach ($root->children(self::WORD_NS) as $child) {
            $name = $child->getName();
            if ($name === 'p') {
                $blocks[] = self::paragraphBlock($child);
            } elseif ($name === 'tbl') {
                foreach (self::tableBlocks($child) as $b) {
                    $blocks[] = $b;
                }
            }
            // sectPr (page setup) and anything else is ignored
        }
        return $blocks;
    }

    private static function blocksFromText(string $text): array
    {
        // Word's "Save As → Unicode text" writes UTF-16 with a BOM.
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }
        $text = str_replace("\0", '', $text); // stray NUL bytes from other encodings
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text); // UTF-8 BOM
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $blocks[] = [
                'text' => self::normalizeSpace($line),
                'bold' => false,
                'underline' => false,
                'style' => null,
                'table' => false,
                'numpr' => false,
            ];
        }
        return $blocks;
    }

    /** Text of one paragraph plus run-level formatting (bold / underline). */
    private static function paragraphBlock(\SimpleXMLElement $p): array
    {
        $text = '';
        $bold = false;
        $underline = false;
        foreach ($p->children(self::WORD_NS) as $child) {
            $name = $child->getName();
            if ($name === 'r') {
                $rpr = $child->children(self::WORD_NS)->rPr ?? null;
                if ($rpr !== null) {
                    if (isset($rpr->b)) {
                        $val = (string) ($rpr->b->attributes(self::WORD_NS)['val'] ?? '');
                        if ($val === '' || (strtolower($val) !== 'false' && $val !== '0')) {
                            $bold = true;
                        }
                    }
                    if (isset($rpr->u) && (string) $rpr->u->attributes(self::WORD_NS)['val'] !== 'none') {
                        $underline = true;
                    }
                }
                $text .= self::extractText($child);
            } elseif ($name === 'tab' || $name === 'br' || $name === 'cr') {
                $text .= ' ';
            } else {
                $text .= self::extractText($child);
            }
        }

        $style = null;
        $p->registerXPathNamespace('w', self::WORD_NS);
        $styles = $p->xpath('.//w:pPr/w:pStyle/@w:val');
        if ($styles !== []) {
            $style = (string) $styles[0];
        }

        $numpr = false;
        if ($p->xpath('.//w:pPr/w:numPr') !== []) {
            $numpr = true; // Word list numbering (numId) — the visible "1." is rendered
        }

        return [
            'text' => self::normalizeSpace($text),
            'bold' => $bold,
            'underline' => $underline,
            'style' => $style,
            'table' => false,
            'numpr' => $numpr,
        ];
    }

    /** Recursively gather all w:t text (handles hyperlinks, nested runs). */
    private static function extractText(\SimpleXMLElement $el): string
    {
        $out = '';
        foreach ($el->children(self::WORD_NS) as $child) {
            $name = $child->getName();
            if ($name === 't') {
                $out .= (string) $child;
            } elseif ($name === 'tab' || $name === 'br' || $name === 'cr') {
                $out .= ' ';
            } else {
                $out .= self::extractText($child);
            }
        }
        return $out;
    }

    /**
     * Flatten a Word table into one block per row (cells joined by two
     * spaces). Covers both common layouts: a question row followed by
     * option rows, and rows of [letter, option text].
     */
    private static function tableBlocks(\SimpleXMLElement $tbl): array
    {
        $blocks = [];
        $tbl->registerXPathNamespace('w', self::WORD_NS);
        foreach ($tbl->xpath('./w:tr') as $tr) {
            $cells = [];
            $tr->registerXPathNamespace('w', self::WORD_NS);
            foreach ($tr->xpath('./w:tc') as $tc) {
                $cell = '';
                $tc->registerXPathNamespace('w', self::WORD_NS);
                foreach ($tc->xpath('.//w:t') as $t) {
                    $cell .= (string) $t;
                }
                $cell = self::normalizeSpace($cell);
                if ($cell !== '') {
                    $cells[] = $cell;
                }
            }
            if ($cells !== []) {
                $blocks[] = [
                    'text' => implode('  ', $cells),
                    'bold' => false,
                    'underline' => false,
                    'style' => null,
                    'table' => true,
                    'numpr' => false,
                ];
            }
        }
        return $blocks;
    }

    // =====================================================================
    // 2. Minimal ZIP reader (no ZipArchive dependency)
    // =====================================================================

    /** Extract one entry's (decompressed) content from a ZIP byte string. */
    private static function zipEntry(string $data, string $wanted): ?string
    {
        $len = strlen($data);
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false || $eocd + 22 > $len) {
            return null;
        }
        $entryCount = self::u16($data, $eocd + 10);
        $cdOffset = self::u32($data, $eocd + 16);

        for ($i = 0; $i < $entryCount; $i++) {
            if ($cdOffset + 46 > $len || substr($data, $cdOffset, 4) !== "PK\x01\x02") {
                break;
            }
            $method = self::u16($data, $cdOffset + 10);
            $compSize = self::u32($data, $cdOffset + 20);
            $uncompSize = self::u32($data, $cdOffset + 24);
            $nameLen = self::u16($data, $cdOffset + 28);
            $extraLen = self::u16($data, $cdOffset + 30);
            $commentLen = self::u16($data, $cdOffset + 32);
            $localOffset = self::u32($data, $cdOffset + 42);
            $name = substr($data, $cdOffset + 46, $nameLen);

            if ($name === $wanted) {
                if ($localOffset + 30 > $len || substr($data, $localOffset, 4) !== "PK\x03\x04") {
                    return null;
                }
                $ln = self::u16($data, $localOffset + 26);
                $el = self::u16($data, $localOffset + 28);
                $payload = substr($data, $localOffset + 30 + $ln + $el, $compSize);
                if (strlen($payload) < $compSize) {
                    return null;
                }
                if ($method === 0) { // stored
                    return $payload;
                }
                if ($method === 8) { // deflate — bound output (zip-bomb guard)
                    if ($uncompSize > self::MAX_DOCUMENT_XML_BYTES) {
                        return null;
                    }
                    $out = @gzinflate($payload, $uncompSize + 1);
                    return $out === false ? null : $out;
                }
                return null; // unsupported compression
            }
            $cdOffset += 46 + $nameLen + $extraLen + $commentLen;
        }
        return null;
    }

    private static function u16(string $s, int $o): int
    {
        return ord($s[$o] ?? "\0") | (ord($s[$o + 1] ?? "\0") << 8);
    }

    private static function u32(string $s, int $o): int
    {
        return ord($s[$o] ?? "\0")
            | (ord($s[$o + 1] ?? "\0") << 8)
            | (ord($s[$o + 2] ?? "\0") << 16)
            | (ord($s[$o + 3] ?? "\0") << 24);
    }

    // =====================================================================
    // 3. Blocks → structured questions (heuristics)
    // =====================================================================

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @return array{questions: array<int,array<string,mixed>>, warnings: array<int,string>}
     */
    private static function parseBlocks(array $blocks): array
    {
        $questions = [];
        $current = null; // ['num'=>?int,'question'=>string[],'options'=>array,'bold'=>array,'hint'=>?string]
        $answerKey = []; // detected "Answers: 1-B 2-A" block → [num => letter]
        $inAnswerKey = false; // saw an "Answers:" header; next lines may be pairs
        $passageLines = []; // consecutive prose paragraphs before questions

        foreach ($blocks as $i => $block) {
            $text = (string) ($block['text'] ?? '');
            if (trim($text) === '') {
                continue;
            }
            $next = $blocks[$i + 1] ?? null;

            // --- per-question answer line ("Answer: C") ---------------------
            // Checked FIRST because "Answer: B" must not be treated as an
            // answer-key header ("Answers:" / "Answer key:" is the header).
            if ($current !== null) {
                $hint = self::answerLetter($text);
                if ($hint !== null) {
                    $current['hint'] = $hint;
                    continue;
                }
            }

            // --- answer-key block ("Answers: 1-B 2-A 3-C") -----------------
            $key = self::answerKeyLine($text); // header + pairs on one line
            if ($key !== null) {
                foreach ($key as $num => $letter) {
                    $answerKey[$num] = $letter;
                }
                continue;
            }
            if (self::answerKeyHeader($text)) {
                $inAnswerKey = true; // "Answers:" alone; pairs follow on later lines
                continue;
            }
            if ($inAnswerKey) {
                $pairs = self::answerKeyPairs($text);
                if ($pairs !== null) {
                    foreach ($pairs as $num => $letter) {
                        $answerKey[$num] = $letter;
                    }
                    continue;
                }
                $inAnswerKey = false; // not a key line — process normally
            }

            // --- True/False bare lines --------------------------------------
            if (self::isTrueFalseOption($text)) {
                if ($current === null) {
                    $current = self::newQuestion();
                }
                $current['options'][strtolower($text) === 'true' ? 'a' : 'b'] = ucfirst(strtolower($text));
                if ($block['bold'] || $block['underline']) {
                    $current['bold'][strtolower($text) === 'true' ? 'a' : 'b'] = true;
                }
                continue;
            }

            // --- lettered option ("A. Paris" / "a) text") -------------------
            if (self::isOption($text)) {
                if ($current === null) {
                    $current = self::newQuestion(); // stray options → orphan stem
                }
                preg_match('/^\s*([A-Ea-e])[.)]\s*(.+)$/', $text, $m);
                $letter = strtolower($m[1]);
                $optText = trim($m[2]);
                if ($optText === '') {
                    continue;
                }
                $prev = $current['options'][$letter] ?? '';
                $current['options'][$letter] = $prev === '' ? $optText : $prev . ' ' . $optText;
                if ($block['bold'] || $block['underline']) {
                    $current['bold'][$letter] = true;
                }
                continue;
            }

            // --- question start ----------------------------------------------
            if (self::isQuestionStart($block, $next, $current)) {
                self::flushPassage($questions, $passageLines);
                if ($current !== null) {
                    $questions[] = self::finalizeQuestion($current);
                }
                $num = self::questionNumber($text);
                $current = self::newQuestion();
                $current['num'] = $num;
                $stem = self::stripQuestionNumber($text);
                if ($stem !== '') {
                    $current['question'][] = $stem;
                }
                continue;
            }

            // --- continuation / passage content / ignore ---------------------
            if ($current !== null) {
                if ($current['options'] === []) {
                    $current['question'][] = $text; // wrapped stem line
                } elseif (self::isPassageParagraph($text) && !self::looksLikeOptionBlock($next)) {
                    // Long prose after a question's options (and not followed by
                    // another option): the previous item is finished and a reading
                    // passage (or next section) begins. A long paragraph followed
                    // by an option letter is a wrapped option line, not a passage.
                    $questions[] = self::finalizeQuestion($current);
                    $current = null;
                    $passageLines[] = $text;
                } else {
                    $last = array_key_last($current['options']);
                    $current['options'][$last] .= ' ' . $text; // wrapped option line
                }
            } elseif (self::isPassageParagraph($text)) {
                $passageLines[] = $text;
            }
            // other standalone lines (titles, instructions) are ignored
        }

        if ($current !== null) {
            $questions[] = self::finalizeQuestion($current);
        }
        self::flushPassage($questions, $passageLines); // trailing passage at end of doc

        // Apply a trailing answer-key block to questions whose numbers match
        // and that have no closer (per-question) answer of their own.
        if ($answerKey !== []) {
            foreach ($questions as &$q) {
                if ($q['correct_answer'] === null && isset($q['num'], $answerKey[$q['num']])) {
                    $q['correct_answer'] = $answerKey[$q['num']];
                }
                unset($q['num']);
            }
            unset($q);
        } else {
            foreach ($questions as &$q) {
                unset($q['num']);
            }
            unset($q);
        }

        $warnings = [];
        $passageCount = 0;
        $noAnswer = 0;
        foreach ($questions as $q) {
            if ($q['type'] === 'Passage') {
                $passageCount++;
            } elseif ($q['correct_answer'] === null) {
                $noAnswer++;
            }
        }
        if ($passageCount > 0) {
            $warnings[] = $passageCount . ' reading passage(s) were detected — comprehension questions below refer to them. Keep them as reference items or uncheck them in the review step.';
        }
        if ($noAnswer > 0) {
            $warnings[] = $noAnswer . ' question(s) have no detected correct answer — set one in the review step before importing.';
        }
        if (count($questions) >= self::MAX_QUESTIONS) {
            $warnings[] = 'Only the first ' . self::MAX_QUESTIONS . ' questions are parsed per file.';
        }

        return ['questions' => $questions, 'warnings' => $warnings];
    }

    private static function newQuestion(): array
    {
        return ['num' => null, 'question' => [], 'options' => [], 'bold' => [], 'hint' => null];
    }

    /**
     * A paragraph that is prose rather than a question, option or answer line:
     * long text, or a "[Paragraph N]" section label used in comprehension exams.
     */
    private static function isPassageParagraph(string $text): bool
    {
        if (preg_match('/^\[?\s*paragraph\s*\d+\s*\]?$/i', $text)) {
            return true;
        }
        return mb_strlen($text) >= 100;
    }

    /** Turn accumulated prose paragraphs into a Passage draft item. */
    private static function flushPassage(array &$questions, array &$passageLines): void
    {
        $joined = trim(implode("\n\n", array_map('trim', $passageLines)));
        if ($joined !== '' && (count($passageLines) >= 2 || mb_strlen($joined) >= 300)) {
            $questions[] = [
                'num' => null,
                'question' => $joined,
                'type' => 'Passage',
                'options' => ['a' => '', 'b' => '', 'c' => '', 'd' => ''],
                'correct_answer' => null,
                'confidence' => 'high',
                'note' => 'Reading passage — keep as a reference item or uncheck to skip.',
            ];
        }
        $passageLines = [];
    }

    private static function isOption(string $text): bool
    {
        return (bool) preg_match('/^\s*[A-Ea-e][.)]\s*.+/', $text);
    }

    private static function isTrueFalseOption(string $text): bool
    {
        return (bool) preg_match('/^(True|False)$/i', trim($text));
    }

    private static function answerLetter(string $text): string|null
    {
        $t = preg_replace('/^\s*\d{1,3}[.)]\s+/', '', trim($text)); // "1. Answer: B"
        if ($t === null) {
            return null;
        }
        // "Answer: True" / "The correct answer is False" → a / b
        if (preg_match('/^\s*(?:ans(?:wer)?\s*[.:=]?|correct\s*answer\s*[.:=]?\s*|the\s*correct\s*answer\s+is\s*|key\s*[.:=]?\s*)\s*(True|False)\s*\.?\s*$/i', $t, $m)) {
            return strtolower($m[1]) === 'true' ? 'a' : 'b';
        }
        // "Answer: C" / "Ans. B" / "The correct answer is A"
        if (!preg_match('/^\s*(?:ans(?:wer)?\s*[.:=]?|correct\s*answer\s*[.:=]?\s*|the\s*correct\s*answer\s+is\s*|key\s*[.:=]?\s*)\s*\(?([A-Ea-e])\)?\s*\.?\s*$/i', $t, $m)) {
            return null;
        }
        $letter = strtolower($m[1]);
        if (!in_array($letter, ['a', 'b', 'c', 'd'], true)) {
            return null;
        }
        return $letter;
    }

    /** "Answers: 1-B 2-A 3 C" → [1 => 'b', 2 => 'a', 3 => 'c']. Null when not a key block. */
    private static function answerKeyLine(string $text): ?array
    {
        if (!self::answerKeyHeader($text)) {
            return null;
        }
        $pairs = self::answerKeyPairs($text);
        return $pairs !== null && $pairs !== [] ? $pairs : null;
    }

    /** Line that announces an answer key ("Answers:", "Answer key:"). */
    private static function answerKeyHeader(string $text): bool
    {
        return (bool) preg_match('/^\s*(?:answers?\s*[.:\-=]|answer\s*key\s*[.:\-=])/i', $text);
    }

    /**
     * A line consisting of "num-letter" pairs ("1-B 2-A 3.C"). Null when the
     * line carries prose (so question text is never mistaken for a key).
     */
    private static function answerKeyPairs(string $text): ?array
    {
        if (preg_match('/[^\d\s\-.):>()A-Ea-e]/', $text)) {
            return null;
        }
        if (!preg_match_all('/\b(\d{1,3})\s*[-.)>:]\s*\(?([A-Ea-e])\)?\b/', $text, $ms, PREG_SET_ORDER)) {
            return null;
        }
        $key = [];
        foreach ($ms as $m) {
            $key[(int) $m[1]] = strtolower($m[2]);
        }
        return $key !== [] ? $key : null;
    }

    /**
     * Question-start detection. Numbered stems and headings are unambiguous;
     * un-numbered stems are accepted when followed by options — and a question
     * that already collected options can be closed by a new un-numbered stem
     * (e.g. a bare "The capital of France is:" after a completed item).
     */
    private static function isQuestionStart(array $block, ?array $next, ?array $current): bool
    {
        $text = trim((string) ($block['text'] ?? ''));

        if (preg_match('/^\s*(?:[Qq](?:uestion)?[\.:]?\s*)?\d{1,3}[.)]\s+\S/', $text)) {
            return true; // "1. …" / "1) …"
        }
        if (preg_match('/^\s*[Qq](?:uestion)?\s*\d*\s*[.:-]\s+\S/', $text)) {
            return true; // "Q1. …" / "Question 3: …"
        }
        $style = (string) ($block['style'] ?? '');
        if (preg_match('/^Heading[1-6]$/i', $style)) {
            return true;
        }
        // Word list-numbered paragraph (numId — the visible "1." is rendered, not
        // stored). A stem may also be split across two paragraphs ("Choose the
        // word…:" + the sentence), so accept it when it ends with ?/: even if the
        // next block is prose.
        if (!empty($block['numpr'])
            && ($next === null || self::looksLikeOptionBlock($next) || preg_match('/[?:]\s*$/u', $text))) {
            return true;
        }

        if ($next === null || !self::looksLikeOptionBlock($next)) {
            return false;
        }
        if ($current === null) {
            return true; // document start — a stem directly before options
        }
        $hasOpenOptions = $current['options'] !== [];
        if ($hasOpenOptions && ($block['bold'] || preg_match('/[?:]\s*$/u', $text))) {
            return true; // previous item finished; this is a new un-numbered stem
        }
        if ($block['bold'] && preg_match('/[?]\s*$/u', $text) && mb_strlen($text) < 300) {
            return true; // bold question-mark stem, options follow
        }
        return false;
    }

    private static function looksLikeOptionBlock(array $block): bool
    {
        $text = trim((string) ($block['text'] ?? ''));
        return self::isOption($text) || self::isTrueFalseOption($text);
    }

    private static function questionNumber(string $text): ?int
    {
        if (preg_match('/^\s*[Qq](?:uestion)?\s*(\d+)/', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^\s*(\d{1,3})[.)]/', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private static function stripQuestionNumber(string $text): string
    {
        $t = preg_replace('/^\s*[Qq](?:uestion)?\s*\d*\s*[.:-]\s+/', '', trim($text)); // "Q1." / "Question 3:"
        $t = preg_replace('/^\s*\d{1,3}[.)]\s+/', '', (string) $t);                    // "1." / "1)"
        return trim((string) $t);
    }

    private static function finalizeQuestion(array $c): array
    {
        $question = self::normalizeSpace(implode(' ', $c['question']));
        if ($question === '') {
            $question = '(untitled question)';
        }

        $options = $c['options'];
        $isTrueFalse = self::looksTrueFalse($question, $options);

        if ($isTrueFalse) {
            $options = ['a' => 'True', 'b' => 'False', 'c' => '', 'd' => ''];
            $type = 'True/False';
            $correct = null;
            if ($c['hint'] !== null) {
                $correct = $c['hint'] === 'b' ? 'b' : 'a';
            } elseif (isset($c['bold']['a']) || isset($c['bold']['b'])) {
                $correct = isset($c['bold']['a']) && !isset($c['bold']['b']) ? 'a'
                    : (isset($c['bold']['b']) && !isset($c['bold']['a']) ? 'b' : null);
            }
            // "(True)" / "(False)" tag inside the stem
            if ($correct === null && preg_match('/\(\s*(True|False)\s*\)/i', $question, $m)) {
                $correct = strtolower($m[1]) === 'true' ? 'a' : 'b';
            }
        } else {
            $type = 'MCQ';
            $ordered = [];
            foreach (['a', 'b', 'c', 'd'] as $letter) {
                $ordered[$letter] = trim((string) ($options[$letter] ?? ''));
            }
            $options = $ordered;
            $correct = $c['hint'];
            if ($correct === null && count($c['bold']) === 1) {
                $correct = array_key_first($c['bold']);
            }
        }

        $filled = array_filter($options, fn ($o) => $o !== '');
        $notes = [];
        $extraOption = trim((string) ($c['options']['e'] ?? ''));
        if ($extraOption !== '') {
            $notes[] = 'A 5th option (E) was found — the exam engine supports A–D only, so it was not stored.';
        }
        if ($correct === null && $type !== 'MCQ') {
            $notes[] = 'No correct answer detected.';
        }
        if ($type === 'MCQ' && count($filled) < 2) {
            $notes[] = 'Only ' . count($filled) . ' option(s) detected — check this question.';
        }
        if ($type === 'MCQ' && $correct === null) {
            $notes[] = 'No correct answer detected — set one before importing.';
        }

        $confidence = 'high';
        if ($correct !== null && $type === 'MCQ' && $c['hint'] === null) {
            $confidence = 'medium'; // inferred from formatting, not an explicit answer
        } elseif ($correct === null) {
            $confidence = 'low';
        }

        return [
            'num' => $c['num'],
            'question' => $question,
            'type' => $type,
            'options' => $options,
            'correct_answer' => $correct,
            'confidence' => $confidence,
            'note' => $notes === [] ? null : implode(' ', $notes),
        ];
    }

    private static function looksTrueFalse(string $question, array $options): bool
    {
        if (preg_match('/\(\s*True\s*\/\s*False\s*\)/i', $question)) {
            return true;
        }
        if (preg_match('/\bTrue\s+or\s+False\b/i', $question)) {
            return true;
        }
        if (count($options) === 2
            && strtolower((string) ($options['a'] ?? '')) === 'true'
            && strtolower((string) ($options['b'] ?? '')) === 'false') {
            return true;
        }
        return false;
    }

    /** Collapse all whitespace (incl. non-breaking spaces) into single spaces. */
    private static function normalizeSpace(string $text): string
    {
        $text = str_replace(["\xC2\xA0", "\xE2\x80\x89", "\xE2\x80\xAF"], ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
