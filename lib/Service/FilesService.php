<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\Lock\LockedException;

/**
 * Deck files in the account's own Files: read one for an import, write
 * one for "Save to Files". Paths are relative to the account's root, as
 * the Files app and its picker name them ("/Lectures/Week 3"). Shares show
 * up there like any folder, so a deck a colleague shared is importable and
 * a folder shared with write access takes a saved deck — that is the whole
 * point. Every failure is an InvalidArgumentException with a message the
 * toast can show; the same message for "not there" and "not yours", so a
 * path cannot be probed.
 */
class FilesService {
    /** What read() hands out at most: the archive limit, the larger of the two formats. */
    public const MAX_READ_BYTES = DeckArchive::MAX_BYTES;

    public function __construct(
        private IRootFolder $rootFolder,
        private IL10N $l10n,
    ) {
    }

    /**
     * The content of a file (Markdown or the ZIP export).
     *
     * @throws \InvalidArgumentException
     */
    public function read(string $uid, string $path): string {
        $node = $this->node($uid, $path);
        if (!$node instanceof File) {
            throw new \InvalidArgumentException($this->l10n->t('This is not a file.'));
        }
        if ($node->getSize() > self::MAX_READ_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('The file is too large.'));
        }
        try {
            return $node->getContent();
        } catch (NotPermittedException | NotFoundException | LockedException) {
            throw new \InvalidArgumentException($this->l10n->t('The file could not be read.'));
        }
    }

    /**
     * Write $content as a new file $name into the folder; a name that is
     * taken gets " (2)", " (3)", … like the Files app does.
     *
     * @return string the new file's path relative to the account's root
     * @throws \InvalidArgumentException
     */
    public function save(string $uid, string $folderPath, string $name, string $content): string {
        $folder = $this->node($uid, $folderPath);
        if (!$folder instanceof Folder) {
            throw new \InvalidArgumentException($this->l10n->t('This is not a folder.'));
        }
        if (!$folder->isCreatable()) {
            throw new \InvalidArgumentException($this->l10n->t('You cannot create files in this folder.'));
        }
        $final = $this->freeName($folder, $name);
        try {
            $file = $folder->newFile($final, $content);
        } catch (NotPermittedException | InvalidPathException | LockedException) {
            throw new \InvalidArgumentException($this->l10n->t('The file could not be written.'));
        }
        $relative = $this->userFolder($uid)->getRelativePath($file->getPath());
        return $relative ?? rtrim('/' . trim($folderPath, '/'), '/') . '/' . $final;
    }

    /** @throws \InvalidArgumentException the path is unusable, or nothing is there */
    private function node(string $uid, string $path): Node {
        $path = '/' . trim(str_replace("\\", '/', $path), '/');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || str_contains($segment, "\0")) {
                throw new \InvalidArgumentException($this->l10n->t('Invalid path.'));
            }
        }
        try {
            return $this->userFolder($uid)->get($path);
        } catch (NotFoundException | InvalidPathException | NotPermittedException) {
            throw new \InvalidArgumentException($this->l10n->t('The file or folder was not found.'));
        }
    }

    /** @throws \InvalidArgumentException */
    private function userFolder(string $uid): Folder {
        try {
            return $this->rootFolder->getUserFolder($uid);
        } catch (\Throwable) {
            throw new \InvalidArgumentException($this->l10n->t('The file or folder was not found.'));
        }
    }

    /** @throws \InvalidArgumentException after a hundred taken names */
    private function freeName(Folder $folder, string $name): string {
        if (!$folder->nodeExists($name)) {
            return $name;
        }
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $ext = $dot === false ? '' : substr($name, $dot);
        for ($i = 2; $i < 100; $i++) {
            $candidate = $stem . ' (' . $i . ')' . $ext;
            if (!$folder->nodeExists($candidate)) {
                return $candidate;
            }
        }
        throw new \InvalidArgumentException($this->l10n->t('A file of that name already exists.'));
    }
}
