<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

/**
 * Text helpers the deck file formats share (DeckMarkdown, DeckGift): a
 * label or question on one line, and a number written without a trailing
 * ".0" or float noise.
 */
final class DeckText {
    /** One line of text: a label or question never spans lines in a file. */
    public static function oneLine(string $s): string {
        return trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $s) ?? $s);
    }

    /** One line with every run of whitespace collapsed (GIFT joins wrapped lines with a space). */
    public static function squashed(string $s): string {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** "1989" for a whole number, otherwise up to six decimals without trailing zeros. */
    public static function number(int|float|string $v): string {
        $n = (float)$v;
        if ($n === floor($n) && abs($n) < 1e15) {
            return (string)(int)$n;
        }
        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    }
}
