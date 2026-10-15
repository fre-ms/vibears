<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Listener;

use OCA\Vibears\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the small bundle that adds "Import into vibeARS" to the actions of
 * a Markdown or ZIP file in the Files app (src/files.js). Only the Files
 * app's own LoadAdditionalScriptsEvent reaches this listener, so the script
 * is never on the moderator, phone or projector pages.
 *
 * @template-implements IEventListener<Event>
 */
class FilesScriptsListener implements IEventListener {
    public function handle(Event $event): void {
        Util::addScript(Application::APP_ID, 'vibears-files');
    }
}
