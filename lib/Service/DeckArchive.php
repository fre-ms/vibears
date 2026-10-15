<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCP\IL10N;
use OCP\ITempManager;

/**
 * The deck file with images: a ZIP that holds `deck.md` (DeckMarkdown) and
 * the question images next to it (`images/1.png`, numbered by position),
 * which the Markdown names with `image:` lines. A deck without images stays
 * a plain .md file; this class only comes in when there is an image to
 * carry.
 *
 * Reading is lenient about how people re-pack a folder: one top-level
 * folder around everything (Finder, Explorer) is stripped, `__MACOSX` and
 * `._*` / `.DS_Store` entries are ignored, and a single `*.md` at the top
 * counts as the deck when it is not called deck.md. Everything else is a
 * refusal with a reason: no deck, too many entries, an image over the
 * upload limit, or an entry name that climbs out of the archive.
 *
 * ZipArchive works on files, not strings, so both directions go through a
 * temporary file from ITempManager.
 */
class DeckArchive {
    /** The whole archive, up and down. */
    public const MAX_BYTES = 50 * 1024 * 1024;
    /** Entries in an archive that is read: a deck has at most 100 questions. */
    public const MAX_ENTRIES = 300;
    public const MARKDOWN = 'deck.md';
    public const IMAGES = 'images/';

    private const SIGNATURE = "PK\x03\x04";

    public function __construct(
        private ITempManager $tempManager,
        private IL10N $l10n,
    ) {
    }

    /** A ZIP rather than Markdown? (The local-file-header signature.) */
    public static function isArchive(string $content): bool {
        return str_starts_with($content, self::SIGNATURE);
    }

    /**
     * @param array<string, string> $images path within the archive => bytes
     * @return string the archive
     * @throws \RuntimeException the archive could not be written
     */
    public function build(string $markdown, array $images): string {
        $path = $this->tempFile();
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('vibeARS: the deck archive could not be created');
        }
        $zip->addFromString(self::MARKDOWN, $markdown);
        foreach ($images as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $data = file_get_contents($path);
        @unlink($path);
        if ($data === false) {
            throw new \RuntimeException('vibeARS: the deck archive could not be read back');
        }
        return $data;
    }

    /**
     * @return array{markdown: string, images: array<string, string>} the deck
     *         and the images by their path relative to it
     * @throws \InvalidArgumentException with the reason
     */
    public function read(string $content): array {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('The archive is too large.'));
        }
        $path = $this->tempFile();
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            @unlink($path);
            throw new \InvalidArgumentException($this->l10n->t('The file is not a readable ZIP archive.'));
        }
        try {
            return $this->entries($zip);
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** @return array{markdown: string, images: array<string, string>} */
    private function entries(\ZipArchive $zip): array {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException($this->l10n->t('The archive holds too many files.'));
        }
        /** @var array<string, int> $files name => index, without junk and directories */
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = (string)$stat['name'];
            if ($name === '' || str_ends_with($name, '/') || self::isJunk($name)) {
                continue;
            }
            $files[$name] = $i;
        }

        $prefix = self::prefix(array_keys($files));
        $markdownName = self::markdownName(array_keys($files), $prefix);
        if ($markdownName === null) {
            throw new \InvalidArgumentException($this->l10n->t('The archive holds no deck.md.'));
        }
        $markdown = $this->entry($zip, $files[$markdownName], DeckMarkdown::MAX_BYTES, $markdownName);

        $images = [];
        $total = strlen($markdown);
        foreach ($files as $name => $index) {
            if ($name === $markdownName || !str_starts_with($name, $prefix)) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if ($relative === '' || !self::isSafe($relative)) {
                throw new \InvalidArgumentException($this->l10n->t('The archive holds an entry with an unusable name: %s', [$name]));
            }
            $bytes = $this->entry($zip, $index, PollImageService::MAX_BYTES, $name);
            $total += strlen($bytes);
            if ($total > self::MAX_BYTES) {
                throw new \InvalidArgumentException($this->l10n->t('The archive is too large.'));
            }
            $images[$relative] = $bytes;
        }
        return ['markdown' => $markdown, 'images' => $images];
    }

    /**
     * One entry, read with a ceiling: the size in the central directory is
     * not trusted, the bytes that actually come out are counted.
     */
    private function entry(\ZipArchive $zip, int $index, int $max, string $name): string {
        $data = $zip->getFromIndex($index, $max + 1);
        if ($data === false) {
            throw new \InvalidArgumentException($this->l10n->t('The archive entry %s could not be read.', [$name]));
        }
        if (strlen($data) > $max) {
            throw new \InvalidArgumentException($this->l10n->t('The archive entry %s is too large.', [$name]));
        }
        return $data;
    }

    /** macOS resource forks and the like: never a deck or an image. */
    private static function isJunk(string $name): bool {
        $base = basename($name);
        return str_starts_with($name, '__MACOSX/') || str_starts_with($base, '._') || $base === '.DS_Store' || $base === 'Thumbs.db';
    }

    /**
     * The one folder everything sits in ("Lecture 3/deck.md"), as a prefix
     * with its slash; '' when the entries sit at the top.
     *
     * @param list<string> $names
     */
    private static function prefix(array $names): string {
        $tops = [];
        foreach ($names as $name) {
            $slash = strpos($name, '/');
            if ($slash === false) {
                return '';
            }
            $tops[substr($name, 0, $slash + 1)] = true;
        }
        return count($tops) === 1 ? array_key_first($tops) : '';
    }

    /**
     * deck.md at the top (below the prefix), or the only *.md there.
     *
     * @param list<string> $names
     */
    private static function markdownName(array $names, string $prefix): ?string {
        if (in_array($prefix . self::MARKDOWN, $names, true)) {
            return $prefix . self::MARKDOWN;
        }
        $candidates = array_values(array_filter($names, static function (string $name) use ($prefix): bool {
            $relative = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : null;
            return $relative !== null && !str_contains($relative, '/') && preg_match('/\.(md|markdown)$/i', $relative) === 1;
        }));
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** A relative path that stays inside the archive. */
    private static function isSafe(string $relative): bool {
        if (str_starts_with($relative, '/') || str_contains($relative, "\\") || str_contains($relative, "\0")) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function tempFile(): string {
        $path = $this->tempManager->getTemporaryFile('.zip');
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('vibeARS: no temporary file for the deck archive');
        }
        return $path;
    }
}
