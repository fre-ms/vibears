<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Administration settings (Settings → Administration → ARS). The values
 * arrive as initial state and js/pulse-admin.js fills the form and saves
 * it (AdminSettingsController); without the script the form shows its
 * labels and does nothing.
 *
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('pulse', 'pulse-admin');
\OCP\Util::addStyle('pulse', 'admin');
?>
<div id="pulse-admin" class="section">
	<h2>vibeARS</h2>
	<p class="settings-hint"><?php p($l->t('Rooms — polls and quizzes with their questions, votes and nicknames — stay until their owner deletes them. The daily background job can delete rooms nobody has used for a while instead: no participant was there, and the owner did not open, edit or present the room. A deck prepared ahead of time therefore counts as used as long as its owner keeps working on it.')); ?></p>
	<form id="pulse-retention" class="pulse-admin-form">
		<p>
			<input type="checkbox" id="pulse-retention-enabled" class="checkbox">
			<label for="pulse-retention-enabled"><?php p($l->t('Delete unused rooms automatically')); ?></label>
		</p>
		<p>
			<label for="pulse-retention-days"><?php p($l->t('Delete a room after')); ?></label>
			<input type="number" id="pulse-retention-days" class="pulse-admin-days" step="1" inputmode="numeric">
			<?php p($l->t('days without any activity')); ?>
		</p>
		<p>
			<button type="submit" id="pulse-retention-save" class="button primary"><?php p($l->t('Save')); ?></button>
		</p>
		<p class="settings-hint"><?php p($l->t('Deleting a Nextcloud account always deletes its rooms, and the job always removes rooms of accounts that no longer exist and images no question refers to — whatever this setting says.')); ?></p>
	</form>
</div>
