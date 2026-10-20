/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * vibeARS — the moderator's downloads (CSV, the deck file as Markdown or
 * ZIP, the Moodle GIFT file) with a request token.
 *
 * The export route only has #[NoAdminRequired]; Nextcloud's SecurityMiddleware
 * therefore requires a request token. An <a href download> or
 * location.href sends no header -> 412 (the same error that
 * CHANGELOG 0.17 fixed for the image route). Request::passesCSRFCheck
 * also reads `requesttoken` from the query, though, so that is where it goes.
 */
import { roomApi, roomGift, roomMarkdown, roomTable } from './routes.js'
import { getRequestToken } from '@nextcloud/auth'

/**
 * URL of the CSV export. Read the token at call time, do not keep it:
 * Nextcloud can swap it during a session.
 * @param {string} code room code
 * @param {string} [view] '' (results per question) | 'players' | 'answers' (self-paced only)
 * @return {string} URL with request token
 */
export function csvUrl(code, view = '') {
	const q = (view ? 'view=' + encodeURIComponent(view) + '&' : '')
		+ 'requesttoken=' + encodeURIComponent(getRequestToken() || '')
	return roomApi(code, '/export') + '?' + q
}

/**
 * Download a CSV (buttons without <a>). The response is a download
 * (Content-Disposition), so the page stays where it is.
 * @param {string} code room code
 * @param {string} [view] see csvUrl
 */
export function downloadCsv(code, view = '') {
	window.location.assign(csvUrl(code, view))
}

/**
 * URL of the deck file (Markdown with a YAML header), token read at call time.
 * @param {string} code room code
 * @return {string} URL with request token
 */
export function markdownUrl(code) {
	return roomMarkdown(code) + '?requesttoken=' + encodeURIComponent(getRequestToken() || '')
}

/**
 * Download the deck file (buttons without <a>).
 * @param {string} code room code
 */
export function downloadMarkdown(code) {
	window.location.assign(markdownUrl(code))
}

/**
 * URL of the Moodle GIFT file of a quiz, token read at call time.
 * @param {string} code room code
 * @return {string} URL with request token
 */
export function giftUrl(code) {
	return roomGift(code) + '?requesttoken=' + encodeURIComponent(getRequestToken() || '')
}

/**
 * Download the Moodle GIFT file (buttons without <a>).
 * @param {string} code room code
 */
export function downloadGift(code) {
	window.location.assign(giftUrl(code))
}

/**
 * URL of the deck as a CSV table, token read at call time.
 * @param {string} code room code
 * @return {string} URL with request token
 */
export function tableUrl(code) {
	return roomTable(code) + '?requesttoken=' + encodeURIComponent(getRequestToken() || '')
}

/**
 * Download the deck as a CSV table (buttons without <a>).
 * @param {string} code room code
 */
export function downloadTable(code) {
	window.location.assign(tableUrl(code))
}
