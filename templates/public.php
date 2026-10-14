<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

/** Public participant interface (no account needed). */
\OCP\Util::addStyle('vibears', 'public');
// KaTeX's stylesheet and fonts for formulas in questions (css/katex/, build/vendor-katex.js).
\OCP\Util::addStyle('vibears', 'katex/katex.min');
\OCP\Util::addScript('vibears', 'vibears-public');
?>
<div id="vibears-public-app"></div>
