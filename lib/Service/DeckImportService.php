<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCA\Vibears\Db\Room;
use OCA\Vibears\Db\RoomMapper;
use OCP\IL10N;

/**
 * Import of a deck file (DeckMarkdown, or the ZIP export with images —
 * DeckArchive): a new room with the file's flow settings and questions.
 * Every question goes through DeckService::addPoll, so the caps (questions
 * per room, option lengths, …) and the per-type rules apply exactly as in
 * the composer; an image goes through PollImageService like an upload, so
 * the image budget and the re-encoding apply as well; the room itself
 * counts against the rooms-per-account cap in RoomService::createRoom. A
 * file that fails on its seventh question leaves nothing behind: the
 * half-filled room is deleted again (with the images stored so far) and the
 * error names the question.
 */
class DeckImportService {
    public function __construct(
        private DeckMarkdown $format,
        private DeckArchive $archive,
        private DeckGift $gift,
        private DeckTable $table,
        private RoomService $roomService,
        private DeckService $deckService,
        private PollImageService $images,
        private RoomMapper $roomMapper,
        private IL10N $l10n,
    ) {
    }

    /**
     * @param string $content the file: Markdown with its YAML header, the ZIP
     *        export, an .xlsx workbook (Kahoot!'s template), a CSV table or a
     *        Moodle GIFT file. A ZIP is a workbook when it holds one, a text
     *        is Markdown by its header, a table by a header row with a
     *        "Question" column, and GIFT otherwise.
     * @throws \InvalidArgumentException the file, a cap, or a question (with its number)
     */
    public function import(string $uid, string $content): Room {
        $images = null;
        if (DeckArchive::isArchive($content) && $this->table->isXlsx($content)) {
            $deck = $this->table->parseXlsx($content);
        } else {
            if (DeckArchive::isArchive($content)) {
                $read = $this->archive->read($content);
                $content = $read['markdown'];
                $images = $read['images'];
            }
            if (self::isMarkdown($content)) {
                $deck = $this->format->parse($content);
            } elseif (DeckTable::looksLikeCsv($content)) {
                $deck = $this->table->parseCsv($content);
            } else {
                $deck = $this->gift->parse($content);
            }
        }
        $room = $this->roomService->createRoom($uid, $deck['mode'], $deck['title']);
        try {
            $this->applyFlow($room, $deck);
            foreach ($deck['questions'] as $i => $body) {
                $image = $body['image'] ?? null;
                unset($body['image']);
                try {
                    $poll = $this->deckService->addPoll($room, $body);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException($this->l10n->t('Question %1$d: %2$s', [$i + 1, $e->getMessage()]), 0, $e);
                }
                if ($image !== null) {
                    $this->images->storeBytes($poll, $this->imageBytes($images, (string)$image, $i + 1));
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

    /** The Markdown deck file starts with its YAML header; everything else is GIFT. */
    public static function isMarkdown(string $content): bool {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        return str_starts_with(ltrim($content), '---');
    }

    /**
     * The bytes an `image:` line points at. A plain .md names an image it
     * cannot carry; the ZIP export does.
     *
     * @param ?array<string, string> $images the archive's files, null for a plain .md
     * @throws \InvalidArgumentException
     */
    private function imageBytes(?array $images, string $path, int $number): string {
        if ($images === null) {
            throw new \InvalidArgumentException($this->l10n->t('Question %1$d: the file names an image ("%2$s") — import the ZIP export, which carries it.', [$number, $path]));
        }
        $key = ltrim(str_replace("\\", '/', $path), '/');
        if (str_starts_with($key, './')) {
            $key = substr($key, 2);
        }
        if (!isset($images[$key])) {
            throw new \InvalidArgumentException($this->l10n->t('Question %1$d: the image "%2$s" is not in the archive.', [$number, $path]));
        }
        return $images[$key];
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
