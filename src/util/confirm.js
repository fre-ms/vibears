/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * vibearsConfirm — promise-based replacement for window.confirm().
 * Mounts VibearsConfirm imperatively on <body> and cleans up afterwards.
 *
 *   if (!await vibearsConfirm({ title: 'Delete room?', text: '…', danger: true,
 *                            confirmLabel: 'Delete permanently' })) return
 *
 * With altLabel there is a third outcome: the promise then resolves to 'alt'.
 */
import Vue from 'vue'
import VibearsConfirm from '../components/ui/VibearsConfirm.vue'

/**
 * @param {object} [options] title, text, confirmLabel, cancelLabel, danger, altLabel
 * @return {Promise<boolean|string>} true = confirmed, false = cancelled,
 *   'alt' = third button (only with altLabel)
 */
export function vibearsConfirm(options = {}) {
	return new Promise((resolve) => {
		const mount = document.createElement('div')
		document.body.appendChild(mount)

		let settled = false
		const finish = (result) => {
			if (settled) return
			settled = true
			// $mount replaced `mount` with vm.$el -> take vm.$el out of the DOM.
			const el = vm.$el
			vm.$destroy()
			if (el && el.parentNode) el.parentNode.removeChild(el)
			resolve(result)
		}

		const vm = new Vue({
			render: (h) => h(VibearsConfirm, {
				props: { ...options },
				on: {
					confirm: () => finish(true),
					cancel: () => finish(false),
					alt: () => finish('alt'),
				},
			}),
		}).$mount(mount)
	})
}
