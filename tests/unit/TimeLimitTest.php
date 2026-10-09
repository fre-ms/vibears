<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\AnswerRules;
use OCA\Pulse\Service\CodeGenerator;
use OCA\Pulse\Service\DeckService;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\QuizService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A quiz question without a time limit (DeckService::NO_LIMIT = 0): the
 * composer offers it, the deck keeps it, and only a timed question is
 * clamped into 5..300 seconds — so a typo never means "no limit". Without
 * a clock a correct answer earns the flat base points, and the vote rules
 * never say "Time is up".
 */
#[CoversClass(DeckService::class)]
#[CoversClass(QuizService::class)]
class TimeLimitTest extends TestCase {

    public static function limits(): array {
        return [
            'no limit stays' => [0, 0],
            'below the minimum' => [1, 5],
            'the minimum' => [5, 5],
            'as requested' => [45, 45],
            'the maximum' => [300, 300],
            'above the maximum' => [301, 300],
            'negative is not "no limit"' => [-30, 5],
        ];
    }

    #[DataProvider('limits')]
    public function testStoredLimit(int $requested, int $stored): void {
        $this->assertSame($stored, DeckService::timeLimit($requested));
    }

    #[DataProvider('limits')]
    public function testAddPollKeepsNoLimitAndClampsTheRest(int $requested, int $stored): void {
        $poll = $this->deck()->addPoll($this->quiz(), ['type' => 'choice', 'question' => 'Q?', 'options' => ['A', 'B'], 'correctIndex' => 0, 'timeLimit' => $requested]);

        $this->assertSame($stored, $poll->getTimeLimit());
    }

    public function testAMissingLimitIsTheComposersDefault(): void {
        $poll = $this->deck()->addPoll($this->quiz(), ['type' => 'choice', 'question' => 'Q?', 'options' => ['A', 'B'], 'correctIndex' => 0]);

        $this->assertSame(30, $poll->getTimeLimit());
    }

    public function testAPollQuestionNeverCarriesALimit(): void {
        $room = new Room();
        $room->setId(1);
        $room->setMode('poll');
        $poll = $this->deck()->addPoll($room, ['type' => 'choice', 'question' => 'Q?', 'options' => ['A', 'B'], 'timeLimit' => 0]);

        $this->assertSame(0, $poll->getTimeLimit());
    }

    public function testWithoutALimitACorrectAnswerEarnsTheFullPointsHoweverLate(): void {
        $quiz = new QuizService();
        $this->assertSame(1000, $quiz->points(0, 0));
        $this->assertSame(1000, $quiz->points(3600, 0));
        // with a limit the speed still counts
        $this->assertSame(500, $quiz->points(30, 30));

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $poll = $this->deck()->addPoll($this->quiz(), ['type' => 'choice', 'question' => 'Q?', 'options' => ['A', 'B'], 'correctIndex' => 1, 'timeLimit' => 0]);
        $correctId = $poll->getOptionsArray()[1]['id'];
        $payload = (new AnswerRules($l10n, $quiz))->quizPayload($poll, $correctId, 900);

        $this->assertTrue($payload['correct']);
        $this->assertSame(1000, $payload['points']);
        $this->assertSame(900, $payload['elapsed']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function deck(): DeckService {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $polls = $this->createMock(PollMapper::class);
        $polls->method('insert')->willReturnArgument(0);
        $polls->method('nextPosition')->willReturn(0);
        $polls->method('countByRoom')->willReturn(0);
        $n = 0;
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('optionId')->willReturnCallback(static function () use (&$n): string {
            return 'OPT' . ++$n;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(5000);

        $deck = (new \ReflectionClass(DeckService::class))->newInstanceWithoutConstructor();
        foreach ([
            'pollMapper' => $polls,
            'codeGenerator' => $codes,
            'timeFactory' => $time,
            'l10n' => $l10n,
            'limits' => new Limits($this->createMock(IAppConfig::class)),
        ] as $name => $value) {
            (new ReflectionProperty(DeckService::class, $name))->setValue($deck, $value);
        }
        return $deck;
    }

    private function quiz(): Room {
        $room = new Room();
        $room->setId(1);
        $room->setMode('quiz');
        return $room;
    }
}
