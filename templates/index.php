<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/** Moderator interface (signed-in users). */
\OCP\Util::addScript('vibears', 'vibears-main');
// The app's CSS is injected by the JS bundle (vue-style-loader); only KaTeX's
// stylesheet and fonts are static files (css/katex/, build/vendor-katex.js).
\OCP\Util::addStyle('vibears', 'katex/katex.min');
?>
<div id="vibears-app"></div>
