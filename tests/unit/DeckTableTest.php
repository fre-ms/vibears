<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\Room;
use OCA\Vibears\Service\DeckService;
use OCA\Vibears\Service\DeckTable;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The deck as a table (DeckTable): Kahoot!'s quiz template as .xlsx (built
 * here the way Excel writes it: seven rows of instructions, the header in
 * row 8, shared and inline strings, numbers) and as CSV with any of the
 * three separators; the export read back into the same questions through
 * the real DeckService; the inference of types the template leaves
 * unsaid; a sheet without keys as a poll; and every refusal with its row.
 */
#[CoversClass(DeckTable::class)]
class DeckTableTest extends TestCase {
    use DeckServiceFixture;

    private DeckTable $table;
    private DeckService $deck;
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void {
        $l10n = $this->englishL10n();
        $temp = $this->createMock(ITempManager::class);
        $temp->method('getTemporaryFile')->willReturnCallback(function (): string {
            $file = tempnam(sys_get_temp_dir(), 'vibears-xlsx');
            $this->tmp[] = $file;
            return $file;
        });
        $this->table = new DeckTable($temp, $l10n);
        $this->deck = $this->deckService($l10n);
    }

    protected function tearDown(): void {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
    }

    // ── Kahoot!'s template ──────────────────────────────────────────────────

    public function testKahootTemplateReadsAsAQuiz(): void {
        $xlsx = $this->workbook([
            1 => ['A' => 'Kahoot! quiz template'],
            2 => ['A' => 'Fill in your questions below.'],
            8 => ['B' => 'Question - max 120 characters', 'C' => 'Answer 1 - max 75 characters', 'D' => 'Answer 2 - max 75 characters', 'E' => 'Answer 3 - max 75 characters', 'F' => 'Answer 4 - max 75 characters', 'G' => 'Time limit (sec) – 5, 10, 20, 30, 60, 90, 120, or 240 secs', 'H' => 'Correct answer(s) - choose at least one'],
            9 => ['B' => 'Which planet is the largest?', 'C' => 'Jupiter', 'D' => 'Saturn', 'E' => 'Earth', 'F' => 'Mars', 'G' => 20, 'H' => 1],
            10 => ['B' => 'Which of these are prime numbers?', 'C' => '2', 'D' => '3', 'E' => '4', 'F' => '9', 'G' => 30, 'H' => '1,2'],
            11 => ['B' => 'Water boils at 100 °C at sea level.', 'C' => 'True', 'D' => 'False', 'G' => 10, 'H' => 1],
            12 => ['B' => 'Is the Sun a planet?', 'C' => 'False', 'D' => 'True', 'G' => 10, 'H' => 1],
            13 => [],
            14 => ['B' => '', 'C' => ''],
        ]);

        $this->assertTrue($this->table->isXlsx($xlsx));
        $deck = $this->table->parseXlsx($xlsx);

        $this->assertSame('quiz', $deck['mode']);
        $this->assertSame([
            ['type' => 'choice', 'question' => 'Which planet is the largest?', 'timeLimit' => 20, 'options' => ['Jupiter', 'Saturn', 'Earth', 'Mars'], 'correctIndex' => 0],
            ['type' => 'multi', 'question' => 'Which of these are prime numbers?', 'timeLimit' => 30, 'options' => ['2', '3', '4', '9'], 'correctIndexes' => [0, 1]],
            ['type' => 'truefalse', 'question' => 'Water boils at 100 °C at sea level.', 'timeLimit' => 10, 'correctIndex' => 0],
            ['type' => 'truefalse', 'question' => 'Is the Sun a planet?', 'timeLimit' => 10, 'correctIndex' => 1],
        ], $deck['questions']);
    }

    public function testSheetWithoutSharedStringsAndWithRichTextReads(): void {
        $xlsx = $this->workbook([
            1 => ['A' => 'Question', 'B' => 'Answer 1', 'C' => 'Answer 2', 'D' => 'Correct answer'],
            2 => ['A' => ['rich' => ['Capital of ', 'France', '?']], 'B' => 'Paris', 'C' => 'Rome', 'D' => 'Paris'],
        ], shared: false);

        $deck = $this->table->parseXlsx($xlsx);

        $this->assertSame('Capital of France?', $deck['questions'][0]['question']);
        $this->assertSame(0, $deck['questions'][0]['correctIndex'], 'the key may name the answer\'s text');
    }

    public function testAZipThatIsNoWorkbookIsNoWorkbook(): void {
        $file = tempnam(sys_get_temp_dir(), 'vibears-zip');
        $this->tmp[] = $file;
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('deck.md', '# deck');
        $zip->close();

        $this->assertFalse($this->table->isXlsx((string)file_get_contents($file)));
        $this->assertFalse($this->table->isXlsx('plain text'));
    }

    // ── CSV ─────────────────────────────────────────────────────────────────

    public static function separators(): array {
        return [
            'comma' => [','],
            'semicolon' => [';'],
            'tab' => ["\t"],
        ];
    }

    #[DataProvider('separators')]
    public function testCsvWithAnySeparatorQuotesAndTypes(string $sep): void {
        $q = static fn (string $s): string => '"' . str_replace('"', '""', $s) . '"';
        $csv = "\xEF\xBB\xBF" . implode($sep, ['Type', 'Question', 'Answer 1', 'Answer 2', 'Answer 3', 'Correct answer(s)', 'Time limit (sec)']) . "\r\n"
            . implode($sep, ['', $q('Which is "right", a, b or c?'), 'a', 'b', 'c', '3', '20']) . "\r\n"
            . implode($sep, ['number', 'Year of the fall of the Berlin Wall?', '1989', '1', '', '', '30']) . "\r\n"
            . implode($sep, ['text', $q("Who wrote\nFaust?"), 'Goethe', 'Johann Wolfgang von Goethe', '', '', 'none']) . "\r\n"
            . implode($sep, ['rank', 'Order the planets.', 'Mercury', 'Venus', 'Earth', '', '']) . "\r\n"
            . implode($sep, ['match', 'Capital and country', 'Paris -> France', 'Berlin → Germany', '', '', '60']) . "\r\n"
            . implode($sep, ['multi', 'Even numbers?', '2', '3', '4', 'A C', '']) . "\r\n";

        $this->assertTrue(DeckTable::looksLikeCsv($csv));
        $deck = $this->table->parseCsv($csv);

        $this->assertSame('quiz', $deck['mode']);
        $this->assertSame(['choice', 'number', 'text', 'rank', 'match', 'multi'], array_column($deck['questions'], 'type'));
        $this->assertSame(['Which is "right", a, b or c?', 2], [$deck['questions'][0]['question'], $deck['questions'][0]['correctIndex']]);
        $this->assertSame([1989, 1], [$deck['questions'][1]['target'], $deck['questions'][1]['tolerance']]);
        $this->assertSame(['Who wrote Faust?', 0], [$deck['questions'][2]['question'], $deck['questions'][2]['timeLimit']], 'a quoted line break is one line; "none" is no limit');
        $this->assertSame(['Mercury', 'Venus', 'Earth'], $deck['questions'][3]['options']);
        $this->assertSame([['left' => 'Paris', 'right' => 'France'], ['left' => 'Berlin', 'right' => 'Germany']], $deck['questions'][4]['pairs']);
        $this->assertSame([0, 2], $deck['questions'][5]['correctIndexes'], 'letters name answers too');
        $this->assertSame(30, $deck['questions'][5]['timeLimit'], 'an empty time is the default');
    }

    public function testASheetWithoutAnyKeyIsAPoll(): void {
        $csv = "Frage,Antwort 1,Antwort 2,Antwort 3,Typ\nTea or coffee?,Tea,Coffee,Water,\nSort by importance,Sleep,Food,Wi-Fi,rank\nOne word for today,2,,,words\nHow clear was it?,7,unclear,clear,scale\n";

        $deck = $this->table->parseCsv($csv);

        $this->assertSame('poll', $deck['mode']);
        $this->assertSame([
            ['type' => 'choice', 'question' => 'Tea or coffee?', 'options' => ['Tea', 'Coffee', 'Water']],
            ['type' => 'rank', 'question' => 'Sort by importance', 'options' => ['Sleep', 'Food', 'Wi-Fi']],
            ['type' => 'words', 'question' => 'One word for today', 'maxWords' => 2],
            ['type' => 'scale', 'question' => 'How clear was it?', 'scaleMode' => 'single', 'scaleMax' => 7, 'minLabel' => 'unclear', 'maxLabel' => 'clear'],
        ], $deck['questions']);
    }

    public function testGiftAndMarkdownAreNotTables(): void {
        $this->assertFalse(DeckTable::looksLikeCsv("// comment\n::Q1::Question, with a comma? {=a ~b}\n"));
        $this->assertFalse(DeckTable::looksLikeCsv("---\nformat: \"vibears/1\"\n---\n## Question, one\n"));
        $this->assertFalse(DeckTable::looksLikeCsv("Just a question without columns\n"));
        $this->assertTrue(DeckTable::looksLikeCsv("\xEF\xBB\xBFQuestion;Answer 1;Answer 2\n"));
    }

    public static function refusals(): array {
        return [
            'no header' => ["a,b,c\n1,2,3\n", 'No header row found: the first row names the columns, "Question" and "Answer 1" among them.'],
            'no answer column' => ["Question,Correct\nA?,1\n", 'No header row found: the first row names the columns, "Question" and "Answer 1" among them.'],
            'empty question' => ["Question,Answer 1,Answer 2,Correct\n,a,b,1\n", 'Row 2: the question is empty.'],
            'unknown type' => ["Type,Question,Answer 1,Answer 2,Correct\nessay,A?,a,b,1\n", 'Row 2: unknown type "essay".'],
            'bad key' => ["Question,Answer 1,Answer 2,Correct\nA?,a,b,7\n", 'Row 2: "7" is not one of the answers.'],
            'bad time' => ["Question,Answer 1,Answer 2,Correct,Time\nA?,a,b,1,soon\n", 'Row 2: the time limit is a number of seconds.'],
            'quiz row without key' => ["Question,Answer 1,Answer 2,Correct\nA?,a,b,1\nB?,c,d,\n", 'Row 3: no correct answer — the sheet is a quiz, so every question needs one.'],
            'two keys on a choice' => ["Type,Question,Answer 1,Answer 2,Correct\nchoice,A?,a,b,\"1,2\"\n", 'Row 2: several correct answers — use the type "multi".'],
            'poll type in a quiz' => ["Type,Question,Answer 1,Answer 2,Correct\n,A?,a,b,1\nwords,B?,,,\n", 'Row 3: a "words" question cannot be in a quiz — the sheet has correct answers, so it is one.'],
            'bad pair' => ["Type,Question,Answer 1,Answer 2,Correct\nmatch,A?,Paris France,Berlin -> Germany,\n", 'Row 2: a pair is written as "left -> right".'],
            'true/false key' => ["Type,Question,Answer 1,Answer 2,Correct\ntruefalse,A?,maybe,perhaps,1\n", 'Row 2: mark True or False as correct.'],
            'only filler' => ["Question,Answer 1,Answer 2\n,,\n", 'The file holds no question.'],
            'not utf-8' => ["Question,Answer 1,Answer 2\nA?,\xFF,b\n", 'The file is not UTF-8 text.'],
        ];
    }

    #[DataProvider('refusals')]
    public function testARowThatCannotBeAQuestionIsRefusedWithItsNumber(string $csv, string $message): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->table->parseCsv($csv);
    }

    // ── Round trip ──────────────────────────────────────────────────────────

    public function testExportThenImportStoresTheSameQuiz(): void {
        $room = $this->room('quiz');
        $bodies = [
            ['type' => 'choice', 'question' => "Which planet\nis the largest?", 'options' => ['Jupiter', 'Saturn, the ringed', 'Earth'], 'correctIndex' => 0, 'timeLimit' => 20],
            ['type' => 'truefalse', 'question' => 'Water boils at 100 °C at sea level.', 'correctIndex' => 1, 'timeLimit' => 15],
            ['type' => 'multi', 'question' => 'Which are prime?', 'options' => ['2', '3', '4', '9'], 'correctIndexes' => [0, 1], 'timeLimit' => 30],
            ['type' => 'number', 'question' => 'How long is a marathon in km?', 'target' => 42.195, 'tolerance' => 0.5, 'timeLimit' => 0],
            ['type' => 'text', 'question' => 'Who wrote "Faust"?', 'answers' => ['Goethe', 'Johann Wolfgang von Goethe'], 'timeLimit' => 60],
            ['type' => 'rank', 'question' => 'Order the planets.', 'options' => ['Mercury', 'Venus', 'Earth', 'Mars', 'Jupiter'], 'timeLimit' => 45],
            ['type' => 'match', 'question' => 'Capital → country', 'pairs' => [['left' => 'Paris', 'right' => 'France'], ['left' => 'Berlin', 'right' => 'Germany']], 'timeLimit' => 300],
        ];
        $original = array_map(fn (array $b): Poll => $this->deck->addPoll($room, $b), $bodies);

        $csv = $this->table->export($room, $original);
        $deck = $this->table->parseCsv($csv);

        $this->assertStringStartsWith("\xEF\xBB\xBFType,Question,Answer 1,Answer 2,Answer 3,Answer 4,Answer 5,Correct answer(s),Time limit (sec)\r\n", $csv);
        $this->assertStringContainsString('choice,Which planet is the largest?,Jupiter,"Saturn, the ringed",Earth,,,1,20', $csv);
        $this->assertSame('quiz', $deck['mode']);
        $again = array_map(fn (array $b): Poll => $this->deck->addPoll($room, $b), $deck['questions']);
        $expected = array_map([$this, 'shape'], $original);
        $expected[0]['question'] = 'Which planet is the largest?'; // a row is one line: the break becomes a space
        $this->assertSame($expected, array_map([$this, 'shape'], $again));
        $this->assertSame([20, 15, 30, 0, 60, 45, 300], array_map(static fn (Poll $p): int => $p->getTimeLimit(), $again), 'the time comes back, 0 as no limit');
    }

    public function testAPollExportsWithoutKeysAndComesBackAsAPoll(): void {
        $room = $this->room('poll');
        $original = [
            $this->deck->addPoll($room, ['type' => 'choice', 'question' => 'Tea or coffee?', 'options' => ['Tea', 'Coffee']]),
            $this->deck->addPoll($room, ['type' => 'words', 'question' => 'One word', 'maxWords' => 2]),
            $this->deck->addPoll($room, ['type' => 'scale', 'question' => 'Clear?', 'scaleMode' => 'single', 'scaleMax' => 7, 'minLabel' => 'no', 'maxLabel' => 'yes']),
        ];

        $csv = $this->table->export($room, $original);
        $deck = $this->table->parseCsv($csv);

        $this->assertStringContainsString("choice,Tea or coffee?,Tea,Coffee,,,,\r\nwords,One word,2,,,,,\r\nscale,Clear?,7,no,yes,,,\r\n", $csv);
        $this->assertSame('poll', $deck['mode']);
        $again = array_map(fn (array $b): Poll => $this->deck->addPoll($room, $b), $deck['questions']);
        $this->assertSame(array_map([$this, 'shape'], $original), array_map([$this, 'shape'], $again));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function room(string $mode): Room {
        $room = new Room();
        $room->setId(1);
        $room->setCode('ABCDEF');
        $room->setMode($mode);
        return $room;
    }

    /** A stored question without its IDs. */
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
            case 'rank':
                $out['options'] = $labels;
                break;
            case 'match':
                $match = $poll->getMatchConfig();
                $targets = array_column($match['targets'], 'label', 'id');
                $out['pairs'] = array_map(static fn (array $item): array => [$item['label'], $targets[$key['map'][$item['id']]] ?? ''], $match['items']);
                break;
            case 'words':
                $out['maxWords'] = $poll->getMaxWords();
                break;
            case 'scale':
                $scale = $poll->getScaleConfig();
                $out['scale'] = [$scale['mode'], $scale['max'], $scale['minLabel'], $scale['maxLabel']];
                break;
        }
        return $out;
    }

    /**
     * A workbook the way Excel writes the template: shared strings (or
     * inline ones), numbers as numbers, the first sheet found through the
     * workbook's relationships.
     *
     * @param array<int, array<string, mixed>> $rows row => column letter => value (string, number, or ['rich' => parts])
     */
    private function workbook(array $rows, bool $shared = true): string {
        $strings = [];
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $cells) {
            $sheet .= '<row r="' . $r . '">';
            foreach ($cells as $col => $value) {
                $ref = $col . $r;
                if (is_int($value) || is_float($value)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } elseif (is_array($value)) {
                    $runs = implode('', array_map(static fn (string $p): string => '<r><t>' . htmlspecialchars($p, ENT_XML1) . '</t></r>', $value['rich']));
                    if ($shared) {
                        $strings[] = '<si>' . $runs . '</si>';
                        $sheet .= '<c r="' . $ref . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
                    } else {
                        $sheet .= '<c r="' . $ref . '" t="inlineStr"><is>' . $runs . '</is></c>';
                    }
                } elseif ($shared) {
                    $strings[] = '<si><t>' . htmlspecialchars((string)$value, ENT_XML1) . '</t></si>';
                    $sheet .= '<c r="' . $ref . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$value, ENT_XML1) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $file = tempnam(sys_get_temp_dir(), 'vibears-xlsx');
        $this->tmp[] = $file;
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        // The template keeps an "Instructions" sheet elsewhere; the quiz sheet is the first by order, under an odd file name on purpose.
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Quiz" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet3.xml', $sheet);
        if ($shared) {
            $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">' . implode('', $strings) . '</sst>');
        }
        $zip->close();
        return (string)file_get_contents($file);
    }
}
