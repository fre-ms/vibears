<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\Vote;

/**
 * Item statistics of a quiz deck: per question the classic figures of a
 * test analysis, computed from the stored verdicts (payload.correct) of the
 * votes. Nothing here depends on the speed points: the figures count correct
 * answers, as an exam analysis would.
 *
 * - n: how many answered the question.
 * - difficulty p: share of correct answers among those who answered, 0 to 1
 *   (a high value means an easy question).
 * - discrimination r_it: the corrected item-total correlation, i.e. the
 *   point-biserial correlation between the item score (1 or 0) and the number
 *   of correct answers on the OTHER questions of the deck, over those who
 *   answered the item. A question someone did not answer counts as 0 in
 *   that rest score. Null with fewer than two answers, or without variance
 *   on either side (everybody right, everybody wrong, a deck of one question).
 * - options: for choice, true/false and multiple answers the same
 *   correlation for "chose this option", per option (distractor analysis).
 *   The key should correlate positively, a good distractor negatively; a
 *   distractor nobody chooses has no figure.
 *
 * Pure computation without the database; the caller fetches the votes.
 * Figures are rounded to three decimals.
 */
final class ItemStatistics {
    /** Types whose vote value is one option ID or a list of them. */
    public const OPTION_TYPES = ['choice', 'truefalse', 'multi'];

    /**
     * @param Poll[] $polls the deck (the order does not matter)
     * @param array<int, Vote[]> $votesByPoll votes per poll ID
     * @return array<int, array{n:int, correct:int, difficulty:?float, discrimination:?float, options:array<string, ?float>}> per poll ID
     */
    public static function compute(array $polls, array $votesByPoll): array {
        /** @var array<int, array<string, int>> $scores poll ID => token => 1|0 */
        $scores = [];
        /** @var array<int, array<string, array<string, true>>> $chosen poll ID => token => option IDs */
        $chosen = [];
        /** @var array<string, int> $total token => correct answers in the deck */
        $total = [];

        foreach ($polls as $poll) {
            $id = $poll->getId();
            $scores[$id] = [];
            $chosen[$id] = [];
            foreach ($votesByPoll[$id] ?? [] as $vote) {
                $token = $vote->getVoterToken();
                $data = json_decode($vote->getPayload(), true);
                $data = is_array($data) ? $data : [];
                $score = ($data['correct'] ?? false) === true ? 1 : 0;
                $scores[$id][$token] = $score;
                $total[$token] = ($total[$token] ?? 0) + $score;
                $chosen[$id][$token] = self::optionSet($data['value'] ?? null);
            }
        }

        $out = [];
        foreach ($polls as $poll) {
            $id = $poll->getId();
            $tokens = array_keys($scores[$id]);
            $item = [];
            $rest = [];
            foreach ($tokens as $token) {
                $item[] = $scores[$id][$token];
                $rest[] = ($total[$token] ?? 0) - $scores[$id][$token];
            }
            $n = count($tokens);
            $correct = (int)array_sum($item);

            $options = [];
            if (in_array($poll->getType(), self::OPTION_TYPES, true)) {
                foreach ($poll->getOptionsArray() as $option) {
                    $optionId = (string)($option['id'] ?? '');
                    if ($optionId === '') {
                        continue;
                    }
                    $x = [];
                    foreach ($tokens as $token) {
                        $x[] = isset($chosen[$id][$token][$optionId]) ? 1 : 0;
                    }
                    $options[$optionId] = self::correlation($x, $rest);
                }
            }

            $out[$id] = [
                'n' => $n,
                'correct' => $correct,
                'difficulty' => $n > 0 ? round($correct / $n, 3) : null,
                'discrimination' => self::correlation($item, $rest),
                'options' => $options,
            ];
        }
        return $out;
    }

    /**
     * Pearson correlation of two lists of the same length, rounded to three
     * decimals; null when it is undefined (fewer than two pairs, or no
     * variance on one side).
     *
     * @param list<int|float> $x
     * @param list<int|float> $y
     */
    public static function correlation(array $x, array $y): ?float {
        $n = count($x);
        if ($n < 2 || $n !== count($y)) {
            return null;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mx;
            $dy = $y[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 0.0 || $syy <= 0.0) {
            return null;
        }
        return round($sxy / sqrt($sxx * $syy), 3);
    }

    /**
     * The option IDs a vote value names: one ID (choice, true/false) or a
     * list of them (multiple answers). Anything else (a number, a text, a
     * ranking's order) is not an option choice and gives an empty set.
     *
     * @return array<string, true>
     */
    private static function optionSet(mixed $value): array {
        $ids = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $set = [];
        foreach ($ids as $optionId) {
            if (is_string($optionId) || is_int($optionId)) {
                $set[(string)$optionId] = true;
            }
        }
        return $set;
    }
}
