<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCA\Vibears\Db\Room;

/**
 * The deck as a file: Markdown with a YAML header (DeckMarkdown) when no
 * question has an image, otherwise a ZIP (DeckArchive) with the Markdown as
 * deck.md and the images next to it, numbered by position (images/3.png)
 * and named by `image:` lines. The download and "Save to Files" both come
 * from here, so the two files are the same bytes.
 */
class DeckExportService {
    public function __construct(
        private DeckService $deckService,
        private DeckMarkdown $markdown,
        private DeckArchive $archive,
        private PollImageService $images,
    ) {
    }

    /**
     * @return array{content: string, ext: string, mime: string} ext is md or zip
     */
    public function export(Room $room): array {
        $polls = array_values($this->deckService->deck($room));
        $paths = [];
        $files = [];
        foreach ($polls as $i => $poll) {
            $image = $this->images->read($poll);
            if ($image === null) {
                continue;
            }
            $path = DeckArchive::IMAGES . ($i + 1) . '.' . ($image['mime'] === 'image/jpeg' ? 'jpg' : 'png');
            $paths[$poll->getId()] = $path;
            $files[$path] = $image['content'];
        }
        $markdown = $this->markdown->export($room, $polls, $paths);
        if ($files === []) {
            return ['content' => $markdown, 'ext' => 'md', 'mime' => 'text/markdown; charset=utf-8'];
        }
        return ['content' => $this->archive->build($markdown, $files), 'ext' => 'zip', 'mime' => 'application/zip'];
    }

    /**
     * The file's name without extension when it goes into Files: the title,
     * cleaned of what a file system refuses, or vibears-CODE without one.
     */
    public static function fileStem(Room $room): string {
        $title = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $room->titleOrEmpty()) ?? '';
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title, " .");
        if ($title === '') {
            return 'vibears-' . $room->getCode();
        }
        return trim(mb_substr($title, 0, 80), " .");
    }
}
