<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Service\DeckText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The text helpers both deck file formats share. */
#[CoversClass(DeckText::class)]
class DeckTextTest extends TestCase {

    public function testOneLineJoinsLinesAndKeepsInnerSpacing(): void {
        $this->assertSame('a b c', DeckText::oneLine("  a\n  b \r\n\r\n c  "));
        $this->assertSame('two  spaces', DeckText::oneLine('two  spaces'));
        $this->assertSame('', DeckText::oneLine("\n\n"));
    }

    public function testSquashedCollapsesEveryRunOfWhitespace(): void {
        $this->assertSame('a b c', DeckText::squashed("  a\n\t b    c \u{00A0} "));
        $this->assertSame('ä ö', DeckText::squashed("ä\u{2003}ö"));
    }

    public function testNumberWritesWholeNumbersPlainAndFloatsWithoutNoise(): void {
        $this->assertSame('1989', DeckText::number(1989));
        $this->assertSame('1989', DeckText::number(1989.0));
        $this->assertSame('-3', DeckText::number('-3'));
        $this->assertSame('42.195', DeckText::number(42.195));
        $this->assertSame('0.3', DeckText::number(0.1 + 0.2));
        $this->assertSame('0.5', DeckText::number('0.5'));
        $this->assertSame('1000000000000000', DeckText::number(1e15), 'from 1e15 on the float form is kept');
    }
}
