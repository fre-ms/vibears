<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Service\FilesService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Deck files in the account's Files (FilesService): a read stays within
 * the size limit and the account's own tree, a save lands in the folder
 * under a free name, and every failure is one message for the toast.
 */
#[CoversClass(FilesService::class)]
class FilesServiceTest extends TestCase {

    /** @var array<string, File|Folder> path => node of alice */
    private array $nodes = [];
    /** @var list<array{0: string, 1: string}> [name, content] of every newFile */
    private array $written = [];

    public function testReadsAFileBelowTheLimit(): void {
        $this->file('/Lectures/deck.md', '# deck');

        $this->assertSame('# deck', $this->service()->read('alice', 'Lectures/deck.md'));
        $this->assertSame('# deck', $this->service()->read('alice', '/Lectures/deck.md'), 'with or without the leading slash');
    }

    public function testAFolderAFileThatIsTooLargeAndAnUnknownPathAreRefused(): void {
        $this->folder('/Lectures');
        $this->file('/big.zip', '', FilesService::MAX_READ_BYTES + 1);
        $service = $this->service();

        $this->assertRefused(fn () => $service->read('alice', '/Lectures'), 'This is not a file.');
        $this->assertRefused(fn () => $service->read('alice', '/big.zip'), 'The file is too large.');
        $this->assertRefused(fn () => $service->read('alice', '/nowhere.md'), 'The file or folder was not found.');
        $this->assertRefused(fn () => $service->read('alice', '/Lectures/../../etc/passwd'), 'Invalid path.');
    }

    public function testSavesUnderAFreeName(): void {
        $this->folder('/Lectures', ['Week 3.md', 'Week 3 (2).md']);

        $path = $this->service()->save('alice', '/Lectures', 'Week 3.md', '# deck');

        $this->assertSame('/Lectures/Week 3 (3).md', $path);
        $this->assertSame([['Week 3 (3).md', '# deck']], $this->written);
    }

    public function testSaveNeedsAFolderWithWriteAccess(): void {
        $this->file('/deck.md', '# deck');
        $this->folder('/ReadOnly', [], creatable: false);
        $service = $this->service();

        $this->assertRefused(fn () => $service->save('alice', '/deck.md', 'x.md', ''), 'This is not a folder.');
        $this->assertRefused(fn () => $service->save('alice', '/ReadOnly', 'x.md', ''), 'You cannot create files in this folder.');
        $this->assertRefused(fn () => $service->save('alice', '/gone', 'x.md', ''), 'The file or folder was not found.');
        $this->assertSame([], $this->written);
    }

    public function testAWriteTheStorageRefusesIsOneMessage(): void {
        $folder = $this->folder('/Lectures');
        $folder->method('newFile')->willThrowException(new NotPermittedException());
        $service = $this->service();

        $this->assertRefused(fn () => $service->save('alice', '/Lectures', 'x.md', ''), 'The file could not be written.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function assertRefused(callable $fn, string $message): void {
        try {
            $fn();
            $this->fail('expected a refusal: ' . $message);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function service(): FilesService {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturnCallback(function (string $path) {
            if (!isset($this->nodes[$path])) {
                throw new NotFoundException($path);
            }
            return $this->nodes[$path];
        });
        $userFolder->method('getRelativePath')->willReturnCallback(static fn (string $path): string => substr($path, strlen('/alice/files')));

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(function (string $uid) use ($userFolder): Folder {
            if ($uid !== 'alice') {
                throw new NotPermittedException();
            }
            return $userFolder;
        });
        return new FilesService($root, $l10n);
    }

    private function file(string $path, string $content, ?int $size = null): File {
        $file = $this->createMock(File::class);
        $file->method('getSize')->willReturn($size ?? strlen($content));
        $file->method('getContent')->willReturn($content);
        $file->method('getPath')->willReturn('/alice/files' . $path);
        $this->nodes[$path] = $file;
        return $file;
    }

    /** @param list<string> $taken names that exist in the folder */
    private function folder(string $path, array $taken = [], bool $creatable = true): Folder&\PHPUnit\Framework\MockObject\MockObject {
        $folder = $this->createMock(Folder::class);
        $folder->method('isCreatable')->willReturn($creatable);
        $folder->method('nodeExists')->willReturnCallback(static fn (string $name): bool => in_array($name, $taken, true));
        $folder->method('newFile')->willReturnCallback(function (string $name, $content) use ($path): File {
            $this->written[] = [$name, (string)$content];
            return $this->file($path . '/' . $name, (string)$content);
        });
        $this->nodes[$path] = $folder;
        return $folder;
    }
}
