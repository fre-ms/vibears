<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\Room;
use OCA\Vibears\Service\DeckGift;
use OCA\Vibears\Service\DeckService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Moodle GIFT file (DeckGift): what the export writes, the import reads
 * back into the same questions (through the real DeckService::addPoll, as
 * the deck test does for Markdown) — a ranking question excepted, which
 * has no GIFT counterpart and comes back as matching. Hand-written files
 * in the spellings of Moodle's own documentation read as well: feedback,
 * weights, names, categories, comments, escapes, an [html] question, the
 * missing-word form, numerical ranges, CRLF and a BOM. A block that cannot
 * be a question here is refused with its number and line.
 */
#[CoversClass(DeckGift::class)]
class DeckGiftTest extends TestCase {
    use DeckServiceFixture;

    private DeckGift $gift;
    private DeckService $deck;

    protected function setUp(): void {
        $l10n = $this->englishL10n();
        $this->gift = new DeckGift($l10n);
        $this->deck = $this->deckService($l10n);
    }

    // ── Round trip ──────────────────────────────────────────────────────────

    public function testExportThenImportStoresTheSameQuizQuestions(): void {
        $room = $this->room('quiz', 'Lecture 3: "Planets" & more');
        $bodies = [
            ['type' => 'choice', 'question' => 'Which planet is the largest?', 'options' => ['Jupiter', 'Saturn', 'Earth = big?'], 'correctIndex' => 0, 'timeLimit' => 20],
            ['type' => 'truefalse', 'question' => 'Water boils at 100 °C at sea level.', 'correctIndex' => 0, 'timeLimit' => 15],
            ['type' => 'truefalse', 'question' => 'The Sun orbits the Earth.', 'correctIndex' => 1, 'timeLimit' => 15],
            ['type' => 'multi', 'question' => 'Which of these are prime numbers? {2, 3, 4, 9}', 'options' => ['2', '3', '4', '9'], 'correctIndexes' => [0, 1], 'timeLimit' => 30],
            ['type' => 'number', 'question' => 'In which year did the Berlin Wall fall?', 'target' => 1989, 'tolerance' => 1, 'timeLimit' => 30],
            ['type' => 'number', 'question' => 'How long is a marathon in km?', 'target' => 42.195, 'tolerance' => 0.5, 'timeLimit' => 0],
            ['type' => 'text', 'question' => 'Who wrote "Faust"?', 'answers' => ['Goethe', 'Johann Wolfgang von Goethe'], 'timeLimit' => 60],
            ['type' => 'match', 'question' => 'Match capital → country.', 'pairs' => [['left' => 'Paris', 'right' => 'France'], ['left' => 'Berlin', 'right' => 'Germany']], 'timeLimit' => 300],
        ];
        $original = array_map(fn (array $body): Poll => $this->deck->addPoll($room, $body), $bodies);

        $file = $this->gift->export($room, $original);
        $parsed = $this->gift->parse($file);

        $this->assertSame('quiz', $parsed['mode']);
        $again = array_map(fn (array $body): Poll => $this->deck->addPoll($room, $body), $parsed['questions']);
        $this->assertSame(array_map([$this, 'shape'], $original), array_map([$this, 'shape'], $again));
        // GIFT has no time per question: every question comes back with the composer's default.
        $this->assertSame([30], array_values(array_unique(array_map(static fn (Poll $p): int => $p->getTimeLimit(), $again))));
    }

    public function testARankingGoesOutAsPositionsAndComesBackAsMatching(): void {
        $room = $this->room('quiz');
        $rank = $this->deck->addPoll($room, ['type' => 'rank', 'question' => 'Order the planets.', 'options' => ['Mercury', 'Venus', 'Earth']]);

        $file = $this->gift->export($room, [$rank]);

        $this->assertStringContainsString("=1 -> Mercury\n  =2 -> Venus\n  =3 -> Earth\n", $file);
        $this->assertStringContainsString('// ranking: the positions are the solution', $file);
        $back = $this->gift->parse($file)['questions'][0];
        $this->assertSame('match', $back['type']);
        $this->assertSame([['left' => '1', 'right' => 'Mercury'], ['left' => '2', 'right' => 'Venus'], ['left' => '3', 'right' => 'Earth']], $back['pairs']);
    }

    public function testExportRefusesAPoll(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GIFT holds graded questions; this room is a poll.');

        $this->gift->export($this->room('poll'), []);
    }

    public function testExportEscapesAndWeights(): void {
        $room = $this->room('quiz');
        $poll = $this->deck->addPoll($room, ['type' => 'multi', 'question' => 'Pick {all} that = true: a#b', 'options' => ['x~y', 'c:d', 'plain'], 'correctIndexes' => [0, 1]]);

        $file = $this->gift->export($room, [$poll]);

        $this->assertStringContainsString('::Pick \{all\} that \= true\: a\#b::Pick \{all\} that \= true\: a\#b {', $file);
        $this->assertStringContainsString("~%50%x\\~y\n  ~%50%c\\:d\n  ~%-100%plain\n", $file);
        $this->assertSame('33.33333', DeckGift::fraction(3));
        $this->assertSame('14.28571', DeckGift::fraction(7));
        $this->assertSame('100', DeckGift::fraction(1));
        $this->assertSame('12.5', DeckGift::fraction(8));
        $this->assertSame('11.11111', DeckGift::fraction(9));
        $this->assertSame('10', DeckGift::fraction(10));
        $this->assertSame('5', DeckGift::fraction(24), 'the nearest of Moodle\'s list');
    }

    // ── Hand-written files ──────────────────────────────────────────────────

    public function testMoodlesOwnExamplesRead(): void {
        $file = "\xEF\xBB\xBF// true/false, with feedback\r\n"
            . "::Q1:: 1+1=2 {T}\r\n"
            . "\r\n"
            . "\$CATEGORY: \$course\$/Planets\r\n"
            . "// multiple choice with specified feedback for right and wrong answers\r\n"
            . "::Q2:: What's between orange and green in the spectrum? \r\n"
            . "{ =yellow # right; good! ~red # wrong, it's yellow ~blue # wrong, it's yellow }\r\n"
            . "\r\n"
            . "// fill-in-the-blank\r\n"
            . "::Q3:: Two plus {=two =2} equals four.\r\n"
            . "\r\n"
            . "// matching\r\n"
            . "::Q4:: Which animal eats which food? { =cat -> cat food =dog -> dog food }\r\n"
            . "\r\n"
            . "// math range question\r\n"
            . "::Q5:: What is a number from 1 to 5? {#1..5}\r\n"
            . "\r\n"
            . "// math question with multiple correct answers, the first counts\r\n"
            . "::Q6:: When was Ulysses S. Grant born? {#\r\n"
            . "         =1822:0\r\n"
            . "         =%50%1822:2\r\n"
            . "}\r\n"
            . "\r\n"
            . "// multiple answers with percentage weights\r\n"
            . "::Q7:: What two people are entombed in Grant's tomb? {\r\n"
            . "   ~%-100%No one\r\n"
            . "   ~%50%Grant\r\n"
            . "   ~%50%Grant's wife\r\n"
            . "   ~%-100%Grant's father\r\n"
            . "}\r\n";

        $q = $this->gift->parse($file)['questions'];

        $this->assertCount(7, $q);
        $this->assertSame(['type' => 'truefalse', 'correctIndex' => 0], self::typeFields($q[0]));
        $this->assertSame('1+1=2', $q[0]['question']);
        $this->assertSame(['type' => 'choice', 'options' => ['yellow', 'red', 'blue'], 'correctIndex' => 0], self::typeFields($q[1]));
        $this->assertSame("What's between orange and green in the spectrum?", $q[1]['question']);
        $this->assertSame(['type' => 'text', 'answers' => ['two', '2']], self::typeFields($q[2]));
        $this->assertSame('Two plus ___ equals four.', $q[2]['question']);
        $this->assertSame(['type' => 'match', 'pairs' => [['left' => 'cat', 'right' => 'cat food'], ['left' => 'dog', 'right' => 'dog food']]], self::typeFields($q[3]));
        $this->assertSame(['type' => 'number', 'target' => 3, 'tolerance' => 2], self::typeFields($q[4]));
        $this->assertSame(['type' => 'number', 'target' => 1822, 'tolerance' => 0], self::typeFields($q[5]));
        $this->assertSame(['type' => 'multi', 'options' => ['No one', 'Grant', "Grant's wife", "Grant's father"], 'correctIndexes' => [1, 2]], self::typeFields($q[6]));
        $this->assertSame(30, $q[6]['timeLimit']);
    }

    public function testEscapesHtmlAndNamesRead(): void {
        $file = "::A \\:\\: B::[html]<p>Which <b>sign</b> is <i>escaped</i>: \\= or \\~ &amp; \\{brace\\}?</p> {\n"
            . "=the \\= sign\n~the \\~ sign\n~a \\#hash\n}\n\n"
            . "[markdown]**Bold** question? {=yes ~no}\n\n"
            . "::Only a name::{=answer}\n";

        $q = $this->gift->parse($file)['questions'];

        $this->assertSame('Which sign is escaped: = or ~ & {brace}?', $q[0]['question']);
        $this->assertSame(['the = sign', 'the ~ sign', 'a #hash'], $q[0]['options']);
        $this->assertSame(0, $q[0]['correctIndex']);
        $this->assertSame('**Bold** question?', $q[1]['question'], 'markdown stays as it is — vibeARS renders it');
        $this->assertSame('Only a name', $q[2]['question'], 'without a text the name is the question');
        $this->assertSame(['type' => 'text', 'answers' => ['answer']], self::typeFields($q[2]));
    }

    public function testSeveralKeysWithoutWeightsAreMultipleAnswers(): void {
        $q = $this->gift->parse("Which are even? {=2 ~3 =4 ~5}\n")['questions'][0];

        $this->assertSame(['type' => 'multi', 'options' => ['2', '3', '4', '5'], 'correctIndexes' => [0, 2]], self::typeFields($q));
    }

    public static function refusals(): array {
        return [
            'description' => ["Welcome to the quiz.\n", 'Question 1 (line 1): no answer block in braces — a description cannot be a question here.'],
            'essay' => ["Write about planets. {}\n", 'Question 1 (line 1): an essay has no answer to check — give at least one accepted answer.'],
            'unclosed' => ["A? {=yes ~no\n", 'Question 1 (line 1): the answer block is not closed.'],
            'no key' => ["A? {~yes ~no}\n", 'Question 1 (line 1): no answer is marked correct (= or a positive weight).'],
            'empty block' => ["A? {   }\n", 'Question 1 (line 1): an essay has no answer to check — give at least one accepted answer.'],
            'no answers' => ["A? {yes no}\n", 'Question 1 (line 1): the answer block holds no answer — answers start with = or ~.'],
            'bad number' => ["A? {#one}\n", 'Question 1 (line 1): the numerical answer is written as "#1989:1" (target:tolerance) or "#1..5".'],
            'no text' => ["{=yes ~no}\n", 'Question 1 (line 1): the question has no text.'],
            'second question' => ["A? {T}\n\n// a note\n\nB? {}\n", 'Question 2 (line 5): an essay has no answer to check — give at least one accepted answer.'],
            'nothing' => ["// only a comment\n\n", 'The file holds no question.'],
            'not utf-8' => ["A? {=\xFF}\n", 'The file is not UTF-8 text.'],
        ];
    }

    #[DataProvider('refusals')]
    public function testABlockThatCannotBeAQuestionIsRefusedWithItsLine(string $file, string $message): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->gift->parse($file);
    }

    public function testTooLargeAFileIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The file is too large.');

        $this->gift->parse(str_repeat("A? {T}\n\n", (int)(DeckGift::MAX_BYTES / 8) + 1));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function room(string $mode, string $title = ''): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setMode($mode);
        $room->setTitle($title);
        return $room;
    }

    /** The type-specific fields of a parsed body (without question and time). */
    private static function typeFields(array $body): array {
        unset($body['question'], $body['timeLimit']);
        return $body;
    }

    /** A stored question without its IDs, as the deck test compares them. */
    private function shape(Poll $poll): array {
        $options = $poll->getOptionsArray();
        $labels = array_column($options, 'label');
        $byId = array_column($options, 'label', 'id');
        $key = $poll->getAnswerKeyArray();
        $out = ['type' => $poll->getType(), 'question' => $poll->getQuestion()];
        switch ($poll->getType()) {
            case 'choice':
            case 'truefalse':
                $out['options'] = $labels;
                $out['correct'] = $byId[$poll->getCorrectOption()] ?? '';
                break;
            case 'multi':
                $out['options'] = $labels;
                $out['correct'] = array_map(static fn (string $id): string => $byId[$id], $key['correct']);
                break;
            case 'number':
                $out['target'] = $key['target'];
                $out['tolerance'] = $key['tolerance'];
                break;
            case 'text':
                $out['answers'] = $key['accepted'];
                break;
            case 'match':
                $match = $poll->getMatchConfig();
                $targets = array_column($match['targets'], 'label', 'id');
                $out['pairs'] = array_map(static fn (array $item): array => [$item['label'], $targets[$key['map'][$item['id']]] ?? ''], $match['items']);
                break;
        }
        return $out;
    }
}
