<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCA\Vibears\Db\Room;
use OCA\Vibears\Db\RoomMapper;
use OCP\IL10N;

/**
 * Import of a deck file (DeckMarkdown): a new room with the file's flow
 * settings and questions. Every question goes through DeckService::addPoll,
 * so the caps (questions per room, option lengths, …) and the per-type
 * rules apply exactly as in the composer; the room itself counts against
 * the rooms-per-account cap in RoomService::createRoom. A file that fails
 * on its seventh question leaves nothing behind: the half-filled room is
 * deleted again and the error names the question.
 */
class DeckImportService {
    public function __construct(
        private DeckMarkdown $format,
        private RoomService $roomService,
        private DeckService $deckService,
        private RoomMapper $roomMapper,
        private IL10N $l10n,
    ) {
    }

    /**
     * @throws \InvalidArgumentException the file, a cap, or a question (with its number)
     */
    public function import(string $uid, string $markdown): Room {
        $deck = $this->format->parse($markdown);
        $room = $this->roomService->createRoom($uid, $deck['mode'], $deck['title']);
        try {
            $this->applyFlow($room, $deck);
            foreach ($deck['questions'] as $i => $body) {
                try {
                    $this->deckService->addPoll($room, $body);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException($this->l10n->t('Question %1$d: %2$s', [$i + 1, $e->getMessage()]), 0, $e);
                }
            }
        } catch (\Throwable $e) {
            try {
                $this->roomService->deleteRoom($room);
            } catch (\Throwable) {
                // the original error is the one worth reporting
            }
            throw $e;
        }
        return $room;
    }

    /**
     * The quiz flow from the header, written like RoomService::fillCopy:
     * only when something differs from a fresh room's defaults.
     *
     * @param array{revealAtEnd:bool, pace:string, timed:bool, feedback:string} $deck
     */
    private function applyFlow(Room $room, array $deck): void {
        if ($room->getMode() !== 'quiz') {
            return;
        }
        $changed = false;
        if ($deck['revealAtEnd']) {
            $room->setRevealAtEnd(true);
            $changed = true;
        }
        if ($deck['pace'] !== $room->getPace() || $deck['timed'] !== $room->getTimed() || $deck['feedback'] !== $room->getFeedback()) {
            $room->setPace($deck['pace']);
            $room->setTimed($deck['timed']);
            $room->setFeedback($deck['feedback']);
            $changed = true;
        }
        if ($changed) {
            $this->roomMapper->update($room);
        }
    }
}
