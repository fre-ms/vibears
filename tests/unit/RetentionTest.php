<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Tests\Unit;

use OCA\Vibears\BackgroundJob\CleanupStaleRoomsJob;
use OCA\Vibears\Controller\AdminSettingsController;
use OCA\Vibears\Service\PollImageService;
use OCA\Vibears\Service\Retention;
use OCA\Vibears\Service\RoomService;
use OCA\Vibears\Settings\AdminSection;
use OCA\Vibears\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The automatic deletion of unused rooms is a setting of the administration
 * (Retention): off by default, the number of days configurable. The daily
 * job skips the stale-room sweep while it is off and still runs its other
 * two sweeps; switched on, it deletes after exactly the configured number
 * of days. The settings controller stores both values and refuses unusable
 * input; the form classes are registered in info.xml.
 */
#[CoversClass(Retention::class)]
#[CoversClass(CleanupStaleRoomsJob::class)]
#[CoversClass(AdminSettingsController::class)]
#[CoversClass(AdminSettings::class)]
#[CoversClass(AdminSection::class)]
class RetentionTest extends TestCase {

    /** App config: key => value, as occ or the form stored it. */
    private array $config = [];
    /** @var list<array{0: string, 1: int}> setValueInt calls */
    private array $written = [];

    // ── Retention ───────────────────────────────────────────────────────────

    public function testOffByDefaultWithThirtyDays(): void {
        $retention = $this->retention();

        $this->assertFalse($retention->enabled());
        $this->assertSame(30, $retention->days());
        $this->assertSame(30 * 86_400, $retention->maxAgeSeconds());
        $this->assertSame(['enabled' => false, 'days' => 30, 'minDays' => 1, 'maxDays' => 3650], $retention->view());
    }

    public static function days(): array {
        return [
            'as set' => [90, 90],
            'the minimum' => [1, 1],
            'below the minimum' => [0, 1],
            'negative' => [-4, 1],
            'the maximum' => [3650, 3650],
            'above the maximum' => [99_999, 3650],
        ];
    }

    #[DataProvider('days')]
    public function testDaysAreClampedNeverRefused(int $stored, int $expected): void {
        $this->config[Retention::KEY_DAYS] = $stored;

        $this->assertSame($expected, $this->retention()->days());
    }

    public function testAnyNonZeroValueSwitchesItOn(): void {
        $this->config[Retention::KEY_ENABLED] = 1;
        $this->assertTrue($this->retention()->enabled());
        $this->config[Retention::KEY_ENABLED] = 7;
        $this->assertTrue($this->retention()->enabled());
        $this->config[Retention::KEY_ENABLED] = 0;
        $this->assertFalse($this->retention()->enabled());
    }

    public function testUnreadableConfigKeepsTheDefaults(): void {
        // e.g. stored with --type=string: IAppConfig throws a type conflict.
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willThrowException(new \RuntimeException('conflict'));
        $retention = new Retention($appConfig);

        $this->assertFalse($retention->enabled());
        $this->assertSame(30, $retention->days());
    }

    public function testStoreWritesBothValuesClamped(): void {
        $stored = $this->retention()->store(true, 5000);

        $this->assertSame(['enabled' => true, 'days' => 3650], $stored);
        $this->assertSame([[Retention::KEY_ENABLED, 1], [Retention::KEY_DAYS, 3650]], $this->written);
    }

    // ── The job ─────────────────────────────────────────────────────────────

    public function testWhileOffTheJobDeletesNoStaleRoomButStillSweepsTheRest(): void {
        $rooms = $this->createMock(RoomService::class);
        $rooms->expects($this->never())->method('cleanupStaleRooms');
        $rooms->expects($this->once())->method('cleanupOrphanedRooms')->willReturn(0);
        $images = $this->createMock(PollImageService::class);
        $images->expects($this->once())->method('sweepOrphans')->willReturn(0);

        $this->runJob($this->job($rooms, $images));
    }

    public function testSwitchedOnTheJobUsesTheConfiguredDays(): void {
        $this->config[Retention::KEY_ENABLED] = 1;
        $this->config[Retention::KEY_DAYS] = 45;
        $rooms = $this->createMock(RoomService::class);
        $rooms->expects($this->once())->method('cleanupStaleRooms')->with(45 * 86_400)->willReturn(0);
        $rooms->method('cleanupOrphanedRooms')->willReturn(0);

        $this->runJob($this->job($rooms, $this->createMock(PollImageService::class)));
    }

    // ── The settings controller ─────────────────────────────────────────────

    public function testTheControllerStoresWhatTheFormSends(): void {
        $response = $this->controller(['enabled' => true, 'days' => 60])->retention();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['enabled' => true, 'days' => 60], $response->getData());
        $this->assertSame([[Retention::KEY_ENABLED, 1], [Retention::KEY_DAYS, 60]], $this->written);
    }

    public function testTheControllerTakesTheFormsSpellings(): void {
        // A checkbox sends "on"/"off" or 1/0, the number may arrive as text.
        $response = $this->controller(['enabled' => 'off', 'days' => '14'])->retention();

        $this->assertSame(['enabled' => false, 'days' => 14], $response->getData());
    }

    public static function unusable(): array {
        return [
            'enabled missing' => [['days' => 30]],
            'days missing' => [['enabled' => true]],
            'enabled a list' => [['enabled' => ['x'], 'days' => 30]],
            'enabled a word' => [['enabled' => 'maybe', 'days' => 30]],
            'days a list' => [['enabled' => true, 'days' => ['x']]],
            'days text' => [['enabled' => true, 'days' => 'soon']],
            'days infinite' => [['enabled' => true, 'days' => '1e999']],
        ];
    }

    #[DataProvider('unusable')]
    public function testUnusableInputIs400AndStoresNothing(array $params): void {
        $response = $this->controller($params)->retention();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'Invalid request.'], $response->getData());
        $this->assertSame([], $this->written);
    }

    public function testTheControllerIsForAdminsOnly(): void {
        $method = new \ReflectionMethod(AdminSettingsController::class, 'retention');
        $names = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes());
        $this->assertNotContains(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class, $names);
        $this->assertNotContains(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class, $names);
        $this->assertNotContains(\OCP\AppFramework\Http\Attribute\PublicPage::class, $names);
    }

    // ── The form and its section ────────────────────────────────────────────

    public function testTheFormHandsTheValuesToThePageAsInitialState(): void {
        $this->config[Retention::KEY_ENABLED] = 1;
        $this->config[Retention::KEY_DAYS] = 10;
        $state = $this->createMock(IInitialState::class);
        $state->expects($this->once())->method('provideInitialState')
            ->with('retention', ['enabled' => true, 'days' => 10, 'minDays' => 1, 'maxDays' => 3650]);

        $form = (new AdminSettings($this->retention(), $state))->getForm();

        $this->assertSame('admin', $form->getTemplateName());
        $this->assertSame('', $form->getRenderAs(), 'a settings form renders inside the settings page');
        $this->assertSame('vibears', (new AdminSettings($this->retention(), $state))->getSection());
    }

    public function testTheSectionCarriesTheNavigationLabelAndTheDarkIcon(): void {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->with('vibears', 'app-dark.svg')->willReturn('/apps/vibears/img/app-dark.svg');
        $section = new AdminSection($l10n, $urls);

        $this->assertSame('vibears', $section->getID());
        $this->assertSame('ARS', $section->getName());
        $this->assertSame('/apps/vibears/img/app-dark.svg', $section->getIcon());
        $this->assertFileExists(dirname(__DIR__, 2) . '/img/app-dark.svg');
    }

    public function testInfoXmlRegistersFormSectionAndNavigationLabel(): void {
        $info = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');
        $this->assertStringContainsString('<admin>OCA\Vibears\Settings\AdminSettings</admin>', $info);
        $this->assertStringContainsString('<admin-section>OCA\Vibears\Settings\AdminSection</admin-section>', $info);
        $this->assertMatchesRegularExpression('#<navigation>\s*<name>ARS</name>#', $info);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function retention(): Retention {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            fn (string $app, string $key, int $default = 0): int => $this->config[$key] ?? $default,
        );
        $appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
            $this->assertSame('vibears', $app);
            $this->config[$key] = $value;
            $this->written[] = [$key, $value];
            return true;
        });
        return new Retention($appConfig);
    }

    private function job(RoomService $rooms, PollImageService $images): CleanupStaleRoomsJob {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_800_000_000);
        return new CleanupStaleRoomsJob(
            $time,
            $rooms,
            $images,
            $this->retention(),
            $this->createMock(IUserManager::class),
            $this->createMock(IConfig::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function runJob(CleanupStaleRoomsJob $job): void {
        (new \ReflectionMethod(CleanupStaleRoomsJob::class, 'run'))->invoke($job, null);
    }

    private function controller(array $params): AdminSettingsController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
        );
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        return new AdminSettingsController($request, $this->retention(), $l10n);
    }
}
