<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Service\DeckArchive;
use OCA\Vibears\Service\DeckMarkdown;
use OCA\Vibears\Service\PollImageService;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The deck archive (DeckArchive): what build() writes, read() gives back —
 * deck.md and the images by their relative path. A re-packed folder (one
 * top-level folder, macOS junk) reads the same; an archive without a deck,
 * with too many entries, with an oversized entry or with a name that
 * climbs out is refused with its reason.
 */
#[CoversClass(DeckArchive::class)]
class DeckArchiveTest extends TestCase {

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
    }

    public function testRoundTrip(): void {
        $images = ['images/1.png' => "\x89PNG one", 'images/3.jpg' => "\xFF\xD8 three"];

        $zip = $this->archive()->build("---\nformat: \"vibears/1\"\n---\n", $images);

        $this->assertTrue(DeckArchive::isArchive($zip));
        $this->assertFalse(DeckArchive::isArchive("---\nformat: \"vibears/1\"\n---\n"));
        $read = $this->archive()->read($zip);
        $this->assertSame("---\nformat: \"vibears/1\"\n---\n", $read['markdown']);
        $this->assertSame($images, $read['images']);
    }

    public function testRepackedFolderWithJunkReadsTheSame(): void {
        $zip = $this->zip([
            '__MACOSX/Lecture 3/._deck.md' => 'resource fork',
            'Lecture 3/' => '',
            'Lecture 3/.DS_Store' => 'junk',
            'Lecture 3/deck.md' => '# deck',
            'Lecture 3/images/' => '',
            'Lecture 3/images/1.png' => 'one',
            'Lecture 3/notes/2.jpg' => 'two',
        ]);

        $read = $this->archive()->read($zip);

        $this->assertSame('# deck', $read['markdown']);
        $this->assertSame(['images/1.png' => 'one', 'notes/2.jpg' => 'two'], $read['images']);
    }

    public function testASingleMarkdownOfAnotherNameCountsAsTheDeck(): void {
        $read = $this->archive()->read($this->zip(['Week 3.md' => '# deck', 'images/1.png' => 'one']));

        $this->assertSame('# deck', $read['markdown']);
        $this->assertSame(['images/1.png' => 'one'], $read['images']);
    }

    public function testNoDeckIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The archive holds no deck.md.');

        $this->archive()->read($this->zip(['a.md' => '1', 'b.md' => '2', 'images/1.png' => 'one']));
    }

    public function testNotAnArchiveIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The file is not a readable ZIP archive.');

        $this->archive()->read("PK\x03\x04 but not really");
    }

    public function testAnEntryThatClimbsOutIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unusable name: ../etc/passwd');

        $this->archive()->read($this->zip(['deck.md' => '# deck', '../etc/passwd' => 'x']));
    }

    public function testAnOversizedImageIsRefusedByTheBytesThatComeOut(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The archive entry images/1.png is too large.');

        $this->archive()->read($this->zip(['deck.md' => '# deck', 'images/1.png' => str_repeat('x', PollImageService::MAX_BYTES + 1)]));
    }

    public function testTooManyEntriesAreRefused(): void {
        $entries = ['deck.md' => '# deck'];
        for ($i = 0; $i <= DeckArchive::MAX_ENTRIES; $i++) {
            $entries['images/' . $i . '.png'] = 'x';
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The archive holds too many files.');

        $this->archive()->read($this->zip($entries));
    }

    public function testTooLargeAnArchiveIsRefusedBeforeItIsOpened(): void {
        $temp = $this->createMock(ITempManager::class);
        $temp->expects($this->never())->method('getTemporaryFile');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The archive is too large.');

        $this->archive($temp)->read(str_repeat('x', DeckArchive::MAX_BYTES + 1));
    }

    public function testTheMarkdownKeepsTheFormatsOwnLimit(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The archive entry deck.md is too large.');

        $this->archive()->read($this->zip(['deck.md' => str_repeat('x', DeckMarkdown::MAX_BYTES + 1)]));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function archive(?ITempManager $temp = null): DeckArchive {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        if ($temp === null) {
            $temp = $this->createMock(ITempManager::class);
            $temp->method('getTemporaryFile')->willReturnCallback(function (): string {
                $file = tempnam(sys_get_temp_dir(), 'vibears-zip');
                $this->tmp[] = $file;
                return $file;
            });
        }
        return new DeckArchive($temp, $l10n);
    }

    /** A ZIP with exactly these entries (a name ending in / is a directory). */
    private function zip(array $entries): string {
        $file = tempnam(sys_get_temp_dir(), 'vibears-zip');
        $this->tmp[] = $file;
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir($name);
            } else {
                $zip->addFromString($name, $content);
            }
        }
        $zip->close();
        return (string)file_get_contents($file);
    }
}
