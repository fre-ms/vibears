<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCA\Vibears\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The automatic deletion of unused rooms, as the administration sets it
 * (Settings → Administration → ARS): whether the daily job deletes rooms at
 * all, and after how many days without any activity it does so.
 *
 * Off by default: a lecturer's question bank is a deck that may well rest
 * for a whole semester, and silently losing it is worse than keeping a few
 * stale rooms. Whoever wants the original behaviour (rooms gone after 30
 * days) switches it on. The other two sweeps of CleanupStaleRoomsJob —
 * rooms of deleted accounts and image files nothing refers to — do not
 * depend on this setting; they only remove what nobody can reach anyway.
 *
 * Two app config keys, readable and writable with occ as well:
 *
 *   occ config:app:set vibears retention_enabled --value 1 --type integer
 *   occ config:app:set vibears retention_days --value 90 --type integer
 *
 * The number of days is clamped to MIN_DAYS..MAX_DAYS; an unreadable or
 * unset value keeps the default. "Enabled" is any value other than 0.
 */
class Retention {
    public const KEY_ENABLED = 'retention_enabled';
    public const KEY_DAYS = 'retention_days';

    public const DEFAULT_ENABLED = false;
    /** The period the upstream app deleted after, kept as the default once switched on. */
    public const DEFAULT_DAYS = 30;
    public const MIN_DAYS = 1;
    /** Ten years: large enough for "practically never", small enough to stay a number. */
    public const MAX_DAYS = 3650;

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    public function enabled(): bool {
        try {
            return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_ENABLED, self::DEFAULT_ENABLED ? 1 : 0) !== 0;
        } catch (\Throwable) {
            return self::DEFAULT_ENABLED;
        }
    }

    public function days(): int {
        try {
            $days = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_DAYS, self::DEFAULT_DAYS);
        } catch (\Throwable) {
            return self::DEFAULT_DAYS;
        }
        return self::clampDays($days);
    }

    /** The retention period in seconds, for RoomService::cleanupStaleRooms. */
    public function maxAgeSeconds(): int {
        return $this->days() * 86_400;
    }

    /**
     * Store both values. The days are clamped, never refused: the form
     * shows what was stored.
     *
     * @return array{enabled: bool, days: int} what is stored now
     */
    public function store(bool $enabled, int $days): array {
        $days = self::clampDays($days);
        $this->appConfig->setValueInt(Application::APP_ID, self::KEY_ENABLED, $enabled ? 1 : 0);
        $this->appConfig->setValueInt(Application::APP_ID, self::KEY_DAYS, $days);
        return ['enabled' => $enabled, 'days' => $days];
    }

    /** @return array{enabled: bool, days: int, minDays: int, maxDays: int} for the settings form */
    public function view(): array {
        return [
            'enabled' => $this->enabled(),
            'days' => $this->days(),
            'minDays' => self::MIN_DAYS,
            'maxDays' => self::MAX_DAYS,
        ];
    }

    public static function clampDays(int $days): int {
        return max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
    }
}
