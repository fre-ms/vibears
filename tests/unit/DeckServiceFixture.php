<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Db\Poll;
use OCA\Vibears\Db\PollMapper;
use OCA\Vibears\Service\CodeGenerator;
use OCA\Vibears\Service\DeckService;
use OCA\Vibears\Service\Limits;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use ReflectionProperty;

/**
 * A real DeckService without a database, for the deck file tests: polls
 * get ascending IDs from a stand-in mapper, option IDs are ID001, ID002, …
 * so that two stores of the same question compare equal, and the caps are
 * the defaults. The file formats (Markdown, GIFT) are tested through
 * DeckService::addPoll on purpose — what the import stores is what the
 * composer would store.
 */
trait DeckServiceFixture {
    private int $nextId = 1;

    /** An IL10N that returns the English source string, placeholders filled in. */
    private function englishL10n(): IL10N {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        return $l10n;
    }

    private function deckService(IL10N $l10n): DeckService {
        $polls = $this->createMock(PollMapper::class);
        $polls->method('insert')->willReturnCallback(function (Poll $poll): Poll {
            $poll->setId($this->nextId++);
            return $poll;
        });
        $polls->method('nextPosition')->willReturn(0);
        $polls->method('countByRoom')->willReturn(0);

        $n = 0;
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('optionId')->willReturnCallback(static function () use (&$n): string {
            return 'ID' . str_pad((string)(++$n), 3, '0', STR_PAD_LEFT);
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
}
