/**
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * The Files app: "Import into vibeARS" on a Markdown or ZIP file. Loaded
 * there by FilesScriptsListener, nowhere else. The action sends the file's
 * path to the import route; the server reads the file itself, so nothing
 * passes through the browser, and the answer is the new room, which opens.
 * Not every Markdown file is a deck — the server says so in one sentence
 * when it is not.
 */
import './util/webpack-env.js'
import axios from '@nextcloud/axios'
import { FileType, registerFileAction } from '@nextcloud/files'
import { t } from './util/l10n.js'
import { importFilesApi, roomPage } from './util/routes.js'

// img/app-dark.svg, inline: the actions menu wants the markup, not a URL.
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16">'
	+ '<path d="M2.5 1.75h11a1.75 1.75 0 0 1 1.75 1.75v6.5a1.75 1.75 0 0 1-1.75 1.75H7.2L3.9 14.4v-2.65H2.5A1.75 1.75 0 0 1 .75 10V3.5A1.75 1.75 0 0 1 2.5 1.75z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>'
	+ '<path d="M5 8.75V7.25M8 8.75V5.25M11 8.75V3.75" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'

const isDeckFile = (node) => node && node.type === FileType.File && /\.(md|markdown|zip)$/i.test(node.basename || '')

function toast(kind, text) {
	const api = window.OCP && window.OCP.Toast
	if (api && typeof api[kind] === 'function') api[kind](text)
	else if (kind === 'error') window.alert(text)
}

// @nextcloud/files 4: a plain IFileAction; every callback gets a context
// ({ nodes, view, folder, contents }), exec one with a single node.
registerFileAction({
	id: 'vibears-import',
	displayName: () => t('vibears', 'Import into vibeARS'),
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => nodes.length === 1 && isDeckFile(nodes[0]),
	async exec({ nodes }) {
		const node = nodes[0]
		try {
			const { data } = await axios.post(importFilesApi(), { path: node.path })
			toast('success', t('vibears', 'Imported: {room}', { room: data.title || data.code }))
			window.location.assign(roomPage(data.code))
			return true
		} catch (e) {
			const message = e && e.response && e.response.data && e.response.data.message
			toast('error', message || t('vibears', 'Could not import the file.'))
			return false
		}
	},
	order: 50,
})
