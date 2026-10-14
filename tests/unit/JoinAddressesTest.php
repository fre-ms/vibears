<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\Db\Room;
use OCA\Vibears\Db\RoomMapper;
use OCA\Vibears\Service\JoinAddresses;
use OCA\Vibears\Service\RoomService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The address of the join link (JoinAddresses, Room::joinHost): an instance
 * reachable under several names lets the moderator choose one per room.
 * The list is the current host first, then trusted_domains without
 * wildcards, each once; the scheme follows the request for the current
 * host, overwriteprotocol where set, otherwise https — http only for names
 * that live inside a network. A room may only store a configured address;
 * an address that was configured once and is gone falls back to the page's
 * own, never to a broken link.
 */
#[CoversClass(JoinAddresses::class)]
#[CoversClass(RoomService::class)]
class JoinAddressesTest extends TestCase {

    /** @var array<string, mixed> system config */
    private array $system = ['trusted_domains' => ['cloud.example.com', 'ARS.example.com', '*.example.com', 'intern.local', 'cloud.example.com', '', 'bad host']];
    private string $host = 'cloud.example.com';
    private string $protocol = 'https';

    public function testCurrentHostFirstThenTrustedDomainsWithoutWildcardsEachOnce(): void {
        $this->host = 'Lecture.Example.com';

        $this->assertSame(['lecture.example.com', 'cloud.example.com', 'ars.example.com', 'intern.local'], $this->addresses()->hosts());
    }

    public function testCurrentHostIsNotRepeatedWhenConfigured(): void {
        $this->assertSame(['cloud.example.com', 'ars.example.com', 'intern.local'], $this->addresses()->hosts());
    }

    public function testUnusableConfigYieldsOnlyTheCurrentHost(): void {
        $this->system['trusted_domains'] = 'cloud.example.com'; // a string instead of a list

        $this->assertSame(['cloud.example.com'], $this->addresses()->hosts());
    }

    public function testAllowedIsCaseInsensitive(): void {
        $a = $this->addresses();
        $this->assertTrue($a->isAllowed('ARS.example.com'));
        $this->assertTrue($a->isAllowed(' intern.local '));
        $this->assertFalse($a->isAllowed('evil.example.com'));
        $this->assertFalse($a->isAllowed('*.example.com'));
    }

    public static function schemes(): array {
        return [
            'current host, http request' => ['http', '', 'cloud.example.com', 'http://cloud.example.com'],
            'other configured host' => ['http', '', 'ars.example.com', 'https://ars.example.com'],
            'overwriteprotocol wins' => ['https', 'http', 'ars.example.com', 'http://ars.example.com'],
            '.local stays http' => ['https', '', 'intern.local', 'http://intern.local'],
        ];
    }

    #[DataProvider('schemes')]
    public function testScheme(string $protocol, string $forced, string $host, string $origin): void {
        $this->protocol = $protocol;
        $this->system['overwriteprotocol'] = $forced;

        $this->assertSame($origin, $this->addresses()->originFor($host));
    }

    public static function localNames(): array {
        return [
            ['localhost', true], ['localhost:8080', true], ['127.0.0.1', true], ['[::1]:8443', true],
            ['nextcloud.local', true], ['pc.localhost', true], ['10.0.0.5', true], ['192.168.1.20:8080', true], ['172.16.0.1', true],
            ['172.32.0.1', false], ['cloud.example.com', false], ['10example.com', false],
        ];
    }

    #[DataProvider('localNames')]
    public function testLocalNames(string $host, bool $local): void {
        $this->assertSame($local, JoinAddresses::isLocalName($host));
    }

    public function testJoinOriginFallsBackForUnsetOrVanishedAddresses(): void {
        $a = $this->addresses();
        $this->assertNull($a->joinOrigin(null));
        $this->assertNull($a->joinOrigin(''));
        $this->assertNull($a->joinOrigin('gone.example.com'), 'removed from trusted_domains since');
        $this->assertSame('https://ars.example.com', $a->joinOrigin('ars.example.com'));
    }

    public function testViewForTheModeratorPage(): void {
        $this->system['trusted_domains'] = ['cloud.example.com', 'ars.example.com'];

        $this->assertSame([
            'hosts' => [
                ['host' => 'cloud.example.com', 'origin' => 'https://cloud.example.com'],
                ['host' => 'ars.example.com', 'origin' => 'https://ars.example.com'],
            ],
            'current' => 'cloud.example.com',
        ], $this->addresses()->view());
    }

    // ── RoomService::setJoinHost ────────────────────────────────────────────

    public function testTheRoomStoresAConfiguredAddressLowerCased(): void {
        [$service, $room, $updated] = $this->roomService();

        $service->setJoinHost($room, ' ARS.example.com ');

        $this->assertSame('ars.example.com', $room->getJoinHost());
        $this->assertSame(1, $updated());
        $this->assertSame('ars.example.com', $room->jsonSerialize()['joinHost']);
    }

    public function testAnEmptyAddressMeansThePagesOwn(): void {
        [$service, $room, $updated] = $this->roomService();
        $room->setJoinHost('ars.example.com');

        $service->setJoinHost($room, '');

        $this->assertNull($room->getJoinHost());
        $this->assertSame('', $room->jsonSerialize()['joinHost']);
        $this->assertSame(1, $updated());
    }

    public function testAnUnknownAddressIsRefusedAndNothingWritten(): void {
        [$service, $room, $updated] = $this->roomService();

        try {
            $service->setJoinHost($room, 'evil.example.com');
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('This address is not one of the configured addresses of this Nextcloud.', $e->getMessage());
        }
        $this->assertNull($room->getJoinHost());
        $this->assertSame(0, $updated());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function addresses(): JoinAddresses {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(fn (string $key, mixed $default = ''): mixed => $this->system[$key] ?? $default);
        $config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = ''): string => (string)($this->system[$key] ?? $default));
        $request = $this->createMock(IRequest::class);
        $request->method('getServerHost')->willReturnCallback(fn (): string => $this->host);
        $request->method('getServerProtocol')->willReturnCallback(fn (): string => $this->protocol);
        return new JoinAddresses($config, $request);
    }

    /** @return array{0: RoomService, 1: Room, 2: callable(): int} service, a room, how often the row was written */
    private function roomService(): array {
        $writes = 0;
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('update')->willReturnCallback(function (Room $r) use (&$writes): Room {
            $writes++;
            return $r;
        });
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $service = (new \ReflectionClass(RoomService::class))->newInstanceWithoutConstructor();
        foreach (['roomMapper' => $mapper, 'l10n' => $l10n, 'joinAddresses' => $this->addresses()] as $name => $value) {
            (new ReflectionProperty(RoomService::class, $name))->setValue($service, $value);
        }
        $room = new Room();
        $room->setId(5);
        return [$service, $room, static function () use (&$writes): int {
            return $writes;
        }];
    }
}
