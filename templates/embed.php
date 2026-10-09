<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Embed shell for the PowerPoint/Office add-in.
 * Loads office.js (via vibears-embed.js), asks for the room code once
 * (persisted in the document settings of the .pptx) and then frames the
 * projector view /apps/vibears/screen/{code} same-origin.
 * Deliberately renders "blank" (no NC chrome) — the slide should show only the poll.
 */
// The app's translations (OC.L10N.register) — the shell is in English
// and is translated the same way as the bundle.
\OCP\Util::addTranslations(\OCA\Vibears\AppInfo\Application::APP_ID);
// Token layer + design system (own style entry point, no Vue bundle):
// the shell uses the same roles and the same .vibears-btn as the app.
\OCP\Util::addScript('vibears', 'vibears-styles');
\OCP\Util::addStyle('vibears', 'embed');
\OCP\Util::addScript('vibears', 'vibears-embed');
?>
<div id="vibears-embed-root" class="vibears-embed-loading">vibeARS …</div>
