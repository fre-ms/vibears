<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\Vote;
use OCA\Vibears\Service\ItemStatistics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Item statistics (ItemStatistics::compute): difficulty, corrected
 * item-total correlation and the per-option correlation, on a deck small
 * enough to check by hand.
 *
 * Four players, three single-choice questions, option A correct everywhere:
 *   Q1: P1 A, P2 A, P3 B, P4 B      -> 2 of 4 correct
 *   Q2: P1 A, P2 A, P3 A, P4 B      -> 3 of 4
 *   Q3: P1 A, P2 B, P3 B, P4 B      -> 1 of 4
 * Totals: P1 3, P2 2, P3 1, P4 0. For Q1 the item scores are 1,1,0,0 and
 * the rest scores 2,1,1,0: r = 1 / sqrt(2) = 0.707. For Q2 and Q3 the same
 * arithmetic gives 0.75 / sqrt(0.75 * 2.75) = 0.522.
 */
#[CoversClass(ItemStatistics::class)]
class ItemStatisticsTest extends TestCase {

    public function testDifficultyAndDiscriminationOnTheHandDeck(): void {
        $stats = ItemStatistics::compute($this->handDeck(), $this->handVotes());

        $this->assertSame([1, 2, 3], array_keys($stats));
        $this->assertSame(4, $stats[1]['n']);
        $this->assertSame(2, $stats[1]['correct']);
        $this->assertSame(0.5, $stats[1]['difficulty']);
        $this->assertSame(0.707, $stats[1]['discrimination']);
        $this->assertSame(0.75, $stats[2]['difficulty']);
        $this->assertSame(0.522, $stats[2]['discrimination']);
        $this->assertSame(0.25, $stats[3]['difficulty']);
        $this->assertSame(0.522, $stats[3]['discrimination']);
    }

    public function testKeyCorrelatesPositivelyAndTheDistractorNegatively(): void {
        $stats = ItemStatistics::compute($this->handDeck(), $this->handVotes());

        // Single choice: choosing the key IS the item score, so the key's
        // figure equals the item's; the only distractor mirrors it.
        $this->assertSame(['A' => 0.707, 'B' => -0.707], $stats[1]['options']);
    }

    public function testMultipleAnswersCountMembershipPerOption(): void {
        $poll = $this->poll(1, 'multi', ['A', 'B', 'C']);
        $other = $this->poll(2, 'choice', ['A', 'B']);
        $votes = [
            1 => [
                $this->vote('p1', ['A', 'B'], true),
                $this->vote('p2', ['A'], false),
                $this->vote('p3', ['C'], false),
                $this->vote('p4', ['A', 'B'], true),
            ],
            2 => [
                $this->vote('p1', 'A', true),
                $this->vote('p2', 'A', true),
                $this->vote('p3', 'B', false),
                $this->vote('p4', 'B', false),
            ],
        ];

        $stats = ItemStatistics::compute([$poll, $other], $votes);

        // Rest scores for Q1 are 1,1,0,0. A was chosen by p1,p2,p4 (1,1,0,1),
        // B by p1,p4 (1,0,0,1), C by p3 only (0,0,1,0).
        $this->assertSame(0.577, $stats[1]['options']['A']);
        $this->assertSame(0.0, $stats[1]['options']['B']);
        $this->assertSame(-0.577, $stats[1]['options']['C']);
        $this->assertSame(0.5, $stats[1]['difficulty']);
    }

    public function testOnlyThoseWhoAnsweredCountAndMissingAnswersAreZeroInTheRest(): void {
        $deck = $this->handDeck();
        $votes = $this->handVotes();
        // P4 never answered Q2 and Q3.
        $votes[2] = array_slice($votes[2], 0, 3);
        $votes[3] = array_slice($votes[3], 0, 3);

        $stats = ItemStatistics::compute($deck, $votes);

        $this->assertSame(3, $stats[2]['n']);
        $this->assertSame(3, $stats[2]['correct']);
        $this->assertSame(1.0, $stats[2]['difficulty']);
        // Everybody who answered Q2 was right: no variance, no figure.
        $this->assertNull($stats[2]['discrimination']);
        // Q1 still has four answers; P4's rest score is 0 (nothing answered elsewhere).
        $this->assertSame(4, $stats[1]['n']);
        $this->assertSame(0.707, $stats[1]['discrimination']);
    }

    public function testNoFigureWithoutEnoughAnswersOrWithoutVariance(): void {
        $single = $this->poll(1, 'choice', ['A', 'B']);

        $none = ItemStatistics::compute([$single], []);
        $this->assertSame(['n' => 0, 'correct' => 0, 'difficulty' => null, 'discrimination' => null, 'options' => ['A' => null, 'B' => null]], $none[1]);

        $one = ItemStatistics::compute([$single], [1 => [$this->vote('p1', 'A', true)]]);
        $this->assertSame(1.0, $one[1]['difficulty']);
        $this->assertNull($one[1]['discrimination']);

        // A deck of one question: every rest score is 0, so the item-total
        // correlation is undefined even with a spread of answers.
        $alone = ItemStatistics::compute([$single], [1 => [$this->vote('p1', 'A', true), $this->vote('p2', 'B', false)]]);
        $this->assertSame(0.5, $alone[1]['difficulty']);
        $this->assertNull($alone[1]['discrimination']);
        $this->assertSame(['A' => null, 'B' => null], $alone[1]['options']);
    }

    public function testVerdictMissingFromThePayloadCountsAsWrong(): void {
        $deck = [$this->poll(1, 'number', []), $this->poll(2, 'text', [])];
        $votes = [
            1 => [$this->vote('p1', 1969, null), $this->vote('p2', 1969, true)],
            2 => [$this->vote('p1', 'Goethe', true), $this->vote('p2', 'Schiller', false)],
        ];

        $stats = ItemStatistics::compute($deck, $votes);

        $this->assertSame(1, $stats[1]['correct']);
        $this->assertSame([], $stats[1]['options'], 'a number guess has no options to correlate');
        $this->assertSame(-1.0, $stats[1]['discrimination']);
    }

    public function testCorrelation(): void {
        $this->assertSame(1.0, ItemStatistics::correlation([1, 2, 3], [2, 4, 6]));
        $this->assertSame(-1.0, ItemStatistics::correlation([1, 2, 3], [3, 2, 1]));
        $this->assertNull(ItemStatistics::correlation([1], [1]));
        $this->assertNull(ItemStatistics::correlation([1, 1], [1, 2]));
        $this->assertNull(ItemStatistics::correlation([1, 2], [1]));
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** @return Poll[] */
    private function handDeck(): array {
        return [
            $this->poll(1, 'choice', ['A', 'B']),
            $this->poll(2, 'choice', ['A', 'B']),
            $this->poll(3, 'choice', ['A', 'B']),
        ];
    }

    /** @return array<int, Vote[]> */
    private function handVotes(): array {
        $plan = [
            1 => ['p1' => 'A', 'p2' => 'A', 'p3' => 'B', 'p4' => 'B'],
            2 => ['p1' => 'A', 'p2' => 'A', 'p3' => 'A', 'p4' => 'B'],
            3 => ['p1' => 'A', 'p2' => 'B', 'p3' => 'B', 'p4' => 'B'],
        ];
        $votes = [];
        foreach ($plan as $pollId => $answers) {
            foreach ($answers as $token => $option) {
                $votes[$pollId][] = $this->vote($token, $option, $option === 'A');
            }
        }
        return $votes;
    }

    /** @param list<string> $optionIds */
    private function poll(int $id, string $type, array $optionIds): Poll {
        $poll = new Poll();
        $poll->setId($id);
        $poll->setType($type);
        $poll->setOptions(json_encode(array_map(static fn (string $o): array => ['id' => $o, 'label' => 'Option ' . $o], $optionIds)));
        return $poll;
    }

    /** @param ?bool $correct null = the key is missing from the payload */
    private function vote(string $token, mixed $value, ?bool $correct): Vote {
        $vote = new Vote();
        $vote->setVoterToken($token);
        $payload = ['value' => $value, 'points' => $correct ? 1000 : 0];
        if ($correct !== null) {
            $payload['correct'] = $correct;
        }
        $vote->setPayload(json_encode($payload));
        return $vote;
    }
}
