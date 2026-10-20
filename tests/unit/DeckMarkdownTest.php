<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\Room;
use OCA\Vibears\Db\RoomMapper;
use OCA\Vibears\Service\DeckArchive;
use OCA\Vibears\Service\DeckGift;
use OCA\Vibears\Service\DeckImportService;
use OCA\Vibears\Service\DeckMarkdown;
use OCA\Vibears\Service\DeckService;
use OCA\Vibears\Service\PollImageService;
use OCA\Vibears\Service\RoomService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The deck file (DeckMarkdown): what the export writes is what the import
 * reads — for every question type and both room modes the round trip
 * through the real DeckService::addPoll stores the same question again
 * (minus the option IDs, which are always new). A hand-written file with
 * the looser spellings (any list style, "->", "time: none", yes/no,
 * comments, a document title, CRLF and a BOM) reads as well, and every
 * structural mistake is refused with the line that holds it. The import
 * service builds the room from the parsed file and leaves nothing behind
 * when a question fails.
 */
#[CoversClass(DeckMarkdown::class)]
#[CoversClass(DeckImportService::class)]
class DeckMarkdownTest extends TestCase {
    use DeckServiceFixture;

    private DeckMarkdown $format;
    private DeckService $deck;

    protected function setUp(): void {
        $l10n = $this->englishL10n();
        $this->format = new DeckMarkdown($l10n);
        $this->deck = $this->deckService($l10n);
    }

    // ── Round trip ──────────────────────────────────────────────────────────

    /** Every quiz type as the composer sends it. */
    public static function quizBodies(): array {
        return [
            ['type' => 'choice', 'question' => 'Which planet is the largest?', 'options' => ['Jupiter', 'Saturn', 'Earth'], 'correctIndex' => 0, 'timeLimit' => 20],
            ['type' => 'truefalse', 'question' => 'Water boils at 100 °C at sea level.', 'correctIndex' => 0, 'timeLimit' => 15],
            ['type' => 'truefalse', 'question' => 'The Sun orbits the Earth.', 'correctIndex' => 1, 'timeLimit' => 15],
            ['type' => 'multi', 'question' => 'Which of these are prime numbers?', 'options' => ['2', '3', '4', '9'], 'correctIndexes' => [0, 1], 'timeLimit' => 30],
            ['type' => 'number', 'question' => 'In which year did the Berlin Wall fall?', 'target' => 1989, 'tolerance' => 1, 'timeLimit' => 30],
            ['type' => 'number', 'question' => 'How long is a marathon in km?', 'target' => 42.195, 'tolerance' => 0.5, 'timeLimit' => 0],
            ['type' => 'text', 'question' => 'Who wrote "Faust"?', 'answers' => ['Goethe', 'Johann Wolfgang von Goethe'], 'timeLimit' => 60],
            ['type' => 'rank', 'question' => 'Order the planets, closest to the Sun first.', 'options' => ['Mercury', 'Venus', 'Earth'], 'timeLimit' => 45],
            ['type' => 'match', 'question' => 'Match capital → country.', 'pairs' => [['left' => 'Paris', 'right' => 'France'], ['left' => 'Berlin', 'right' => 'Germany'], ['left' => 'Rome', 'right' => 'Italy']], 'timeLimit' => 300],
            // no time limit: the moderator ends the question
            ['type' => 'choice', 'question' => 'Take your time: 2 + 2?', 'options' => ['3', '4'], 'correctIndex' => 1, 'timeLimit' => 0],
        ];
    }

    /** Every poll type, the scale in all three modes. */
    public static function pollBodies(): array {
        return [
            ['type' => 'choice', 'question' => 'Tea or coffee?', 'options' => ['Tea', 'Coffee', 'Neither: water']],
            ['type' => 'words', 'question' => 'One word for today', 'maxWords' => 2],
            ['type' => 'scale', 'question' => 'How clear was the lecture?', 'scaleMode' => 'single', 'scaleMax' => 7, 'minLabel' => 'not at all', 'maxLabel' => 'very'],
            ['type' => 'scale', 'question' => 'Rate the course', 'scaleMode' => 'spectrum', 'scaleMax' => 5, 'aspects' => [
                ['label' => 'Clarity', 'poleLow' => 'unclear', 'poleHigh' => 'clear'],
                ['label' => 'Pace', 'poleLow' => 'too slow', 'poleHigh' => 'too fast'],
                ['label' => 'Relevance', 'poleLow' => '', 'poleHigh' => ''],
            ]],
            ['type' => 'scale', 'question' => 'Where do you stand?', 'scaleMode' => 'compass', 'range' => 4,
                'axisX' => ['title' => 'Practice', 'poleLow' => 'practical', 'poleHigh' => 'theoretical'],
                'axisY' => ['title' => 'Social', 'poleLow' => 'alone', 'poleHigh' => 'together'],
                'cornerLabels' => ['practical & alone', 'theoretical & alone', '', 'theoretical & together'],
                'heatmapThreshold' => 12],
            ['type' => 'rank', 'question' => 'Sort by importance', 'options' => ['Sleep', 'Food', 'Wi-Fi']],
            ['type' => 'match', 'question' => 'Which tool for which job?', 'pairs' => [['left' => 'Hammer', 'right' => 'Nail'], ['left' => 'Saw', 'right' => 'Board']]],
        ];
    }

    public static function roundTrips(): array {
        return [
            'quiz, moderated' => ['quiz', 'live', true, 'each', false, self::quizBodies()],
            'quiz, self-paced homework without a timer, feedback at the end, reveal at the end' => ['quiz', 'self', false, 'end', true, self::quizBodies()],
            'poll' => ['poll', 'live', true, 'each', false, self::pollBodies()],
        ];
    }

    #[DataProvider('roundTrips')]
    public function testExportThenImportStoresTheSameDeck(string $mode, string $pace, bool $timed, string $feedback, bool $revealAtEnd, array $bodies): void {
        $room = $this->room($mode, 'Lecture 3: "Planets" & more \\ stuff');
        $room->setPace($pace);
        $room->setTimed($timed);
        $room->setFeedback($feedback);
        $room->setRevealAtEnd($revealAtEnd);
        $original = array_map(fn (array $body): Poll => $this->deck->addPoll($room, $body), $bodies);

        $file = $this->format->export($room, $original);
        $parsed = $this->format->parse($file);

        $this->assertSame('Lecture 3: "Planets" & more \\ stuff', $parsed['title']);
        $this->assertSame($mode, $parsed['mode']);
        if ($mode === 'quiz') {
            $this->assertSame($pace, $parsed['pace']);
            $this->assertSame($timed, $parsed['timed']);
            $this->assertSame($feedback, $parsed['feedback']);
            $this->assertSame($revealAtEnd, $parsed['revealAtEnd']);
        } else {
            $this->assertSame(['live', true, 'each', false], [$parsed['pace'], $parsed['timed'], $parsed['feedback'], $parsed['revealAtEnd']]);
        }
        $this->assertCount(count($original), $parsed['questions']);
        foreach ($parsed['questions'] as $i => $body) {
            $again = $this->deck->addPoll($room, $body);
            $this->assertSame($this->shape($original[$i]), $this->shape($again), 'question ' . ($i + 1) . ' of the ' . $mode);
        }
        // A second export of the re-imported deck is the same file: the format is stable.
        $this->assertSame($file, $this->format->export($room, array_map(fn (array $b): Poll => $this->deck->addPoll($room, $b), $parsed['questions'])));
    }

    public function testExportLooksLikeTheDocumentedFormat(): void {
        $room = $this->room('quiz', 'Demo');
        $polls = [
            $this->deck->addPoll($room, ['type' => 'choice', 'question' => 'Capital of France?', 'options' => ['Paris', 'Rome'], 'correctIndex' => 0, 'timeLimit' => 20]),
            $this->deck->addPoll($room, ['type' => 'rank', 'question' => "Smallest\nfirst", 'options' => ['1', '2'], 'timeLimit' => 0]),
        ];

        $file = $this->format->export($room, $polls);

        $this->assertStringStartsWith("---\nformat: \"vibears/1\"\ntitle: \"Demo\"\nmode: \"quiz\"\nrevealAtEnd: false\npace: \"live\"\ntimed: true\nfeedback: \"each\"\n---\n", $file);
        $this->assertStringContainsString("## Capital of France?\ntype: choice\ntime: 20\n- [x] Paris\n- [ ] Rome\n", $file);
        // a line break inside a question never breaks the file
        $this->assertStringContainsString("## Smallest first\ntype: rank\ntime: none\n1. 1\n2. 2\n", $file);
        $this->assertStringEndsWith("\n", $file);
    }

    // ── Hand-written files ──────────────────────────────────────────────────

    public function testALooselyWrittenFileReads(): void {
        $file = "\xEF\xBB\xBF---\r\n"
            . "# a comment line in the header\r\n"
            . "title: 'It''s a quiz'   # trailing comment\r\n"
            . "mode: Quiz\r\n"
            . "revealAtEnd: yes\r\n"
            . "pace: self\r\n"
            . "timed: off\r\n"
            . "feedback: END\r\n"
            . "---\r\n"
            . "# My document title\r\n"
            . "<!-- a comment\r\nover two lines -->\r\n"
            . "\r\n"
            . "## Pick one\r\n"
            . "Type: Choice\r\n"
            . "time: none\r\n"
            . "* [ ] A\r\n"
            . "* [X] B\r\n"
            . "\r\n"
            . "## Match them\r\n"
            . "type: match\r\n"
            . "time: 45 s\r\n"
            . "1) a -> 1\r\n"
            . "2) b => 2\r\n"
            . "\r\n"
            . "## True or false?\r\n"
            . "type: truefalse\r\n"
            . "- [ ] True\r\n"
            . "- [x] False\r\n"
            . "\r\n"
            . "## Guess\r\n"
            . "type: number\r\n"
            . "target: \"1969\"\r\n"
            . "\r\n"
            . "## Name one\r\n"
            . "type: text\r\n"
            . "+ Goethe\r\n";

        $parsed = $this->format->parse($file);

        $this->assertSame("It's a quiz", $parsed['title']);
        $this->assertSame(['quiz', true, 'self', false, 'end'], [$parsed['mode'], $parsed['revealAtEnd'], $parsed['pace'], $parsed['timed'], $parsed['feedback']]);
        $this->assertSame([
            ['type' => 'choice', 'question' => 'Pick one', 'timeLimit' => 0, 'options' => ['A', 'B'], 'correctIndex' => 1],
            ['type' => 'match', 'question' => 'Match them', 'timeLimit' => 45, 'pairs' => [['left' => 'a', 'right' => '1'], ['left' => 'b', 'right' => '2']]],
            ['type' => 'truefalse', 'question' => 'True or false?', 'timeLimit' => 30, 'correctIndex' => 1],
            ['type' => 'number', 'question' => 'Guess', 'timeLimit' => 30, 'target' => '1969', 'tolerance' => 0],
            ['type' => 'text', 'question' => 'Name one', 'timeLimit' => 30, 'answers' => ['Goethe']],
        ], $parsed['questions']);
    }

    public function testAPollFileIgnoresQuizKeysAndCheckboxes(): void {
        $parsed = $this->format->parse("---\nmode: poll\npace: self\nrevealAtEnd: true\n---\n## Q\n- [x] A\n- B\n");

        $this->assertSame(['live', true, 'each', false], [$parsed['pace'], $parsed['timed'], $parsed['feedback'], $parsed['revealAtEnd']]);
        $this->assertSame([['type' => 'choice', 'question' => 'Q', 'options' => ['A', 'B']]], $parsed['questions']);
    }

    public function testAMissingFormatKeyIsFineAnUnknownOneIsNot(): void {
        $this->assertCount(1, $this->format->parse("---\nmode: quiz\n---\n## Q\n- [x] A\n- B\n")['questions']);

        $this->expectExceptionMessage('Unknown file format "kahoot/2" — expected "vibears/1".');
        $this->format->parse("---\nformat: kahoot/2\n---\n## Q\n- A\n- B\n");
    }

    public static function mistakes(): array {
        $head = "---\nmode: quiz\n---\n";
        return [
            'no header' => ["## Q\n- A\n", 'The file needs a YAML header: a "---" line, the settings, another "---" line.'],
            'header not closed' => ["---\nmode: quiz\n## Q\n", 'The YAML header is not closed: a second "---" line is missing.'],
            'header line without a colon' => ["---\nmode quiz\n---\n## Q\n", 'Line 2: the header holds "key: value" lines only.'],
            'unclosed quote' => ["---\ntitle: \"oops\n---\n## Q\n", 'Line 2: the quoted value is not closed.'],
            'bad mode' => ["---\nmode: survey\n---\n## Q\n", 'Line 2: "mode" must be "poll" or "quiz".'],
            'bad pace' => ["---\nmode: quiz\npace: fast\n---\n## Q\n", 'Line 3: "pace" must be "live" or "self".'],
            'bad feedback' => ["---\nmode: quiz\nfeedback: never\n---\n## Q\n", 'Line 3: "feedback" must be "each" or "end".'],
            'bad boolean' => ["---\nmode: quiz\ntimed: maybe\n---\n## Q\n", 'Line 3: "timed" must be true or false.'],
            'no question' => [$head . "just text\n", 'Line 4: text before the first question. A question starts with a line "## …".'],
            'empty body' => [$head . "\n\n", 'The file contains no question: a question starts with a line "## …".'],
            'deeper heading' => [$head . "## Q\n### sub\n", 'Line 5: only "## " headings are allowed — one per question.'],
            'unknown setting' => [$head . "## Q\ntolerence: 2\n", 'Line 5: unknown setting "tolerence".'],
            'stray text' => [$head . "## Q\nsome explanation\n", 'Line 5: unexpected text. Below a question only "key: value" settings and list items are allowed.'],
            'unknown type' => [$head . "## Q\ntype: essay\n", 'Question 1 (line 4): unknown question type "essay".'],
            'two correct options' => [$head . "## Q\n- [x] A\n- [x] B\n", 'Question 1 (line 4): mark exactly one option with [x] — or use the type "multi".'],
            'pair without an arrow' => [$head . "## Q\ntype: match\n- Paris, France\n", 'Line 6: a pair is written as "left → right".'],
            'bad time' => [$head . "## Q\ntime: soon\n- [x] A\n- B\n", 'Line 5: "time" is a number of seconds or "none".'],
            'bad answer' => [$head . "## Q\ntype: truefalse\nanswer: perhaps\n", 'Question 1 (line 6): "answer" must be true or false.'],
            'axis without poles' => ["---\nmode: poll\n---\n## Q\ntype: scale\nscaleMode: compass\naxisX: Practice\naxisY: a | b | c\n", 'Question 1 (line 7): "axisX" is written as "title | low pole | high pole".'],
        ];
    }

    #[DataProvider('mistakes')]
    public function testStructuralMistakesNameTheLine(string $file, string $message): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->format->parse($file);
    }

    public function testContentRulesStayWithDeckService(): void {
        // A quiz choice without any [x]: the file is well-formed, the composer's rule decides.
        $parsed = $this->format->parse("---\nmode: quiz\n---\n## Q\n- A\n- B\n");
        $this->assertArrayNotHasKey('correctIndex', $parsed['questions'][0]);

        $this->expectExceptionMessage('Please mark the correct answer.');
        $this->deck->addPoll($this->room('quiz'), $parsed['questions'][0]);
    }

    public function testTooLargeAndNotUtf8AreRefusedBeforeParsing(): void {
        try {
            $this->format->parse(str_repeat('#', DeckMarkdown::MAX_BYTES + 1));
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('The file is too large.', $e->getMessage());
        }
        $this->expectExceptionMessage('The file is not UTF-8 text.');
        $this->format->parse("---\n---\n## \xFF\xFE\n");
    }

    // ── DeckImportService ───────────────────────────────────────────────────

    public function testImportBuildsTheRoomAndItsQuestions(): void {
        $room = $this->room('quiz');
        $room->setId(9);
        $rooms = $this->createMock(RoomService::class);
        $rooms->expects($this->once())->method('createRoom')->with('alice', 'quiz', 'Homework')->willReturn($room);
        $rooms->expects($this->never())->method('deleteRoom');
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->expects($this->once())->method('update')->willReturnArgument(0);
        $deck = $this->createMock(DeckService::class);
        $added = [];
        $deck->method('addPoll')->willReturnCallback(function (Room $r, array $body) use (&$added): Poll {
            $added[] = $body['question'];
            return new Poll();
        });

        $result = $this->importer($rooms, $deck, $mapper)->import('alice', "---\ntitle: Homework\nmode: quiz\npace: self\ntimed: no\nfeedback: end\n---\n## A\n- [x] 1\n- 2\n## B\n- [x] 1\n- 2\n");

        $this->assertSame($room, $result);
        $this->assertSame(['A', 'B'], $added);
        $this->assertSame(['self', false, 'end', false], [$room->getPace(), $room->getTimed(), $room->getFeedback(), $room->getRevealAtEnd()]);
    }

    public function testAModeratedQuizWithDefaultsWritesTheRoomRowOnlyOnce(): void {
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('createRoom')->willReturn($this->room('quiz'));
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->expects($this->never())->method('update');

        $this->importer($rooms, $this->createMock(DeckService::class), $mapper)->import('alice', "---\nmode: quiz\n---\n## A\n- [x] 1\n- 2\n");
    }

    public function testAFailingQuestionDeletesTheRoomAndNamesTheQuestion(): void {
        $room = $this->room('quiz');
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('createRoom')->willReturn($room);
        $rooms->expects($this->once())->method('deleteRoom')->with($room);
        $deck = $this->createMock(DeckService::class);
        $deck->method('addPoll')->willReturnCallback(static function (Room $r, array $body): Poll {
            if ($body['question'] === 'B') {
                throw new \InvalidArgumentException('Please mark the correct answer.');
            }
            return new Poll();
        });

        try {
            $this->importer($rooms, $deck, $this->createMock(RoomMapper::class))->import('alice', "---\nmode: quiz\n---\n## A\n- [x] 1\n- 2\n## B\n- 1\n- 2\n");
            $this->fail('imported');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Question 2: Please mark the correct answer.', $e->getMessage());
        }
    }

    public function testAFileThatDoesNotParseCreatesNoRoom(): void {
        $rooms = $this->createMock(RoomService::class);
        $rooms->expects($this->never())->method('createRoom');

        $this->expectException(\InvalidArgumentException::class);
        $this->importer($rooms, $this->createMock(DeckService::class), $this->createMock(RoomMapper::class))->import('alice', 'no header');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    // ── Images: the `image:` line and the ZIP export ───────────────────────

    public function testTheImageLineIsWrittenOnlyWhenTheExportCarriesTheImage(): void {
        $room = $this->room('poll');
        $poll = $this->deck->addPoll($room, ['type' => 'choice', 'question' => 'Which?', 'options' => ['a', 'b']]);

        $plain = $this->format->export($room, [$poll]);
        $withImage = $this->format->export($room, [$poll], [$poll->getId() => 'images/1.png']);

        // (The file's comment block mentions the line; the question block is what counts.)
        $this->assertStringContainsString("## Which?\ntype: choice\n- a\n", $plain);
        $this->assertStringContainsString("## Which?\ntype: choice\nimage: images/1.png\n- a\n", $withImage);
        $this->assertSame('images/1.png', $this->format->parse($withImage)['questions'][0]['image']);
        $this->assertArrayNotHasKey('image', $this->format->parse($plain)['questions'][0]);
    }

    public function testImportStoresTheArchivesImagesOnTheirQuestions(): void {
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('createRoom')->willReturn($this->room('poll'));
        $archive = $this->createMock(DeckArchive::class);
        $archive->method('read')->willReturn([
            'markdown' => "---\nmode: poll\n---\n## A\nimage: ./images/1.png\n- 1\n- 2\n## B\n- 1\n- 2\n",
            'images' => ['images/1.png' => 'PNG-BYTES'],
        ]);
        $deck = $this->createMock(DeckService::class);
        $bodies = [];
        $deck->method('addPoll')->willReturnCallback(static function (Room $room, array $body) use (&$bodies): Poll {
            $bodies[] = $body;
            $poll = new Poll();
            $poll->setId(count($bodies));
            return $poll;
        });
        $images = $this->createMock(PollImageService::class);
        $images->expects($this->once())->method('storeBytes')
            ->with($this->callback(static fn (Poll $p): bool => $p->getId() === 1), 'PNG-BYTES')
            ->willReturnArgument(0);

        $this->importer($rooms, $deck, $this->createMock(RoomMapper::class), $archive, $images)->import('alice', "PK\x03\x04 whatever");

        // The path never reaches DeckService: it is not a question field.
        $this->assertArrayNotHasKey('image', $bodies[0]);
        $this->assertArrayNotHasKey('image', $bodies[1]);
    }

    public function testAnImageMissingFromTheArchiveOrNamedByAPlainFileIsRefusedAndTheRoomGoes(): void {
        $cases = [
            ["PK\x03\x04", ['images/2.png' => 'x'], 'Question 1: the image "images/1.png" is not in the archive.'],
            ["---\nmode: poll\n---\n## A\nimage: images/1.png\n- 1\n- 2\n", null, 'Question 1: the file names an image ("images/1.png") — import the ZIP export, which carries it.'],
        ];
        foreach ($cases as [$content, $archiveImages, $message]) {
            $rooms = $this->createMock(RoomService::class);
            $rooms->method('createRoom')->willReturn($this->room('poll'));
            $rooms->expects($this->once())->method('deleteRoom');
            $archive = $this->createMock(DeckArchive::class);
            $archive->method('read')->willReturn(['markdown' => "---\nmode: poll\n---\n## A\nimage: images/1.png\n- 1\n- 2\n", 'images' => $archiveImages ?? []]);
            $deck = $this->createMock(DeckService::class);
            $deck->method('addPoll')->willReturn(new Poll());
            $images = $this->createMock(PollImageService::class);
            $images->expects($this->never())->method('storeBytes');
            try {
                $this->importer($rooms, $deck, $this->createMock(RoomMapper::class), $archive, $images)->import('alice', $content);
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function testImportTellsTheMarkdownFileFromAGiftFile(): void {
        $deckOf = static fn (string $q): array => ['title' => '', 'mode' => 'quiz', 'revealAtEnd' => false, 'pace' => 'live', 'timed' => true, 'feedback' => 'each', 'questions' => [['type' => 'truefalse', 'question' => $q, 'correctIndex' => 0, 'timeLimit' => 30]]];
        $gift = $this->createMock(DeckGift::class);
        $gift->expects($this->once())->method('parse')->with("Water boils? {T}\n")->willReturn($deckOf('from gift'));
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('createRoom')->willReturn($this->room('quiz'));
        $deck = $this->createMock(DeckService::class);
        $questions = [];
        $deck->method('addPoll')->willReturnCallback(static function (Room $room, array $body) use (&$questions): Poll {
            $questions[] = $body['question'];
            return new Poll();
        });
        $importer = $this->importer($rooms, $deck, $this->createMock(RoomMapper::class), null, null, $gift);

        $importer->import('alice', "Water boils? {T}\n");
        $importer->import('alice', "\xEF\xBB\xBF  ---\nmode: quiz\n---\n## From markdown\n- [x] 1\n- 2\n");

        $this->assertSame(['from gift', 'From markdown'], $questions, 'the header decides, a BOM or blanks before it do not');
    }

    private function importer(RoomService $rooms, DeckService $deck, RoomMapper $mapper, ?DeckArchive $archive = null, ?PollImageService $images = null, ?DeckGift $gift = null): DeckImportService {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        return new DeckImportService(
            $this->format,
            $archive ?? $this->createMock(DeckArchive::class),
            // The real reader: a text without the Markdown header is GIFT, and
            // one that is neither is refused by it.
            $gift ?? new DeckGift($l10n),
            $rooms,
            $deck,
            $images ?? $this->createMock(PollImageService::class),
            $mapper,
            $l10n,
        );
    }

    private function room(string $mode, string $title = ''): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setMode($mode);
        $room->setTitle($title);
        return $room;
    }

    /**
     * A stored question without its IDs: what the file has to carry so that
     * the import stores the same question.
     */
    private function shape(Poll $poll): array {
        $options = $poll->getOptionsArray();
        $labels = array_column($options, 'label');
        $byId = array_column($options, 'label', 'id');
        $key = $poll->getAnswerKeyArray();
        $out = [
            'type' => $poll->getType(),
            'question' => $poll->getQuestion(),
            'timeLimit' => $poll->getTimeLimit(),
            'maxWords' => $poll->getMaxWords(),
        ];
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
            case 'rank':
                $out['options'] = $labels;
                $out['order'] = array_map(static fn (string $id): string => $byId[$id], $key['order'] ?? []);
                break;
            case 'match':
                $match = $poll->getMatchConfig();
                $targets = array_column($match['targets'], 'label', 'id');
                $out['pairs'] = [];
                foreach ($match['items'] as $i => $item) {
                    $out['pairs'][] = [$item['label'], isset($key['map']) ? $targets[$key['map'][$item['id']]] : $match['targets'][$i]['label']];
                }
                break;
            case 'number':
            case 'text':
                $out['key'] = $key;
                break;
            case 'scale':
                $scale = $poll->getScaleConfig();
                if (isset($scale['aspects'])) {
                    $scale['aspects'] = array_map(static function (array $a): array {
                        unset($a['id']);
                        return $a;
                    }, $scale['aspects']);
                }
                $out['scale'] = $scale;
                break;
        }
        return $out;
    }
}
