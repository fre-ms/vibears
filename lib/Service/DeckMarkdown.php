<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\Room;
use OCP\IL10N;

/**
 * The deck file: a room's questions as Markdown with a YAML header, written
 * by the export and read by the import. Pure text work without a database,
 * so that export and parse are tested side by side (DeckMarkdownTest); the
 * import itself (room, questions, caps) is DeckImportService.
 *
 * The format (version vibears/1), as the export writes it:
 *
 *   ---
 *   format: "vibears/1"
 *   title: "Lecture 3"
 *   mode: "quiz"                 poll | quiz
 *   revealAtEnd: false           quiz only, like the deck menu's switch
 *   pace: "live"                 quiz only: live (moderated) | self (self-paced)
 *   timed: true                  quiz only: the self-paced "Timer" default
 *   feedback: "each"             quiz only: each | end
 *   ---
 *
 *   ## Which planet is the largest?       one question per level-2 heading
 *   type: choice                          the question type (see TYPES)
 *   time: 20                              quiz only: seconds, or "none"
 *   - [x] Jupiter                         options as a list; [x] = correct
 *   - [ ] Saturn
 *
 * Per type: choice/multi take a list ([x] marks the correct option(s) in a
 * quiz), rank an ordered list (the order is the solution in a quiz), match
 * a list of "left → right" pairs, text a list of accepted answers, number
 * `target` and `tolerance`, truefalse `answer: true|false`, words `maxWords`,
 * scale `scaleMode` (single: `scaleMax`, `minLabel`, `maxLabel`; spectrum:
 * `scaleMax` and a list of "aspect | low pole | high pole"; compass: `range`,
 * `axisX`/`axisY` as "title | low | high", the four `corner…` labels and
 * `heatmapThreshold`). Settings are "key: value" lines; a list item may be
 * "-", "*", "+" or "1." — any list style for any type, only the order and
 * the [x] marks count. HTML comments are ignored. Images are not part of
 * the file.
 *
 * The header is a flat YAML mapping (scalars only; quoted strings with the
 * usual escapes, booleans, numbers) — enough for these keys, and read by a
 * small parser of its own rather than a YAML library the server does not
 * ship. parse() returns request bodies exactly as the composer sends them,
 * so DeckService::addPoll does the validation and the import can never
 * store what the composer could not.
 */
class DeckMarkdown {
    public const FORMAT = 'vibears/1';
    /** A deck of 100 questions is a few tens of kilobytes; this is a flood guard. */
    public const MAX_BYTES = 1_048_576;

    private const TYPES = ['choice', 'truefalse', 'multi', 'number', 'text', 'rank', 'match', 'words', 'scale'];

    /**
     * Known "key: value" settings below a heading, lower-cased => canonical.
     * A key outside this list is an error (a typo would otherwise silently
     * fall back to a default); a known key that does not apply to the
     * question's type is ignored.
     */
    private const KEYS = [
        'type' => 'type', 'time' => 'time', 'answer' => 'answer',
        'target' => 'target', 'tolerance' => 'tolerance', 'maxwords' => 'maxWords',
        'scalemode' => 'scaleMode', 'scalemax' => 'scaleMax', 'minlabel' => 'minLabel', 'maxlabel' => 'maxLabel',
        'range' => 'range', 'axisx' => 'axisX', 'axisy' => 'axisY', 'heatmapthreshold' => 'heatmapThreshold',
        'cornerbottomleft' => 'cornerBottomLeft', 'cornerbottomright' => 'cornerBottomRight',
        'cornertopleft' => 'cornerTopLeft', 'cornertopright' => 'cornerTopRight',
    ];

    public function __construct(
        private IL10N $l10n,
    ) {
    }

    // ── Export ──────────────────────────────────────────────────────────────

    /**
     * @param Poll[] $polls the deck in order
     */
    public function export(Room $room, array $polls): string {
        $quiz = $room->getMode() === 'quiz';
        $head = [
            'format' => self::FORMAT,
            'title' => $room->titleOrEmpty(),
            'mode' => $room->getMode(),
        ];
        if ($quiz) {
            $head['revealAtEnd'] = $room->getRevealAtEnd();
            $head['pace'] = $room->getPace();
            $head['timed'] = $room->getTimed();
            $head['feedback'] = $room->getFeedback();
        }
        $out = ['---'];
        foreach ($head as $key => $value) {
            $out[] = $key . ': ' . self::yamlScalar($value);
        }
        $out[] = '---';
        $out[] = '';
        $out[] = '<!--';
        $out[] = '  vibeARS deck (format ' . self::FORMAT . '). Edit it and import it under "My rooms".';
        $out[] = '  One question per "## heading"; below it "key: value" settings and a list';
        $out[] = '  of options. Quiz: "- [x]" marks a correct option, an ordered list is the';
        $out[] = '  correct order, "left → right" is a matching pair, "time: none" lets the';
        $out[] = '  moderator end the question. Images are not part of this file.';
        $out[] = '-->';
        foreach ($polls as $poll) {
            $out[] = '';
            $out[] = '## ' . self::oneLine($poll->getQuestion());
            $out[] = 'type: ' . $poll->getType();
            if ($quiz) {
                $out[] = 'time: ' . ($poll->getTimeLimit() > 0 ? (string)$poll->getTimeLimit() : 'none');
            }
            foreach ($this->questionLines($poll, $quiz) as $line) {
                $out[] = $line;
            }
        }
        return implode("\n", $out) . "\n";
    }

    /** @return list<string> */
    private function questionLines(Poll $poll, bool $quiz): array {
        $key = $poll->getAnswerKeyArray();
        $lines = [];
        switch ($poll->getType()) {
            case 'choice':
                foreach ($poll->getOptionsArray() as $o) {
                    $lines[] = $quiz
                        ? self::checkItem($o['id'] === $poll->getCorrectOption(), $o['label'])
                        : '- ' . self::oneLine($o['label']);
                }
                break;
            case 'truefalse':
                $options = $poll->getOptionsArray();
                $lines[] = 'answer: ' . (($options[0]['id'] ?? '') === $poll->getCorrectOption() ? 'true' : 'false');
                break;
            case 'multi':
                $correct = array_map('strval', (array)($key['correct'] ?? []));
                foreach ($poll->getOptionsArray() as $o) {
                    $lines[] = self::checkItem(in_array($o['id'], $correct, true), $o['label']);
                }
                break;
            case 'rank':
                foreach (array_values($poll->getOptionsArray()) as $i => $o) {
                    $lines[] = ($i + 1) . '. ' . self::oneLine($o['label']);
                }
                break;
            case 'match':
                $match = $poll->getMatchConfig();
                $targetLabel = [];
                foreach ($match['targets'] as $t) {
                    $targetLabel[$t['id']] = $t['label'];
                }
                $map = (array)($key['map'] ?? []);
                foreach ($match['items'] as $i => $item) {
                    // Quiz: the solution's target; poll: target i sits next to item i.
                    $right = isset($map[$item['id']]) && isset($targetLabel[$map[$item['id']]])
                        ? $targetLabel[$map[$item['id']]]
                        : ($match['targets'][$i]['label'] ?? '');
                    $lines[] = '- ' . self::oneLine($item['label']) . ' → ' . self::oneLine($right);
                }
                break;
            case 'number':
                $lines[] = 'target: ' . self::number($key['target'] ?? 0);
                $lines[] = 'tolerance: ' . self::number($key['tolerance'] ?? 0);
                break;
            case 'text':
                foreach ((array)($key['accepted'] ?? []) as $answer) {
                    $lines[] = '- ' . self::oneLine((string)$answer);
                }
                break;
            case 'words':
                $lines[] = 'maxWords: ' . $poll->getMaxWords();
                break;
            case 'scale':
                $scale = $poll->getScaleConfig();
                $lines[] = 'scaleMode: ' . $scale['mode'];
                if ($scale['mode'] === 'spectrum') {
                    $lines[] = 'scaleMax: ' . $scale['max'];
                    foreach ($scale['aspects'] ?? [] as $a) {
                        $lines[] = '- ' . self::oneLine($a['label']) . ' | ' . self::oneLine($a['poleLow']) . ' | ' . self::oneLine($a['poleHigh']);
                    }
                } elseif ($scale['mode'] === 'compass') {
                    $lines[] = 'range: ' . $scale['range'];
                    $lines[] = 'axisX: ' . self::axis($scale['axisX']);
                    $lines[] = 'axisY: ' . self::axis($scale['axisY']);
                    $corners = $scale['cornerLabels'] + ['', '', '', ''];
                    $lines[] = 'cornerBottomLeft: ' . self::oneLine($corners[0]);
                    $lines[] = 'cornerBottomRight: ' . self::oneLine($corners[1]);
                    $lines[] = 'cornerTopLeft: ' . self::oneLine($corners[2]);
                    $lines[] = 'cornerTopRight: ' . self::oneLine($corners[3]);
                    $lines[] = 'heatmapThreshold: ' . $scale['heatmapThreshold'];
                } else {
                    $lines[] = 'scaleMax: ' . $scale['max'];
                    $lines[] = 'minLabel: ' . self::oneLine($scale['minLabel']);
                    $lines[] = 'maxLabel: ' . self::oneLine($scale['maxLabel']);
                }
                break;
        }
        return $lines;
    }

    private static function checkItem(bool $checked, string $label): string {
        return '- [' . ($checked ? 'x' : ' ') . '] ' . self::oneLine($label);
    }

    /** @param array{title:string,poleLow:string,poleHigh:string} $axis */
    private static function axis(array $axis): string {
        return self::oneLine($axis['title']) . ' | ' . self::oneLine($axis['poleLow']) . ' | ' . self::oneLine($axis['poleHigh']);
    }

    /** One line of text: a label or question never spans lines in the file. */
    private static function oneLine(string $s): string {
        return trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $s) ?? $s);
    }

    private static function number(int|float|string $v): string {
        $n = (float)$v;
        return $n === floor($n) && abs($n) < 1e15 ? (string)(int)$n : (string)$n;
    }

    /** A header value: strings always double-quoted, so a title like "yes" or "1" stays text. */
    private static function yamlScalar(string|bool|int $v): string {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], self::oneLine($v)) . '"';
    }

    // ── Import ──────────────────────────────────────────────────────────────

    /**
     * Reads a deck file. The questions come back as request bodies in the
     * shape of the composer (type, question, options, correctIndex, pairs,
     * …, timeLimit), unvalidated: DeckService::addPoll checks them as it
     * checks the composer's.
     *
     * @return array{title:string, mode:string, revealAtEnd:bool, pace:string, timed:bool, feedback:string, questions:list<array<string,mixed>>}
     * @throws \InvalidArgumentException with a message that names the line
     */
    public function parse(string $markdown): array {
        if (strlen($markdown) > self::MAX_BYTES) {
            throw $this->error($this->l10n->t('The file is too large.'));
        }
        if (!mb_check_encoding($markdown, 'UTF-8')) {
            throw $this->error($this->l10n->t('The file is not UTF-8 text.'));
        }
        if (str_starts_with($markdown, "\xEF\xBB\xBF")) {
            $markdown = substr($markdown, 3);
        }
        $markdown = str_replace("\0", '', str_replace(["\r\n", "\r"], "\n", $markdown));
        $lines = explode("\n", $markdown);

        [$head, $next] = $this->frontMatter($lines);
        $room = $this->roomSettings($head);
        $questions = $this->questions($lines, $next, $room['mode'] === 'quiz');
        if ($questions === []) {
            throw $this->error($this->l10n->t('The file contains no question: a question starts with a line "## …".'));
        }
        $room['questions'] = $questions;
        return $room;
    }

    /**
     * The YAML header: from the first "---" line to the next, as key => [value, line].
     *
     * @param list<string> $lines
     * @return array{0: array<string, array{0: string|bool|int|float, 1: int}>, 1: int} settings, index of the first body line
     */
    private function frontMatter(array $lines): array {
        $i = 0;
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }
        if ($i >= count($lines) || trim($lines[$i]) !== '---') {
            throw $this->error($this->l10n->t('The file needs a YAML header: a "---" line, the settings, another "---" line.'));
        }
        $head = [];
        for ($j = $i + 1; $j < count($lines); $j++) {
            $line = $lines[$j];
            if (trim($line) === '---') {
                return [$head, $j + 1];
            }
            $bare = trim(preg_replace('/^\s*#.*$/', '', $line) ?? '');
            if ($bare === '') {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]*)\s*:(.*)$/', $line, $m)) {
                throw $this->error($this->l10n->t('Line %d: the header holds "key: value" lines only.', [$j + 1]));
            }
            $head[strtolower($m[1])] = [$this->yamlValue(trim($m[2]), $j + 1), $j + 1];
        }
        throw $this->error($this->l10n->t('The YAML header is not closed: a second "---" line is missing.'));
    }

    /** A flat YAML scalar: quoted text with escapes, true/false, a number, or plain text. */
    private function yamlValue(string $raw, int $line): string|bool|int|float {
        if ($raw === '') {
            return '';
        }
        if ($raw[0] === '"') {
            if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/', $raw, $m)) {
                throw $this->error($this->l10n->t('Line %d: the quoted value is not closed.', [$line]));
            }
            return preg_replace_callback('/\\\\(.)/', static fn (array $e): string => match ($e[1]) {
                'n' => "\n", 't' => "\t", default => $e[1],
            }, $m[1]) ?? '';
        }
        if ($raw[0] === "'") {
            if (!preg_match("/^'((?:[^']|'')*)'\s*(?:#.*)?$/", $raw, $m)) {
                throw $this->error($this->l10n->t('Line %d: the quoted value is not closed.', [$line]));
            }
            return str_replace("''", "'", $m[1]);
        }
        $plain = trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
        $lower = strtolower($plain);
        if (in_array($lower, ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($lower, ['false', 'no', 'off'], true)) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $plain) && abs((float)$plain) < 1e15) {
            return (int)$plain;
        }
        if (is_numeric($plain)) {
            return (float)$plain;
        }
        return $plain;
    }

    /**
     * @param array<string, array{0: string|bool|int|float, 1: int}> $head
     * @return array{title:string, mode:string, revealAtEnd:bool, pace:string, timed:bool, feedback:string}
     */
    private function roomSettings(array $head): array {
        $text = static fn (string $key, string $default = ''): string => isset($head[$key]) ? self::scalarText($head[$key][0]) : $default;
        $format = $text('format');
        if ($format !== '' && !str_starts_with($format, 'vibears/')) {
            throw $this->error($this->l10n->t('Unknown file format "%s" — expected "%s".', [$format, self::FORMAT]));
        }
        $mode = strtolower($text('mode', 'poll'));
        if (!in_array($mode, ['poll', 'quiz'], true)) {
            throw $this->error($this->l10n->t('Line %d: "mode" must be "poll" or "quiz".', [$head['mode'][1]]));
        }
        $pace = strtolower($text('pace', 'live'));
        if (!in_array($pace, ['live', 'self'], true)) {
            throw $this->error($this->l10n->t('Line %d: "pace" must be "live" or "self".', [$head['pace'][1]]));
        }
        $feedback = strtolower($text('feedback', 'each'));
        if (!in_array($feedback, ['each', 'end'], true)) {
            throw $this->error($this->l10n->t('Line %d: "feedback" must be "each" or "end".', [$head['feedback'][1]]));
        }
        return [
            'title' => $text('title'),
            'mode' => $mode,
            'revealAtEnd' => $mode === 'quiz' && $this->flag($head, 'revealAtEnd', false),
            'pace' => $mode === 'quiz' ? $pace : 'live',
            'timed' => $mode !== 'quiz' || $this->flag($head, 'timed', true),
            'feedback' => $mode === 'quiz' ? $feedback : 'each',
        ];
    }

    /** @param array<string, array{0: string|bool|int|float, 1: int}> $head */
    private function flag(array $head, string $key, bool $default): bool {
        $k = strtolower($key);
        if (!isset($head[$k])) {
            return $default;
        }
        $v = $head[$k][0];
        if (is_bool($v)) {
            return $v;
        }
        $parsed = Input::flag(is_float($v) ? (string)$v : $v);
        if ($parsed === null) {
            throw $this->error($this->l10n->t('Line %d: "%s" must be true or false.', [$head[$k][1], $key]));
        }
        return $parsed;
    }

    private static function scalarText(string|bool|int|float $v): string {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return trim((string)$v);
    }

    /**
     * The body: one block per "## " heading. Lines before the first
     * heading may only be blank, a document title ("# …") or a comment.
     *
     * @param list<string> $lines
     * @return list<array<string, mixed>> request bodies
     */
    private function questions(array $lines, int $from, bool $quiz): array {
        $body = implode("\n", array_slice($lines, $from));
        // Comments go, line numbers stay.
        $body = preg_replace_callback('/<!--.*?-->/s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $body) ?? $body;
        $blocks = [];
        $current = null;
        foreach (explode("\n", $body) as $offset => $line) {
            $no = $from + $offset + 1;
            if (preg_match('/^##\s+(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = ['question' => trim($m[1]), 'line' => $no, 'attrs' => [], 'items' => []];
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            if ($current === null) {
                if (preg_match('/^#\s/', $line)) {
                    continue; // the document's title
                }
                throw $this->error($this->l10n->t('Line %d: text before the first question. A question starts with a line "## …".', [$no]));
            }
            if (preg_match('/^\s*#{1,6}\s/', $line)) {
                throw $this->error($this->l10n->t('Line %d: only "## " headings are allowed — one per question.', [$no]));
            }
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])(?:\s+(.*))?$/', $line, $m)) {
                $text = trim($m[1] ?? '');
                $checked = null;
                if (preg_match('/^\[([ xX])\]\s*(.*)$/', $text, $c)) {
                    $checked = $c[1] !== ' ';
                    $text = trim($c[2]);
                }
                $current['items'][] = ['text' => $text, 'checked' => $checked, 'line' => $no];
                continue;
            }
            if (preg_match('/^\s*([A-Za-z][A-Za-z0-9]*)\s*:(.*)$/', $line, $m)) {
                $lower = strtolower($m[1]);
                if (!isset(self::KEYS[$lower])) {
                    throw $this->error($this->l10n->t('Line %d: unknown setting "%s".', [$no, $m[1]]));
                }
                $current['attrs'][self::KEYS[$lower]] = ['value' => self::unquote(trim($m[2])), 'line' => $no];
                continue;
            }
            throw $this->error($this->l10n->t('Line %d: unexpected text. Below a question only "key: value" settings and list items are allowed.', [$no]));
        }
        if ($current !== null) {
            $blocks[] = $current;
        }
        $out = [];
        foreach ($blocks as $i => $block) {
            $out[] = $this->body($block, $i + 1, $quiz);
        }
        return $out;
    }

    /** A setting's value as typed, minus one pair of surrounding quotes. */
    private static function unquote(string $v): string {
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
            return substr($v, 1, -1);
        }
        return $v;
    }

    /**
     * One block → the composer's request body. Only the file's structure is
     * checked here (a pair without an arrow, two [x] on a single-choice
     * question); content rules stay with DeckService.
     *
     * @param array{question:string, line:int, attrs:array<string, array{value:string, line:int}>, items:list<array{text:string, checked:?bool, line:int}>} $block
     * @return array<string, mixed>
     */
    private function body(array $block, int $number, bool $quiz): array {
        $attr = static fn (string $key): ?string => isset($block['attrs'][$key]) ? $block['attrs'][$key]['value'] : null;
        $items = $block['items'];
        $texts = array_column($items, 'text');
        $type = strtolower($attr('type') ?? 'choice');
        if (!in_array($type, self::TYPES, true)) {
            throw $this->error($this->l10n->t('Question %1$d (line %2$d): unknown question type "%3$s".', [$number, $block['line'], $attr('type') ?? '']));
        }
        $body = ['type' => $type, 'question' => $block['question']];
        if ($quiz) {
            $body['timeLimit'] = $this->timeLimit($attr('time'), $block['attrs']['time']['line'] ?? $block['line']);
        }
        switch ($type) {
            case 'choice':
                $body['options'] = $texts;
                if ($quiz) {
                    $checked = array_keys(array_filter($items, static fn (array $it): bool => $it['checked'] === true));
                    if (count($checked) > 1) {
                        throw $this->error($this->l10n->t('Question %1$d (line %2$d): mark exactly one option with [x] — or use the type "multi".', [$number, $block['line']]));
                    }
                    if ($checked !== []) {
                        $body['correctIndex'] = $checked[0];
                    }
                }
                break;
            case 'multi':
                $body['options'] = $texts;
                $body['correctIndexes'] = array_values(array_keys(array_filter($items, static fn (array $it): bool => $it['checked'] === true)));
                break;
            case 'rank':
                $body['options'] = $texts;
                break;
            case 'truefalse':
                $answer = $attr('answer');
                if ($answer !== null) {
                    $flag = Input::flag($answer);
                    if ($flag === null) {
                        throw $this->error($this->l10n->t('Question %1$d (line %2$d): "answer" must be true or false.', [$number, $block['attrs']['answer']['line']]));
                    }
                    $body['correctIndex'] = $flag ? 0 : 1;
                } else {
                    foreach ($items as $i => $it) {
                        if ($it['checked'] === true && $i < 2) {
                            $body['correctIndex'] = $i;
                        }
                    }
                }
                break;
            case 'match':
                $pairs = [];
                foreach ($items as $it) {
                    $parts = preg_split('/\s*(?:→|->|=>)\s*/u', $it['text'], 2);
                    if ($parts === false || count($parts) !== 2) {
                        throw $this->error($this->l10n->t('Line %d: a pair is written as "left → right".', [$it['line']]));
                    }
                    $pairs[] = ['left' => $parts[0], 'right' => $parts[1]];
                }
                $body['pairs'] = $pairs;
                break;
            case 'number':
                $body['target'] = $attr('target');
                $body['tolerance'] = $attr('tolerance') ?? 0;
                break;
            case 'text':
                $body['answers'] = $texts;
                break;
            case 'words':
                $body['maxWords'] = $attr('maxWords') ?? 3;
                break;
            case 'scale':
                $mode = strtolower($attr('scaleMode') ?? 'single');
                $body['scaleMode'] = $mode;
                $body['scaleMax'] = $attr('scaleMax') ?? 5;
                if ($mode === 'spectrum') {
                    $body['aspects'] = array_map(static function (string $text): array {
                        $parts = array_map('trim', explode('|', $text, 3));
                        return ['label' => $parts[0], 'poleLow' => $parts[1] ?? '', 'poleHigh' => $parts[2] ?? ''];
                    }, $texts);
                } elseif ($mode === 'compass') {
                    $axis = function (string $key) use ($attr, $block, $number): array {
                        $parts = array_map('trim', explode('|', $attr($key) ?? '', 3));
                        if (count($parts) !== 3) {
                            throw $this->error($this->l10n->t('Question %1$d (line %2$d): "%3$s" is written as "title | low pole | high pole".', [$number, $block['attrs'][$key]['line'] ?? $block['line'], $key]));
                        }
                        return ['title' => $parts[0], 'poleLow' => $parts[1], 'poleHigh' => $parts[2]];
                    };
                    $body['range'] = $attr('range') ?? 5;
                    $body['axisX'] = $axis('axisX');
                    $body['axisY'] = $axis('axisY');
                    $body['cornerLabels'] = [
                        $attr('cornerBottomLeft') ?? '', $attr('cornerBottomRight') ?? '',
                        $attr('cornerTopLeft') ?? '', $attr('cornerTopRight') ?? '',
                    ];
                    $body['heatmapThreshold'] = $attr('heatmapThreshold') ?? 45;
                } else {
                    $body['minLabel'] = $attr('minLabel') ?? '';
                    $body['maxLabel'] = $attr('maxLabel') ?? '';
                }
                break;
        }
        return $body;
    }

    /** "time: 30", "30 s", "none", "0" or missing (the composer's default). */
    private function timeLimit(?string $raw, int $line): int {
        if ($raw === null) {
            return 30;
        }
        $v = strtolower(trim($raw));
        if (in_array($v, ['none', 'no', 'off', 'unlimited', '∞', '0'], true)) {
            return DeckService::NO_LIMIT;
        }
        if (preg_match('/^(\d{1,4})\s*s(?:ec(?:onds?)?)?$/', $v, $m) || preg_match('/^(\d{1,4})$/', $v, $m)) {
            return (int)$m[1];
        }
        throw $this->error($this->l10n->t('Line %d: "time" is a number of seconds or "none".', [$line]));
    }

    private function error(string $message): \InvalidArgumentException {
        return new \InvalidArgumentException($message);
    }
}
