<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Settings;

use OCA\Pulse\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The app's own entry in the administration settings (Settings →
 * Administration → ARS), registered in appinfo/info.xml. It carries the
 * same label as the navigation entry, so the two are found under one name.
 */
class AdminSection implements IIconSection {
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l10n->t('ARS');
    }

    public function getPriority(): int {
        return 80;
    }

    /** The black copy of the app icon: the settings sidebar sits on a light background. */
    public function getIcon(): string {
        return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
    }
}
