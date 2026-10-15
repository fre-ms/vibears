/**
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Nextcloud's file picker for the deck file: a folder to save into, a
 * Markdown or ZIP file to import. The picker is the server's own
 * (OC.dialogs.filepicker, the legacy entry of @nextcloud/dialogs' FilePicker)
 * so that it looks like every other picker in this instance and shows the
 * shares the account can see; bundling @nextcloud/dialogs would bring Vue 3
 * and a megabyte along for two dialogs. The entry is deprecated upstream
 * but present in every server this app supports (34, 35); where it is
 * missing, hasFilePicker() is false and the two menu entries stay away.
 */

export function hasFilePicker() {
	return typeof window !== 'undefined' && !!(window.OC && window.OC.dialogs && typeof window.OC.dialogs.filepicker === 'function')
}

// The picker's modes; the numeric fallbacks are the values the server has
// used since the picker exists.
const TYPE_CHOOSE = () => (window.OC.dialogs.FILEPICKER_TYPE_CHOOSE ?? 1)

/**
 * Let the person pick a folder. Resolves to its path relative to the
 * account's root ("/" for the root), or to null when the picker was closed.
 * @param {string} title dialog title
 * @return {Promise<string|null>}
 */
export function pickFolder(title) {
	return pick(title, ['httpd/unix-directory'], true)
}

/**
 * Let the person pick a deck file (Markdown, or the ZIP export). Resolves
 * to its path, or to null.
 * @param {string} title dialog title
 * @return {Promise<string|null>}
 */
export function pickDeckFile(title) {
	return pick(title, ['text/markdown', 'text/plain', 'application/zip', 'application/x-zip-compressed'], false)
}

function pick(title, mimetypes, directories) {
	return new Promise((resolve) => {
		let settled = false
		const done = (path) => {
			if (settled) return
			settled = true
			resolve(typeof path === 'string' && path !== '' ? path : null)
		}
		try {
			window.OC.dialogs.filepicker(title, done, false, mimetypes, true, TYPE_CHOOSE(), directories ? '/' : undefined, {
				allowDirectoryChooser: directories,
				filter: directories ? undefined : (node) => /\.(md|markdown|zip)$/i.test(node.basename || node.name || ''),
			})
		} catch (e) {
			console.warn('vibeARS: the file picker could not be opened', e)
			done(null)
		}
		// Closing the picker without a choice calls nothing back; the
		// promise then simply never settles, and the caller's busy flag is
		// not set until a path arrives.
	})
}
