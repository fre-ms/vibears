<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Settings;

use OCA\Vibears\AppInfo\Application;
use OCA\Vibears\Service\Retention;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/**
 * The administration form (templates/admin.php): whether unused rooms are
 * deleted automatically and after how many days. The current values go to
 * the page as initial state; the template loads js/vibears-admin.js
 * (src/admin.js), which saves them through AdminSettingsController. Script
 * and style are added by the template, like the other pages do, so this
 * class stays testable without a booted server.
 */
class AdminSettings implements ISettings {
    public function __construct(
        private Retention $retention,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('retention', $this->retention->view());
        return new TemplateResponse(Application::APP_ID, 'admin', [], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
