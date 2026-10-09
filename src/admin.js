/**
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Administration settings (templates/admin.php): the automatic deletion of
 * unused rooms. Plain DOM, no Vue — a checkbox, a number and a button do
 * not need a framework, and the settings page should not load one.
 */
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { adminRetentionApi } from './util/routes.js'
import { t } from './util/l10n.js'
import { showError, showSuccess, serverMessage } from './toast.js'

const state = loadState('pulse', 'retention', { enabled: false, days: 30, minDays: 1, maxDays: 3650 })
const form = document.getElementById('pulse-retention')
const enabled = document.getElementById('pulse-retention-enabled')
const days = document.getElementById('pulse-retention-days')
const save = document.getElementById('pulse-retention-save')

if (form && enabled && days && save) {
	enabled.checked = !!state.enabled
	days.value = String(state.days)
	days.min = String(state.minDays)
	days.max = String(state.maxDays)
	const reflect = () => { days.disabled = !enabled.checked }
	reflect()
	enabled.addEventListener('change', reflect)

	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		const wanted = Math.round(Number(days.value))
		if (!Number.isFinite(wanted) || wanted < state.minDays || wanted > state.maxDays) {
			showError(t('pulse', 'Enter a number of days between {min} and {max}.', { min: state.minDays, max: state.maxDays }))
			days.focus()
			return
		}
		save.disabled = true
		try {
			const { data } = await axios.post(adminRetentionApi(), { enabled: enabled.checked, days: wanted })
			enabled.checked = !!data.enabled
			days.value = String(data.days)
			reflect()
			showSuccess(data.enabled
				? t('pulse', 'Saved. Rooms unused for {days} days are deleted by the daily job.', { days: data.days })
				: t('pulse', 'Saved. Rooms are kept until their owner deletes them.'))
		} catch (e) {
			showError(serverMessage(e, t('pulse', 'Could not save.')))
		} finally {
			save.disabled = false
		}
	})
}
