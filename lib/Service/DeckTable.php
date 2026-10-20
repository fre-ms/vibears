<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\Room;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * The deck as a table: one question per row, the columns of Kahoot!'s
 * quiz spreadsheet template — Question, Answer 1 … Answer N, Time limit,
 * Correct answer(s) — plus an optional Type column for everything the
 * template cannot say. Read from a CSV file or from an .xlsx workbook
 * (the template itself, or any sheet with those headers), written as CSV.
 *
 *   Type,Question,Answer 1,Answer 2,Answer 3,Answer 4,Correct answer(s),Time limit (sec)
 *   choice,Which planet is the largest?,Jupiter,Saturn,Earth,,1,20
 *   multi,Which are prime numbers?,2,3,4,9,"1,2",30
 *   truefalse,Water boils at 100 °C at sea level.,True,False,,,1,15
 *   number,In which year did the Berlin Wall fall?,1989,1,,,,30
 *   text,"Who wrote ""Faust""?",Goethe,Johann Wolfgang von Goethe,,,,60
 *   rank,Order the planets.,Mercury,Venus,Earth,,,45
 *   match,Match capital and country.,Paris -> France,Berlin -> Germany,,,,60
 *
 * The header row is found by its "Question" column, wherever it sits —
 * Kahoot!'s template has seven rows of instructions above it — and the
 * other columns by their headers (German ones too). Without a Type column
 * the type is inferred the way the template means it: one correct answer
 * is multiple choice, several are multiple answers, "True"/"False" is
 * true/false. Correct answers are numbers (1, 3), letters (A, C) or the
 * answer's text. A sheet without any correct answer is a poll. Rows that
 * are empty apart from the template's filler are skipped; a row that is a
 * question but cannot be stored is refused with its row number, and
 * nothing is stored.
 *
 * The .xlsx reader is deliberately small: the first sheet of the workbook,
 * shared and inline strings, numbers — what a template or an export
 * carries. No formulas are evaluated, nothing is loaded from outside the
 * file, and every part is capped.
 */
class DeckTable {
    /** A CSV deck: the same flood guard as the Markdown file. */
    public const MAX_TEXT_BYTES = DeckMarkdown::MAX_BYTES;
    /** An .xlsx file; the template is a few tens of kilobytes. */
    public const MAX_XLSX_BYTES = 10 * 1024 * 1024;
    private const MAX_PART_BYTES = 20 * 1024 * 1024;
    private const MAX_ENTRIES = 200;
    private const DEFAULT_TIME = 30;
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const QUIZ_ONLY = ['truefalse', 'multi', 'number', 'text'];
    private const POLL_ONLY = ['words', 'scale'];

    /** Type names as people write them, lower-cased => the type. */
    private const TYPES = [
        'choice' => 'choice', 'multiple choice' => 'choice', 'mc' => 'choice', 'single' => 'choice', 'single choice' => 'choice', 'quiz' => 'choice', 'einfachauswahl' => 'choice',
        'truefalse' => 'truefalse', 'true/false' => 'truefalse', 'true false' => 'truefalse', 'tf' => 'truefalse', 'boolean' => 'truefalse', 'richtig/falsch' => 'truefalse', 'wahr/falsch' => 'truefalse',
        'multi' => 'multi', 'multiple answers' => 'multi', 'multiple' => 'multi', 'multi-select' => 'multi', 'mehrfachauswahl' => 'multi', 'mehrfachantwort' => 'multi',
        'number' => 'number', 'numerical' => 'number', 'numeric' => 'number', 'zahl' => 'number', 'schätzung' => 'number', 'schaetzung' => 'number',
        'text' => 'text', 'free text' => 'text', 'freitext' => 'text', 'short answer' => 'text', 'type answer' => 'text', 'open' => 'text',
        'rank' => 'rank', 'ranking' => 'rank', 'order' => 'rank', 'ordering' => 'rank', 'puzzle' => 'rank', 'reihenfolge' => 'rank', 'rangfolge' => 'rank',
        'match' => 'match', 'matching' => 'match', 'zuordnung' => 'match',
        'words' => 'words', 'word cloud' => 'words', 'wordcloud' => 'words', 'wortwolke' => 'words',
        'scale' => 'scale', 'skala' => 'scale', 'slider' => 'scale',
    ];

    public function __construct(
        private ITempManager $tempManager,
        private IL10N $l10n,
    ) {
    }

    // ── Detection ───────────────────────────────────────────────────────────

    /**
     * A text that is a table: its first line that is not a GIFT comment
     * holds a separator and a "Question" header, and no GIFT answer block.
     */
    public static function looksLikeCsv(string $content): bool {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        foreach (preg_split('/\r\n|\r|\n/', $content, 40) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '$CATEGORY')) {
                continue;
            }
            return !str_contains($line, '{') && preg_match('/question|frage/i', $line) === 1 && preg_match('/[,;\t]/', $line) === 1;
        }
        return false;
    }

    /** A ZIP that is a workbook: it holds xl/workbook.xml. */
    public function isXlsx(string $content): bool {
        if (!DeckArchive::isArchive($content) || strlen($content) > self::MAX_XLSX_BYTES) {
            return false;
        }
        $path = $this->tempFile();
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        $isXlsx = $zip->open($path, \ZipArchive::RDONLY) === true;
        if ($isXlsx) {
            $isXlsx = $zip->locateName('xl/workbook.xml') !== false;
            $zip->close();
        }
        @unlink($path);
        return $isXlsx;
    }

    // ── CSV ─────────────────────────────────────────────────────────────────

    /**
     * @return array{title:string, mode:string, revealAtEnd:bool, pace:string, timed:bool, feedback:string, questions:list<array<string,mixed>>}
     * @throws \InvalidArgumentException
     */
    public function parseCsv(string $content): array {
        if (strlen($content) > self::MAX_TEXT_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('The file is too large.'));
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new \InvalidArgumentException($this->l10n->t('The file is not UTF-8 text.'));
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $separator = self::separator($content);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $rows = [];
        $no = 0;
        while (($cells = fgetcsv($fh, 0, $separator, '"', '')) !== false) {
            $no++;
            $rows[$no] = array_map(static fn ($c): string => (string)$c, $cells);
        }
        fclose($fh);
        return $this->deckFromRows($rows);
    }

    /** The separator the header line uses most: comma, semicolon or tab. */
    private static function separator(string $content): string {
        $first = '';
        foreach (explode("\n", $content, 50) as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }
        $outside = preg_replace('/"[^"]*"/', '', $first) ?? $first;
        $counts = [',' => substr_count($outside, ','), ';' => substr_count($outside, ';'), "\t" => substr_count($outside, "\t")];
        arsort($counts);
        return array_key_first($counts) ?? ',';
    }

    // ── XLSX ────────────────────────────────────────────────────────────────

    /**
     * The first sheet of a workbook, the template's or any with the same headers.
     *
     * @return array{title:string, mode:string, revealAtEnd:bool, pace:string, timed:bool, feedback:string, questions:list<array<string,mixed>>}
     * @throws \InvalidArgumentException
     */
    public function parseXlsx(string $content): array {
        if (strlen($content) > self::MAX_XLSX_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('The file is too large.'));
        }
        $path = $this->tempFile();
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            @unlink($path);
            throw new \InvalidArgumentException($this->l10n->t('The file is not a readable workbook.'));
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new \InvalidArgumentException($this->l10n->t('The workbook holds too many parts.'));
            }
            $strings = $this->sharedStrings($zip);
            $sheetXml = $this->part($zip, $this->firstSheetPath($zip));
            return $this->deckFromRows($this->cells($sheetXml, $strings));
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** A part of the workbook, capped; '' when absent. */
    private function part(\ZipArchive $zip, string $name): string {
        $index = $zip->locateName($name);
        if ($index === false) {
            return '';
        }
        $data = $zip->getFromIndex($index, self::MAX_PART_BYTES + 1);
        if ($data === false || strlen($data) > self::MAX_PART_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('The workbook part %s is too large.', [$name]));
        }
        return $data;
    }

    private function xml(string $data, string $what): \SimpleXMLElement {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($data, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \InvalidArgumentException($this->l10n->t('The workbook part %s could not be read.', [$what]));
        }
        return $xml;
    }

    /** @return list<string> */
    private function sharedStrings(\ZipArchive $zip): array {
        $data = $this->part($zip, 'xl/sharedStrings.xml');
        if ($data === '') {
            return [];
        }
        $xml = $this->xml($data, 'xl/sharedStrings.xml');
        $out = [];
        foreach ($xml->children(self::MAIN_NS)->si as $si) {
            $out[] = self::textOf($si);
        }
        return $out;
    }

    /** Every <t> below a string item, rich-text runs included. */
    private static function textOf(\SimpleXMLElement $node): string {
        $text = '';
        foreach ($node->children(self::MAIN_NS) as $child) {
            $name = $child->getName();
            if ($name === 't') {
                $text .= (string)$child;
            } elseif ($name === 'r' || $name === 'rPh') {
                if ($name === 'r') {
                    $text .= self::textOf($child);
                }
            }
        }
        return $text;
    }

    /** The path of the workbook's first sheet, by its relationship; the usual name if that fails. */
    private function firstSheetPath(\ZipArchive $zip): string {
        $fallback = 'xl/worksheets/sheet1.xml';
        $workbook = $this->part($zip, 'xl/workbook.xml');
        $rels = $this->part($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook === '' || $rels === '') {
            return $fallback;
        }
        $wb = $this->xml($workbook, 'xl/workbook.xml');
        $sheets = $wb->children(self::MAIN_NS)->sheets;
        $first = $sheets ? $sheets->children(self::MAIN_NS)->sheet[0] ?? null : null;
        if ($first === null) {
            return $fallback;
        }
        $rid = (string)($first->attributes(self::REL_NS)['id'] ?? '');
        if ($rid === '') {
            return $fallback;
        }
        foreach ($this->xml($rels, 'xl/_rels/workbook.xml.rels')->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $target = (string)$rel['Target'];
                return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
        }
        return $fallback;
    }

    /**
     * The sheet's cells as text: row number => cells by column index (0 = A).
     *
     * @param list<string> $strings the shared strings
     * @return array<int, array<int, string>>
     */
    private function cells(string $sheetXml, array $strings): array {
        if ($sheetXml === '') {
            throw new \InvalidArgumentException($this->l10n->t('The workbook has no sheet.'));
        }
        $xml = $this->xml($sheetXml, 'the sheet');
        $rows = [];
        $sheetData = $xml->children(self::MAIN_NS)->sheetData;
        if (!$sheetData) {
            return $rows;
        }
        $rowNo = 0;
        foreach ($sheetData->children(self::MAIN_NS)->row as $row) {
            // The elements come through children(namespace), so a plain
            // $row['r'] would look for a namespaced attribute; attributes()
            // without an argument reads the ones without a namespace.
            $rowNo = (int)(self::attr($row, 'r') ?: ($rowNo + 1));
            $cells = [];
            $col = 0;
            foreach ($row->children(self::MAIN_NS)->c as $c) {
                $ref = self::attr($c, 'r');
                if (preg_match('/^([A-Z]+)/', $ref, $m) === 1) {
                    $col = self::columnIndex($m[1]);
                }
                $type = self::attr($c, 't');
                $children = $c->children(self::MAIN_NS);
                $v = isset($children->v) ? (string)$children->v : '';
                $value = match ($type) {
                    's' => $strings[(int)$v] ?? '',
                    'inlineStr' => isset($children->is) ? self::textOf($children->is) : '',
                    'b' => $v === '1' ? 'TRUE' : 'FALSE',
                    default => $v,
                };
                $cells[$col] = trim($value);
                $col++;
            }
            $rows[$rowNo] = $cells;
        }
        return $rows;
    }

    /** An attribute without a namespace, '' when absent. */
    private static function attr(\SimpleXMLElement $el, string $name): string {
        $attributes = $el->attributes();
        return $attributes === null ? '' : (string)($attributes[$name] ?? '');
    }

    /** "A" → 0, "Z" → 25, "AA" → 26. */
    public static function columnIndex(string $letters): int {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    // ── Rows → deck ─────────────────────────────────────────────────────────

    /**
     * @param array<int, array<int, string>> $rows row number => cells (0-based columns)
     * @return array{title:string, mode:string, revealAtEnd:bool, pace:string, timed:bool, feedback:string, questions:list<array<string,mixed>>}
     * @throws \InvalidArgumentException
     */
    private function deckFromRows(array $rows): array {
        ksort($rows);
        $head = null;
        $columns = null;
        foreach ($rows as $no => $cells) {
            $columns = self::columnsOf($cells);
            if ($columns !== null) {
                $head = $no;
                break;
            }
        }
        if ($head === null || $columns === null) {
            throw new \InvalidArgumentException($this->l10n->t('No header row found: the first row names the columns, "Question" and "Answer 1" among them.'));
        }

        $parsed = [];
        foreach ($rows as $no => $cells) {
            if ($no <= $head) {
                continue;
            }
            $cell = static fn (?int $i): string => $i === null ? '' : trim($cells[$i] ?? '');
            $question = $cell($columns['question']);
            $answers = [];
            foreach ($columns['answers'] as $i) {
                $a = $cell($i);
                if ($a !== '') {
                    $answers[] = $a;
                }
            }
            $correctRaw = $cell($columns['correct']);
            $typeRaw = $cell($columns['type']);
            if ($question === '' && $answers === [] && $correctRaw === '' && $typeRaw === '') {
                continue; // the template's filler rows, a trailing empty line
            }
            if ($question === '') {
                throw $this->rowError($no, $this->l10n->t('the question is empty.'));
            }
            $type = '';
            if ($typeRaw !== '') {
                $type = self::TYPES[strtolower($typeRaw)] ?? '';
                if ($type === '') {
                    throw $this->rowError($no, $this->l10n->t('unknown type "%s".', [$typeRaw]));
                }
            }
            if ($type === 'truefalse' && $answers === []) {
                $answers = ['True', 'False']; // the template's two, so that the key can name one
            }
            $parsed[] = [
                'no' => $no,
                'question' => DeckText::squashed($question),
                'answers' => array_map([DeckText::class, 'squashed'], $answers),
                'correct' => $this->correctIndexes($correctRaw, $answers, $no),
                'type' => $type,
                'time' => $this->time($cell($columns['time']), $no),
            ];
        }
        if ($parsed === []) {
            throw new \InvalidArgumentException($this->l10n->t('The file holds no question.'));
        }

        // A sheet with a correct answer anywhere, or a type a poll cannot hold, is a quiz.
        $quiz = false;
        foreach ($parsed as $p) {
            if ($p['correct'] !== [] || in_array($p['type'], self::QUIZ_ONLY, true)) {
                $quiz = true;
                break;
            }
        }
        $questions = [];
        foreach ($parsed as $p) {
            $questions[] = $this->body($p, $quiz);
        }
        return [
            'title' => '',
            'mode' => $quiz ? 'quiz' : 'poll',
            'revealAtEnd' => false,
            'pace' => 'live',
            'timed' => true,
            'feedback' => 'each',
            'questions' => $questions,
        ];
    }

    /**
     * The columns from a candidate header row, null if it is none.
     *
     * @param array<int, string> $cells
     * @return ?array{question:int, answers:list<int>, correct:?int, time:?int, type:?int}
     */
    private static function columnsOf(array $cells): ?array {
        $question = null;
        $answers = [];
        $correct = null;
        $time = null;
        $type = null;
        foreach ($cells as $i => $raw) {
            $h = strtolower(trim($raw));
            if ($h === '') {
                continue;
            }
            if (preg_match('/^(answer|antwort|option)\s*#?\s*(\d+)/', $h, $m) === 1) {
                $answers[(int)$m[2]] = $i;
            } elseif ($question === null && preg_match('/question|frage/', $h) === 1) {
                $question = $i;
            } elseif ($correct === null && preg_match('/correct|richtig|lösung|loesung|key/', $h) === 1) {
                $correct = $i;
            } elseif ($time === null && preg_match('/time|zeit|sec/', $h) === 1) {
                $time = $i;
            } elseif ($type === null && preg_match('/^(type|typ|fragetyp)\b/', $h) === 1) {
                $type = $i;
            }
        }
        if ($question === null || $answers === []) {
            return null;
        }
        ksort($answers);
        return ['question' => $question, 'answers' => array_values($answers), 'correct' => $correct, 'time' => $time, 'type' => $type];
    }

    /**
     * "1,3", "A C", "2;4" or the answers' texts → 0-based indexes.
     *
     * @param list<string> $answers
     * @return list<int>
     */
    private function correctIndexes(string $raw, array $answers, int $no): array {
        if (trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s*[,;|]\s*|\s+/', trim($raw)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $token) === 1) {
                $i = (int)$token - 1;
            } elseif (preg_match('/^[A-Ha-h]$/', $token) === 1) {
                $i = ord(strtoupper($token)) - 65;
            } else {
                $i = -1;
                foreach ($answers as $k => $a) {
                    if (mb_strtolower($a) === mb_strtolower($token)) {
                        $i = $k;
                        break;
                    }
                }
            }
            if ($i < 0 || $i >= count($answers)) {
                throw $this->rowError($no, $this->l10n->t('"%s" is not one of the answers.', [$token]));
            }
            if (!in_array($i, $out, true)) {
                $out[] = $i;
            }
        }
        return $out;
    }

    /** The time limit: empty = the default, 0 = none, else seconds (clamped later). */
    private function time(string $raw, int $no): int {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return self::DEFAULT_TIME;
        }
        if (in_array($raw, ['none', 'no', 'off', 'unlimited', '∞', 'kein', 'keins', 'ohne'], true)) {
            return DeckService::NO_LIMIT;
        }
        if (preg_match('/^(\d{1,4})\s*(s|sec|secs|seconds?|sek)?$/', $raw, $m) === 1) {
            return (int)$m[1];
        }
        throw $this->rowError($no, $this->l10n->t('the time limit is a number of seconds.'));
    }

    /**
     * One parsed row → the composer's request body.
     *
     * @param array{no:int, question:string, answers:list<string>, correct:list<int>, type:string, time:int} $p
     * @return array<string, mixed>
     */
    private function body(array $p, bool $quiz): array {
        $no = $p['no'];
        $answers = $p['answers'];
        $correct = $p['correct'];
        $type = $p['type'];
        if ($type === '') {
            $type = self::inferType($answers, $correct);
        }
        if ($quiz && in_array($type, self::POLL_ONLY, true)) {
            throw $this->rowError($no, $this->l10n->t('a "%s" question cannot be in a quiz — the sheet has correct answers, so it is one.', [$type]));
        }
        if (!$quiz && in_array($type, self::QUIZ_ONLY, true)) {
            // Cannot happen: such a type makes the sheet a quiz. Kept as a guard.
            throw $this->rowError($no, $this->l10n->t('a "%s" question needs a quiz.', [$type]));
        }
        $body = ['type' => $type, 'question' => $p['question']];
        if ($quiz) {
            $body['timeLimit'] = $p['time'];
        }
        switch ($type) {
            case 'choice':
                $body['options'] = $answers;
                if ($quiz) {
                    if (count($correct) !== 1) {
                        throw $this->rowError($no, $correct === []
                            ? $this->l10n->t('no correct answer — the sheet is a quiz, so every question needs one.')
                            : $this->l10n->t('several correct answers — use the type "multi".'));
                    }
                    $body['correctIndex'] = $correct[0];
                }
                break;
            case 'multi':
                $body['options'] = $answers;
                $body['correctIndexes'] = $correct;
                break;
            case 'truefalse':
                // The template writes "True"/"False" as answers; the key names one of them.
                $idx = $correct[0] ?? -1;
                $picked = $idx >= 0 ? mb_strtolower($answers[$idx] ?? '') : '';
                if (in_array($picked, ['true', 'wahr', 'richtig', 'ja', 'yes', 't'], true)) {
                    $body['correctIndex'] = 0;
                } elseif (in_array($picked, ['false', 'falsch', 'nein', 'no', 'f'], true)) {
                    $body['correctIndex'] = 1;
                } else {
                    throw $this->rowError($no, $this->l10n->t('mark True or False as correct.'));
                }
                break;
            case 'number':
                $body['target'] = self::number($answers[0] ?? '');
                $body['tolerance'] = self::number($answers[1] ?? '0');
                break;
            case 'text':
                $body['answers'] = $answers;
                break;
            case 'rank':
                $body['options'] = $answers;
                break;
            case 'match':
                $pairs = [];
                foreach ($answers as $a) {
                    $parts = preg_split('/\s*(?:→|->|=>)\s*/u', $a, 2);
                    if ($parts === false || count($parts) !== 2) {
                        throw $this->rowError($no, $this->l10n->t('a pair is written as "left -> right".'));
                    }
                    $pairs[] = ['left' => $parts[0], 'right' => $parts[1]];
                }
                $body['pairs'] = $pairs;
                break;
            case 'words':
                $body['maxWords'] = $answers === [] ? 3 : (int)$answers[0];
                break;
            case 'scale':
                $body['scaleMode'] = 'single';
                $body['scaleMax'] = $answers === [] ? 5 : (int)$answers[0];
                $body['minLabel'] = $answers[1] ?? '';
                $body['maxLabel'] = $answers[2] ?? '';
                break;
        }
        return $body;
    }

    /** @param list<string> $answers @param list<int> $correct */
    private static function inferType(array $answers, array $correct): string {
        if (count($answers) === 2 && count($correct) <= 1) {
            $a = mb_strtolower($answers[0]);
            $b = mb_strtolower($answers[1]);
            $tf = [['true', 'false'], ['wahr', 'falsch'], ['richtig', 'falsch'], ['yes', 'no'], ['ja', 'nein']];
            foreach ($tf as [$t, $f]) {
                if (($a === $t && $b === $f) || ($a === $f && $b === $t)) {
                    return 'truefalse';
                }
            }
        }
        return count($correct) > 1 ? 'multi' : 'choice';
    }

    private static function number(string $s): int|float|string {
        $s = str_replace(',', '.', trim($s));
        if (preg_match('/^[-+]?\d+$/', $s) === 1) {
            return (int)$s;
        }
        if (preg_match('/^[-+]?\d*\.\d+$/', $s) === 1) {
            return (float)$s;
        }
        return $s; // DeckService says what is wrong with it
    }

    private function rowError(int $no, string $what): \InvalidArgumentException {
        return new \InvalidArgumentException($this->l10n->t('Row %1$d: %2$s', [$no, $what]));
    }

    // ── Export ──────────────────────────────────────────────────────────────

    /**
     * The deck as CSV with the columns above, comma-separated with a BOM.
     * Every question goes out; a spectrum or compass scale keeps its type
     * and question only.
     *
     * @param Poll[] $polls the deck in order
     */
    public function export(Room $room, array $polls): string {
        $quiz = $room->getMode() === 'quiz';
        $rows = [];
        $width = 4;
        foreach ($polls as $poll) {
            $rows[] = $this->row($poll, $quiz);
            $width = max($width, count($rows[array_key_last($rows)]['answers']));
        }
        $head = ['Type', 'Question'];
        for ($i = 1; $i <= $width; $i++) {
            $head[] = 'Answer ' . $i;
        }
        $head[] = 'Correct answer(s)';
        $head[] = 'Time limit (sec)';
        $out = [$head];
        foreach ($rows as $r) {
            $line = [$r['type'], $r['question']];
            for ($i = 0; $i < $width; $i++) {
                $line[] = $r['answers'][$i] ?? '';
            }
            $line[] = $r['correct'];
            $line[] = $r['time'];
            $out[] = $line;
        }
        return CsvFormat::document($out, ',', quoteOnlyWhenNeeded: true);
    }

    /** @return array{type:string, question:string, answers:list<string>, correct:string, time:string} */
    private function row(Poll $poll, bool $quiz): array {
        $key = $poll->getAnswerKeyArray();
        // The options JSON is a list of {id, label} for choice, true/false,
        // multi and rank only; a scale or matching keeps its own structure there.
        $options = in_array($poll->getType(), ['choice', 'truefalse', 'multi', 'rank'], true)
            ? array_values(array_filter($poll->getOptionsArray(), 'is_array'))
            : [];
        $labels = array_map(static fn (array $o): string => DeckText::squashed((string)($o['label'] ?? '')), $options);
        $answers = [];
        $correct = '';
        switch ($poll->getType()) {
            case 'choice':
                $answers = $labels;
                foreach ($options as $i => $o) {
                    if ($quiz && $o['id'] === $poll->getCorrectOption()) {
                        $correct = (string)($i + 1);
                    }
                }
                break;
            case 'truefalse':
                $answers = ['True', 'False'];
                $correct = (($options[0]['id'] ?? '') === $poll->getCorrectOption()) ? '1' : '2';
                break;
            case 'multi':
                $answers = $labels;
                $ids = array_map('strval', (array)($key['correct'] ?? []));
                $picked = [];
                foreach ($options as $i => $o) {
                    if (in_array((string)$o['id'], $ids, true)) {
                        $picked[] = $i + 1;
                    }
                }
                $correct = implode(',', $picked);
                break;
            case 'number':
                $answers = [DeckText::number($key['target'] ?? 0), DeckText::number($key['tolerance'] ?? 0)];
                break;
            case 'text':
                $answers = array_map(static fn ($a): string => DeckText::squashed((string)$a), (array)($key['accepted'] ?? []));
                break;
            case 'rank':
                $answers = $labels;
                break;
            case 'match':
                $match = $poll->getMatchConfig();
                $targetLabel = [];
                foreach ($match['targets'] as $t) {
                    $targetLabel[$t['id']] = $t['label'];
                }
                $map = (array)($key['map'] ?? []);
                foreach ($match['items'] as $i => $item) {
                    $right = isset($map[$item['id']]) && isset($targetLabel[$map[$item['id']]])
                        ? $targetLabel[$map[$item['id']]]
                        : ($match['targets'][$i]['label'] ?? '');
                    $answers[] = DeckText::squashed($item['label']) . ' -> ' . DeckText::squashed($right);
                }
                break;
            case 'words':
                $answers = [(string)$poll->getMaxWords()];
                break;
            case 'scale':
                $scale = $poll->getScaleConfig();
                if (($scale['mode'] ?? 'single') === 'single') {
                    $answers = [(string)$scale['max'], DeckText::squashed($scale['minLabel'] ?? ''), DeckText::squashed($scale['maxLabel'] ?? '')];
                }
                break;
        }
        return [
            'type' => $poll->getType(),
            'question' => DeckText::squashed($poll->getQuestion()),
            'answers' => $answers,
            'correct' => $correct,
            'time' => $quiz ? (string)$poll->getTimeLimit() : '',
        ];
    }

    private function tempFile(): string {
        $path = $this->tempManager->getTemporaryFile('.xlsx');
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('vibeARS: no temporary file for the workbook');
        }
        return $path;
    }
}
