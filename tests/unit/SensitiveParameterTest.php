<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Db\Room;
use OCA\Vibears\Service\QuizService;
use OCA\Vibears\Service\VoteService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Voter tokens, answers and nicknames stay out of exception traces
 * (security review I5). Nextcloud's log keeps the string arguments of every
 * frame of a logged exception, so a failed vote used to write the voter's
 * cookie secret and answer into nextcloud.log, where it outlived the room.
 * `#[\SensitiveParameter]` makes PHP replace such an argument with a
 * SensitiveParameterValue in the trace.
 *
 * A guard over lib/: every parameter with one of the names below carries the
 * attribute — a new method taking a token or a nickname cannot forget it.
 * Plus the vote values, answers and token-keyed arrays that go by generic
 * names, and one real trace.
 */
#[CoversNothing]
class SensitiveParameterTest extends TestCase {

    /** Parameter names that always hold a voter token, a nickname or an answer. */
    private const ALWAYS = ['voterToken', 'meToken', 'nickname', 'answer'];

    /** Generic names that hold such data in these methods. */
    private const EXPLICIT = [
        ['OCA\Vibears\Controller\PublicVoteController', 'stateWithVersion', 'token'],
        ['OCA\Vibears\Controller\PublicVoteController', 'setVoterCookie', 'token'],
        ['OCA\Vibears\Service\CodeGenerator', 'isVoterToken', 's'],
        ['OCA\Vibears\Service\VoteService', 'recordVote', 'value'],
        ['OCA\Vibears\Service\VoteService', 'recordQuizVote', 'value'],
        ['OCA\Vibears\Service\VoteService', 'recordSelfVote', 'value'],
        ['OCA\Vibears\Service\VoteService', 'selfPayload', 'normalized'],
        ['OCA\Vibears\Service\VoteService', 'correctSelfVote', 'normalized'],
        ['OCA\Vibears\Service\VoteService', 'correctQuizVote', 'payload'],
        ['OCA\Vibears\Service\VoteService', 'settleText', 'data'],
        ['OCA\Vibears\Service\VoteService', 'forViewer', 'rows'],
        ['OCA\Vibears\Service\AnswerRules', 'normalizeValue', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'choice', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'multi', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'rank', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'matching', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'number', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'text', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'scale', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'words', 'value'],
        ['OCA\Vibears\Service\AnswerRules', 'quizPayload', 'normalized'],
        ['OCA\Vibears\Service\PaceService', 'isFinal', 'payload'],
        ['OCA\Vibears\Service\PaceStateService', 'answerText', 'value'],
        ['OCA\Vibears\Service\QuizService', 'leaderboard', 'pointsByToken'],
        ['OCA\Vibears\Service\QuizService', 'leaderboard', 'correctByToken'],
        ['OCA\Vibears\Service\QuizService', 'leaderboard', 'timeByToken'],
        ['OCA\Vibears\Service\TallyService', 'nameKey', 's'],
        ['OCA\Vibears\Service\TallyService', 'namesClash', 'a'],
        ['OCA\Vibears\Service\TallyService', 'namesClash', 'b'],
        ['OCA\Vibears\Service\TallyService', 'clashIn', 'name'],
        ['OCA\Vibears\Service\TallyService', 'clashIn', 'others'],
    ];

    public function testEveryTokenNicknameAndAnswerParameterIsMarked(): void {
        $seen = 0;
        $missing = [];
        foreach ($this->libClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $p) {
                    if (!in_array($p->getName(), self::ALWAYS, true)) {
                        continue;
                    }
                    $seen++;
                    if (!self::marked($p)) {
                        $missing[] = $class . '::' . $method->getName() . '($' . $p->getName() . ')';
                    }
                }
            }
        }
        $this->assertGreaterThan(40, $seen, 'the guard found the parameters it is about');
        $this->assertSame([], $missing);
    }

    public static function explicit(): array {
        $out = [];
        foreach (self::EXPLICIT as [$class, $method, $param]) {
            $out[substr($class, strrpos($class, '\\') + 1) . '::' . $method . '($' . $param . ')'] = [$class, $method, $param];
        }
        return $out;
    }

    #[DataProvider('explicit')]
    public function testGenericallyNamedSensitiveParameterIsMarked(string $class, string $method, string $param): void {
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $p) {
            if ($p->getName() === $param) {
                $this->assertTrue(self::marked($p));
                return;
            }
        }
        $this->fail("$class::$method has no \$$param");
    }

    public function testTheTraceHidesTokenAndNickname(): void {
        if (ini_get('zend.exception_ignore_args') === '1') {
            $this->markTestSkipped('zend.exception_ignore_args=1: traces carry no arguments at all');
        }
        $service = (new \ReflectionClass(VoteService::class))->newInstanceWithoutConstructor();
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        (new ReflectionProperty(VoteService::class, 'l10n'))->setValue($service, $l10n);
        $room = new Room();
        $room->setMode('poll');

        try {
            $service->quizJoin($room, 'SecretTokenSecretTokenSecretToke', 'Anna Secret', '192.0.2.1');
            $this->fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            $frame = $e->getTrace()[0];
            $this->assertSame('quizJoin', $frame['function']);
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][1]);
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][2]);
            $this->assertSame('192.0.2.1', $frame['args'][3], 'the address is no secret; the brute-force log has it anyway');
            $this->assertStringNotContainsString('SecretToken', $e->getTraceAsString());
            $this->assertStringNotContainsString('Anna Secret', $e->getTraceAsString());
        }
    }

    public function testTheTraceHidesTokenKeyedArrays(): void {
        if (ini_get('zend.exception_ignore_args') === '1') {
            $this->markTestSkipped('zend.exception_ignore_args=1: traces carry no arguments at all');
        }
        $quiz = (new \ReflectionClass(QuizService::class))->newInstanceWithoutConstructor();
        try {
            // A player that is no Player: the TypeError carries the frame's arguments.
            $quiz->leaderboard([new \stdClass()], ['SecretTokenSecretTokenSecretToke' => 5], [], []);
            $this->fail('Error expected');
        } catch (\Error $e) {
            $frame = $e->getTrace()[0];
            $this->assertSame('leaderboard', $frame['function']);
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][1]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function marked(\ReflectionParameter $p): bool {
        return $p->getAttributes(\SensitiveParameter::class) !== [];
    }

    /** @return list<class-string> every class, interface and trait under lib/ */
    private function libClasses(): array {
        $lib = dirname(__DIR__, 2) . '/lib';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($lib) + 1, -4);
            if (str_starts_with($rel, 'Migration/')) {
                continue;
            }
            $class = 'OCA\\Vibears\\' . str_replace('/', '\\', $rel);
            if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
                $out[] = $class;
            }
        }
        sort($out);
        return $out;
    }
}
