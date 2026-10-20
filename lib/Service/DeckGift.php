<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\Room;
use OCP\IL10N;

/**
 * The deck as a Moodle GIFT file, and a deck from one. GIFT is the plain
 * text format of Moodle's question bank:
 *
 *   // a comment
 *   ::Planets::Which planet is the largest? {
 *     =Jupiter
 *     ~Saturn
 *     ~Earth
 *   }
 *   Water boils at 100 °C at sea level. {TRUE}
 *   Who wrote "Faust"? {=Goethe =Johann Wolfgang von Goethe}
 *   In which year did the Berlin Wall fall? {#1989:1}
 *   Match capital and country. {=Paris -> France =Berlin -> Germany}
 *   Which are prime numbers? {~%50%2 ~%50%3 ~%-100%4}
 *
 * Questions are blocks separated by blank lines; `::name::` is the
 * question's name, `{…}` its answer block, `#` after an answer its
 * feedback, `[html]` and the like a format prefix, and `\` escapes the
 * special characters ~ = # { } : and the backslash itself.
 *
 * Quiz questions map one to one — multiple choice, true/false, multiple
 * answers (as weighted answers), number guess (as a numerical answer with
 * tolerance), free text (as a short answer with its accepted answers) and
 * matching. A ranking question has no counterpart in GIFT and goes out as
 * a matching question of positions to items ("1 -> Mercury"), which Moodle
 * takes; it comes back as matching. A poll has nothing to grade, and GIFT
 * holds graded questions: the export refuses a poll, the import always
 * makes a quiz.
 *
 * Read with the same strictness as the Markdown file (DeckMarkdown): a
 * block that is not a question (a description without an answer block, an
 * essay without an answer to check, a choice without a key) is refused
 * with its number and line, and nothing is stored. Feedback, names and
 * categories are read and dropped; the question text comes back as plain
 * text on one line, with an `[html]` question's tags removed.
 */
class DeckGift {
    /** A question bank of a few hundred questions is a few hundred kilobytes; a flood guard. */
    public const MAX_BYTES = DeckMarkdown::MAX_BYTES;

    /** The question's default time in a quiz, as the composer's. */
    private const DEFAULT_TIME = 30;

    /**
     * The only percentages Moodle accepts for a weighted answer; a value
     * off this list fails the whole import on Moodle's side.
     */
    private const FRACTIONS = [100, 90, 83.33333, 80, 75, 70, 66.66667, 60, 50, 40, 33.33333, 30, 25, 20, 16.66667, 14.28571, 12.5, 11.11111, 10, 5];

    public function __construct(
        private IL10N $l10n,
    ) {
    }

    // ── Export ──────────────────────────────────────────────────────────────

    /**
     * @param Poll[] $polls the deck in order
     * @throws \InvalidArgumentException a poll room
     */
    public function export(Room $room, array $polls): string {
        if ($room->getMode() !== 'quiz') {
            throw new \InvalidArgumentException($this->l10n->t('GIFT holds graded questions; this room is a poll.'));
        }
        $out = [];
        $out[] = '// vibeARS deck' . ($room->titleOrEmpty() !== '' ? ' "' . DeckText::squashed($room->titleOrEmpty()) . '"' : '') . ' in Moodle GIFT format.';
        $out[] = '// Import it into a Moodle question bank (format: GIFT), or back into vibeARS.';
        $out[] = '// A ranking question is written as matching positions to items.';
        $n = 0;
        foreach ($polls as $poll) {
            $n++;
            $out[] = '';
            $out[] = $this->question($poll, $n);
        }
        return implode("\n", $out) . "\n";
    }

    private function question(Poll $poll, int $n): string {
        $text = DeckText::squashed($poll->getQuestion());
        $head = '::' . self::escape(mb_substr($text, 0, 80)) . '::' . self::escape($text);
        $key = $poll->getAnswerKeyArray();
        switch ($poll->getType()) {
            case 'choice':
                $lines = [];
                foreach ($poll->getOptionsArray() as $o) {
                    $lines[] = ($o['id'] === $poll->getCorrectOption() ? '=' : '~') . self::escape(DeckText::squashed($o['label']));
                }
                return $head . " {\n" . self::indent($lines) . "}";
            case 'truefalse':
                $options = $poll->getOptionsArray();
                return $head . ' {' . ((($options[0]['id'] ?? '') === $poll->getCorrectOption()) ? 'TRUE' : 'FALSE') . '}';
            case 'multi':
                $correct = array_map('strval', (array)($key['correct'] ?? []));
                $share = self::fraction(max(1, count($correct)));
                $lines = [];
                foreach ($poll->getOptionsArray() as $o) {
                    $weight = in_array((string)$o['id'], $correct, true) ? $share : '-100';
                    $lines[] = '~%' . $weight . '%' . self::escape(DeckText::squashed($o['label']));
                }
                return $head . " {\n" . self::indent($lines) . "}";
            case 'number':
                $target = DeckText::number($key['target'] ?? 0);
                $tol = DeckText::number($key['tolerance'] ?? 0);
                return $head . ' {#' . $target . ($tol !== '0' ? ':' . $tol : '') . '}';
            case 'text':
                $lines = [];
                foreach ((array)($key['accepted'] ?? []) as $answer) {
                    $lines[] = '=' . self::escape(DeckText::squashed((string)$answer));
                }
                return $head . " {\n" . self::indent($lines) . "}";
            case 'rank':
                $lines = [];
                foreach (array_values($poll->getOptionsArray()) as $i => $o) {
                    $lines[] = '=' . ($i + 1) . ' -> ' . self::escape(DeckText::squashed($o['label']));
                }
                return '// ranking: the positions are the solution' . "\n" . $head . " {\n" . self::indent($lines) . "}";
            case 'match':
                $match = $poll->getMatchConfig();
                $targetLabel = [];
                foreach ($match['targets'] as $t) {
                    $targetLabel[$t['id']] = $t['label'];
                }
                $map = (array)($key['map'] ?? []);
                $lines = [];
                foreach ($match['items'] as $i => $item) {
                    $right = isset($map[$item['id']]) && isset($targetLabel[$map[$item['id']]])
                        ? $targetLabel[$map[$item['id']]]
                        : ($match['targets'][$i]['label'] ?? '');
                    $lines[] = '=' . self::escape(DeckText::squashed($item['label'])) . ' -> ' . self::escape(DeckText::squashed($right));
                }
                return $head . " {\n" . self::indent($lines) . "}";
            default:
                // Poll types cannot occur in a quiz; a comment keeps the count honest.
                return '// question ' . $n . ' (' . $poll->getType() . ') has no GIFT equivalent and was left out';
        }
    }

    /** The share per correct answer, from Moodle's list, for k correct answers. */
    public static function fraction(int $k): string {
        $want = 100 / max(1, $k);
        $best = self::FRACTIONS[0];
        foreach (self::FRACTIONS as $f) {
            if (abs($f - $want) < abs($best - $want)) {
                $best = $f;
            }
        }
        return (string)$best;
    }

    /** @param list<string> $lines */
    private static function indent(array $lines): string {
        return $lines === [] ? '' : '  ' . implode("\n  ", $lines) . "\n";
    }

    /** Backslash before the characters GIFT reads as syntax. */
    public static function escape(string $s): string {
        return str_replace(['\\', '~', '=', '#', '{', '}', ':'], ['\\\\', '\\~', '\\=', '\\#', '\\{', '\\}', '\\:'], $s);
    }

    // ── Import ──────────────────────────────────────────────────────────────

    /**
     * Reads a GIFT file into the shape DeckImportService takes from
     * DeckMarkdown::parse: the room's settings (always a quiz with the
     * defaults) and the questions as the composer's request bodies.
     *
     * @return array{title:string, mode:string, revealAtEnd:bool, pace:string, timed:bool, feedback:string, questions:list<array<string,mixed>>}
     * @throws \InvalidArgumentException with the question's number and line
     */
    public function parse(string $content): array {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('The file is too large.'));
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new \InvalidArgumentException($this->l10n->t('The file is not UTF-8 text.'));
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $questions = [];
        foreach ($this->blocks($content) as $i => [$line, $text]) {
            $questions[] = $this->question_($text, $i + 1, $line);
        }
        if ($questions === []) {
            throw new \InvalidArgumentException($this->l10n->t('The file holds no question.'));
        }
        return [
            'title' => '',
            'mode' => 'quiz',
            'revealAtEnd' => false,
            'pace' => 'live',
            'timed' => true,
            'feedback' => 'each',
            'questions' => $questions,
        ];
    }

    /**
     * The blocks: text between blank lines, braces kept together across
     * lines. Comment lines and categories are dropped, the first line's
     * number kept for the message.
     *
     * @return list<array{0:int, 1:string}> [line, text]
     */
    private function blocks(string $content): array {
        $blocks = [];
        $current = [];
        $start = 0;
        $depth = 0;
        foreach (explode("\n", $content) as $index => $raw) {
            $no = $index + 1;
            $line = rtrim($raw);
            if ($depth === 0 && (trim($line) === '' )) {
                if ($current !== []) {
                    $blocks[] = [$start, implode("\n", $current)];
                    $current = [];
                }
                continue;
            }
            if ($depth === 0 && (str_starts_with(ltrim($line), '//') || preg_match('/^\s*\$CATEGORY:/i', $line) === 1)) {
                continue;
            }
            if ($current === []) {
                $start = $no;
            }
            $current[] = $line;
            $depth = max(0, $depth + self::braceDelta($line));
        }
        if ($current !== []) {
            $blocks[] = [$start, implode("\n", $current)];
        }
        return $blocks;
    }

    /** Opening minus closing braces on a line, escapes ignored. */
    private static function braceDelta(string $line): int {
        $delta = 0;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === '\\') {
                $i++;
                continue;
            }
            if ($c === '{') {
                $delta++;
            } elseif ($c === '}') {
                $delta--;
            }
        }
        return $delta;
    }

    /** @return array<string, mixed> the composer's request body */
    private function question_(string $block, int $number, int $line): array {
        $text = $block;
        $name = '';
        if (str_starts_with(ltrim($text), '::')) {
            $text = ltrim($text);
            $end = self::findUnescaped($text, '::', 2);
            if ($end !== -1) {
                $name = self::unescape(substr($text, 2, $end - 2));
                $text = substr($text, $end + 2);
            }
        }
        $open = self::findUnescaped($text, '{', 0);
        if ($open === -1) {
            throw $this->error($number, $line, $this->l10n->t('no answer block in braces — a description cannot be a question here.'));
        }
        $close = self::findUnescaped($text, '}', $open + 1);
        if ($close === -1) {
            throw $this->error($number, $line, $this->l10n->t('the answer block is not closed.'));
        }
        $before = substr($text, 0, $open);
        $inside = substr($text, $open + 1, $close - $open - 1);
        $after = substr($text, $close + 1);

        $question = $this->questionText($before);
        $tail = $this->questionText($after);
        if ($tail !== '') {
            // The missing-word form: "The capital of France is {=Paris ~Rome} and …".
            $question = trim($question . ' ___ ' . $tail);
        }
        if ($question === '') {
            $question = DeckText::squashed($name);
        }
        if ($question === '') {
            throw $this->error($number, $line, $this->l10n->t('the question has no text.'));
        }

        $body = array_merge(['question' => $question, 'timeLimit' => self::DEFAULT_TIME], $this->answers(trim($inside), $number, $line));
        return $body;
    }

    /** The text of a question: format prefix off, HTML stripped, unescaped, one line. */
    private function questionText(string $raw): string {
        $raw = trim($raw);
        if (preg_match('/^\[(html|markdown|plain|moodle)\]\s*/i', $raw, $m) === 1) {
            $raw = substr($raw, strlen($m[0]));
            if (strtolower($m[1]) === 'html') {
                $raw = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', ' ', $raw) ?? $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return DeckText::squashed(self::unescape($raw));
    }

    /**
     * The answer block → the type and its fields.
     *
     * @return array<string, mixed>
     */
    private function answers(string $inside, int $number, int $line): array {
        if ($inside === '') {
            throw $this->error($number, $line, $this->l10n->t('an essay has no answer to check — give at least one accepted answer.'));
        }
        if (preg_match('/^(T|TRUE|F|FALSE)\s*(#.*)?$/is', $inside, $m) === 1) {
            return ['type' => 'truefalse', 'correctIndex' => strtoupper($m[1][0]) === 'T' ? 0 : 1];
        }
        if (str_starts_with($inside, '#')) {
            return $this->numerical(substr($inside, 1), $number, $line);
        }
        $entries = $this->entries($inside);
        if ($entries === []) {
            throw $this->error($number, $line, $this->l10n->t('the answer block holds no answer — answers start with = or ~.'));
        }
        $markers = array_column($entries, 'marker');
        $texts = array_column($entries, 'text');
        $allEqual = !in_array('~', $markers, true);
        if ($allEqual && count(array_filter($texts, static fn (string $t): bool => self::findUnescaped($t, '->', 0) !== -1)) === count($texts)) {
            $pairs = [];
            foreach ($texts as $t) {
                $arrow = self::findUnescaped($t, '->', 0);
                $pairs[] = ['left' => DeckText::squashed(self::unescape(substr($t, 0, $arrow))), 'right' => DeckText::squashed(self::unescape(substr($t, $arrow + 2)))];
            }
            return ['type' => 'match', 'pairs' => $pairs];
        }
        $labels = array_map(static fn (string $t): string => DeckText::squashed(self::unescape($t)), $texts);
        if ($allEqual) {
            return ['type' => 'text', 'answers' => $labels];
        }
        $weighted = count(array_filter($entries, static fn (array $e): bool => $e['weight'] !== null)) > 0;
        $equals = array_keys(array_filter($markers, static fn (string $m): bool => $m === '='));
        if (!$weighted && count($equals) === 1) {
            return ['type' => 'choice', 'options' => $labels, 'correctIndex' => $equals[0]];
        }
        // Weighted answers, or several keys: multiple answers; a key is "=" or a positive weight.
        $correct = [];
        foreach ($entries as $i => $e) {
            if ($e['marker'] === '=' || ($e['weight'] !== null && $e['weight'] > 0)) {
                $correct[] = $i;
            }
        }
        if ($correct === []) {
            throw $this->error($number, $line, $this->l10n->t('no answer is marked correct (= or a positive weight).'));
        }
        return ['type' => 'multi', 'options' => $labels, 'correctIndexes' => $correct];
    }

    /**
     * A numerical answer: "1989:1", "1989", "1..5", or several "=1989:1 =1990",
     * the first of which counts.
     *
     * @return array<string, mixed>
     */
    private function numerical(string $body, int $number, int $line): array {
        $first = trim($body);
        if (str_starts_with($first, '=')) {
            $first = substr($first, 1);
        }
        $cut = self::findUnescaped($first, '=', 0);
        if ($cut !== -1) {
            $first = substr($first, 0, $cut);
        }
        $hash = self::findUnescaped($first, '#', 0);
        if ($hash !== -1) {
            $first = substr($first, 0, $hash);
        }
        $first = trim(preg_replace('/^%-?[\d.]+%/', '', trim($first)) ?? $first);
        $num = '[-+]?\d+(?:[.,]\d+)?';
        if (preg_match('/^(' . $num . ')\s*:\s*(' . $num . ')$/', $first, $m) === 1) {
            return ['type' => 'number', 'target' => self::toNumber($m[1]), 'tolerance' => self::toNumber($m[2])];
        }
        if (preg_match('/^(' . $num . ')\s*\.\.\s*(' . $num . ')$/', $first, $m) === 1) {
            $lo = self::toNumber($m[1]);
            $hi = self::toNumber($m[2]);
            return ['type' => 'number', 'target' => ($lo + $hi) / 2, 'tolerance' => abs($hi - $lo) / 2];
        }
        if (preg_match('/^(' . $num . ')$/', $first, $m) === 1) {
            return ['type' => 'number', 'target' => self::toNumber($m[1]), 'tolerance' => 0];
        }
        throw $this->error($number, $line, $this->l10n->t('the numerical answer is written as "#1989:1" (target:tolerance) or "#1..5".'));
    }

    private static function toNumber(string $s): int|float {
        $s = str_replace(',', '.', $s);
        return str_contains($s, '.') ? (float)$s : (int)$s;
    }

    /**
     * The answers of a block: each starts with an unescaped = or ~, may carry
     * a %weight% and ends at the next marker; feedback after # is dropped.
     *
     * @return list<array{marker:string, weight:?float, text:string}>
     */
    private function entries(string $inside): array {
        $entries = [];
        $len = strlen($inside);
        $i = 0;
        $current = null;
        while ($i < $len) {
            $c = $inside[$i];
            if ($c === '\\') {
                if ($current !== null) {
                    $current['raw'] .= substr($inside, $i, 2);
                }
                $i += 2;
                continue;
            }
            if ($c === '=' || $c === '~') {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['marker' => $c, 'raw' => ''];
                $i++;
                continue;
            }
            if ($current !== null) {
                $current['raw'] .= $c;
            }
            $i++;
        }
        if ($current !== null) {
            $entries[] = $current;
        }
        $out = [];
        foreach ($entries as $e) {
            $raw = ltrim($e['raw']);
            $weight = null;
            if (preg_match('/^%(-?\d+(?:\.\d+)?)%/', $raw, $m) === 1) {
                $weight = (float)$m[1];
                $raw = substr($raw, strlen($m[0]));
            }
            $hash = self::findUnescaped($raw, '#', 0);
            if ($hash !== -1) {
                $raw = substr($raw, 0, $hash);
            }
            $out[] = ['marker' => $e['marker'], 'weight' => $weight, 'text' => trim($raw)];
        }
        return $out;
    }

    /** Position of $needle in $s from $from on, skipping escaped characters; -1 if absent. */
    public static function findUnescaped(string $s, string $needle, int $from): int {
        $len = strlen($s);
        $n = strlen($needle);
        for ($i = $from; $i <= $len - $n; $i++) {
            if ($s[$i] === '\\') {
                $i++;
                continue;
            }
            if (substr($s, $i, $n) === $needle) {
                return $i;
            }
        }
        return -1;
    }

    /** The text as the author typed it: the backslash before a special character goes. */
    public static function unescape(string $s): string {
        return preg_replace('/\\\\([\\\\~=#{}:])/', '$1', $s) ?? $s;
    }

    private function error(int $number, int $line, string $what): \InvalidArgumentException {
        return new \InvalidArgumentException($this->l10n->t('Question %1$d (line %2$d): %3$s', [$number, $line, $what]));
    }
}
