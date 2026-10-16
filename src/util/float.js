/**
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * The floating results window: the live result in a small window that the
 * browser keeps above every other window — above a full-screen slide show
 * of Impress, Keynote or a PDF viewer too — so a lecture can run in any
 * presentation programme with vibeARS's result floating in a corner of the
 * projector. Nothing is installed on the presenting computer.
 *
 * Two ways, by browser (floatMode):
 *   - 'document': document picture-in-picture (Chromium). A real window with
 *     the projector page itself inside, scaled down (openDocumentFloat) —
 *     every stage, countdown and leaderboard as on the big screen.
 *   - 'video': video picture-in-picture (Safari, Chromium without the
 *     above). The result is painted on a canvas (paintCard), the canvas
 *     streamed into a <video>, and that video floated. A compact card:
 *     question, the bars of a choice question, answers, countdown.
 *   - 'inline': Firefox has no API for it, only its own button on every
 *     video: the same video stays on the page with a hint.
 *
 * The pure parts (mode, scale, the card's content and layout, the text
 * wrap) are tested in dev/unit/float.test.mjs; painting and the windows
 * need a browser (FloatResults.vue).
 */
import { plainText } from './richtext.js'
import { option } from './palette.js'
import { t, n } from './l10n.js'

/** The projector's kiosk size, as ScreenPreview scales it. */
export const KIOSK_W = 1280
export const KIOSK_H = 720
/** The painted card (16:9); the floating video shows it at any size. */
export const CARD_W = 960
export const CARD_H = 540

const BAR_TYPES = ['choice', 'truefalse', 'multi']
const MAX_ROWS = 8

/**
 * Which way this browser can float the result.
 * @param {object} [win] the window (a stand-in in tests)
 * @return {'document'|'video'|'inline'|'none'}
 */
export function floatMode(win = typeof window !== 'undefined' ? window : null) {
	if (!win || !win.document) return 'none'
	const dpip = win.documentPictureInPicture
	if (dpip && typeof dpip.requestWindow === 'function') return 'document'
	const canvas = win.HTMLCanvasElement && win.HTMLCanvasElement.prototype
	const video = win.HTMLVideoElement && win.HTMLVideoElement.prototype
	if (!canvas || typeof canvas.captureStream !== 'function' || !video) return 'none'
	const api = typeof video.requestPictureInPicture === 'function' || typeof video.webkitSetPresentationMode === 'function'
	if (api && win.document.pictureInPictureEnabled !== false) return 'video'
	return 'inline'
}

/**
 * The scale that fits the kiosk into a box, 0 for an unusable box.
 * @param {number} w box width
 * @param {number} h box height
 */
export function fitScale(w, h, kw = KIOSK_W, kh = KIOSK_H) {
	const k = Math.min(w / kw, h / kh)
	return Number.isFinite(k) && k > 0 ? k : 0
}

/** Revealed the way the projector reads it (Screen.vue). */
export function revealedOf(poll) {
	if (!poll) return false
	if (typeof poll.revealed === 'boolean') return poll.revealed
	return ['locked', 'ended'].includes(poll.status)
}

function correctIds(poll) {
	const ids = []
	if (poll.correctOption) ids.push(poll.correctOption)
	const multi = poll.answerKey && Array.isArray(poll.answerKey.correct) ? poll.answerKey.correct : []
	return ids.concat(multi.map(String))
}

/**
 * The card's content from the projector's (or the moderator's) state: the
 * question as plain text, up to eight rows for a choice question with the
 * same letters and colours as everywhere else, counts, the answered total
 * and the countdown. Other question types get a note instead of rows.
 *
 * @param {object} s
 * @param {?object} s.poll the current question (public shape), null in the lobby
 * @param {?object} s.results the tally ({total, results: [{id, label, count}]})
 * @param {number} [s.answered] how many have answered
 * @param {?number} [s.remaining] seconds left, null without a countdown
 * @param {?boolean} [s.revealed] revealed; computed from the poll when omitted
 * @return {{question: string, rows: Array, total: number, answered: number, remaining: ?number, revealed: boolean, note: string}}
 */
export function floatCard({ poll = null, results = null, answered = 0, remaining = null, revealed = null }) {
	if (!poll) {
		return { question: '', rows: [], total: 0, answered: 0, remaining: null, revealed: false, note: t('vibears', 'Waiting for the next question …') }
	}
	const isRevealed = revealed === null ? revealedOf(poll) : !!revealed
	const total = results && typeof results.total === 'number' ? results.total : 0
	let rows = []
	if (BAR_TYPES.includes(poll.type)) {
		const correct = new Set(correctIds(poll))
		// A quiz holds the tally back until the reveal (the projector shows
		// the intake counter then): without rows the options stand without
		// counts, as on the open stage, and the footer carries the answers.
		const tallied = results && Array.isArray(results.results) && results.results.length ? results.results : null
		const source = tallied || (poll.options || []).map((o) => ({ id: o.id, label: o.label, count: null }))
		rows = source.slice(0, MAX_ROWS).map((r, i) => {
			const pal = option(i)
			const count = tallied ? (r.count || 0) : null
			return {
				id: r.id,
				letter: pal.letter,
				key: pal.cls.slice(-1),
				label: plainText(r.label || ''),
				count,
				pct: count === null ? null : (total > 0 ? Math.round(count / total * 100) : 0),
				correct: isRevealed && correct.has(String(r.id)),
			}
		})
	}
	return {
		question: plainText(poll.question || ''),
		rows,
		total,
		answered: answered || 0,
		remaining: isRevealed ? null : remaining,
		revealed: isRevealed,
		note: rows.length ? '' : t('vibears', 'Shown in full on the projector'),
	}
}

/**
 * Greedy word wrap by a character budget per line; the last allowed line
 * ends in an ellipsis when text is left over. Pure, so the layout can be
 * tested without a canvas; the painter measures with the real font and
 * only ever shortens further.
 * @param {string} text
 * @param {number} perLine characters per line
 * @param {number} maxLines
 * @return {string[]}
 */
export function wrapText(text, perLine, maxLines) {
	const words = String(text || '').trim().split(/\s+/).filter(Boolean)
	const lines = []
	let line = ''
	for (const word of words) {
		const candidate = line ? line + ' ' + word : word
		if (candidate.length <= perLine || !line) {
			line = candidate
		} else {
			lines.push(line)
			line = word
		}
		if (lines.length === maxLines) break
	}
	if (lines.length < maxLines && line) lines.push(line)
	const used = lines.join(' ').length
	if (used < words.join(' ').length && lines.length) {
		const last = lines[lines.length - 1]
		lines[lines.length - 1] = (last.length > perLine - 1 ? last.slice(0, Math.max(1, perLine - 1)) : last) + '…'
	}
	// A single word longer than the line is cut to the budget, ellipsis included.
	return lines.map((l) => l.length > perLine + 1 ? l.slice(0, Math.max(1, perLine - 1)) + '…' : l)
}

/**
 * Geometry of the card at a size: every number scales with the height, so
 * the painted card looks the same at 480 and at 1920 pixels. The rows
 * share the space between question and footer; many rows become thinner.
 * @param {object} card from floatCard
 * @param {number} [w]
 * @param {number} [h]
 * @param {Function} [wrap] the question's wrap: (text, perLine, maxLines,
 *        maxWidthPx, fontPx) => lines. The default counts characters; the
 *        painter passes one that measures the real font.
 */
export function floatLayout(card, w = CARD_W, h = CARD_H, wrap = wrapText) {
	const u = h / CARD_H
	const pad = Math.round(32 * u)
	const qFs = Math.round(36 * u)
	const footFs = Math.round(24 * u)
	const qLines = wrap(card.question, Math.max(8, Math.floor((w - 2 * pad) / (qFs * 0.6))), 2, w - 2 * pad, qFs)
	const qH = qLines.length * Math.round(qFs * 1.25)
	const footH = Math.round(footFs * 1.5)
	const top = pad + qH + Math.round(14 * u)
	const bottom = h - pad - footH
	const count = card.rows.length
	const gap = Math.round(10 * u)
	const rowH = count ? Math.max(1, Math.min(Math.round(66 * u), Math.floor((bottom - top - gap * (count - 1)) / count))) : 0
	const rows = card.rows.map((r, i) => ({ ...r, x: pad, y: top + i * (rowH + gap), w: w - 2 * pad, h: rowH }))
	return {
		w, h, pad, qFs, footFs, qLines, rows,
		rowFs: Math.max(10, Math.min(Math.round(26 * u), Math.round(rowH * 0.42))),
		badge: Math.round(rowH * 0.66),
		noteY: Math.round(top + (bottom - top) / 2),
		footY: h - pad,
	}
}

/**
 * Read the theme's colours once, from the page's custom properties, so the
 * card matches the projector; fallbacks for a page without the tokens.
 * @param {Document} doc
 */
export function readTheme(doc) {
	const cs = doc.defaultView.getComputedStyle(doc.documentElement)
	const v = (name, fallback) => (cs.getPropertyValue(name) || '').trim() || fallback
	const opt = {}
	for (const key of ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']) {
		opt[key] = { fill: v('--opt-' + key, '#888'), ink: v('--opt-' + key + '-ink', '#fff') }
	}
	return {
		bg: v('--vibears-screen-bg', '#111'),
		text: v('--vibears-text', '#f4f4f4'),
		text2: v('--vibears-text-2', '#b8b8b8'),
		track: v('--vibears-border', 'rgba(128,128,128,.35)'),
		ok: v('--vibears-success', '#2e7d46'),
		opt,
	}
}

/**
 * Paint the card on a 2D context (CARD_W × CARD_H unless the canvas says
 * otherwise). Browser-only: fonts are measured here, so a label that the
 * character budget let through is still cut to the pixels it has.
 */
export function paintCard(ctx, card, theme) {
	const w = ctx.canvas.width
	const h = ctx.canvas.height
	const font = (px, weight = 400) => `${weight} ${px}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`
	const clip = (text, maxW) => {
		if (ctx.measureText(text).width <= maxW) return text
		let s = text
		while (s.length > 1 && ctx.measureText(s + '…').width > maxW) s = s.slice(0, -1)
		return s + '…'
	}
	// The question wrapped by the measured font, not by a character budget.
	const wrapMeasured = (text, perLine, maxLines, maxW, px) => {
		ctx.font = font(px, 700)
		const words = String(text || '').trim().split(/\s+/).filter(Boolean)
		const lines = []
		let line = ''
		let used = 0
		for (const word of words) {
			const candidate = line ? line + ' ' + word : word
			if (ctx.measureText(candidate).width <= maxW || !line) {
				line = candidate
			} else {
				lines.push(line)
				used += line.split(' ').length
				line = word
				if (lines.length === maxLines) break
			}
		}
		if (lines.length < maxLines && line) {
			lines.push(line)
			used += line.split(' ').length
		}
		if (used < words.length && lines.length) {
			lines[lines.length - 1] = clip(lines[lines.length - 1] + ' …', maxW)
		}
		return lines.map((l) => clip(l, maxW))
	}
	const L = floatLayout(card, w, h, wrapMeasured)
	ctx.fillStyle = theme.bg
	ctx.fillRect(0, 0, w, h)

	// Question
	ctx.fillStyle = theme.text
	ctx.font = font(L.qFs, 700)
	ctx.textBaseline = 'top'
	L.qLines.forEach((line, i) => ctx.fillText(line, L.pad, L.pad + i * Math.round(L.qFs * 1.25)))

	// Rows: badge · label · count, the bar under the label
	ctx.font = font(L.rowFs, 600)
	for (const r of L.rows) {
		const col = theme.opt[r.key] || theme.opt.a
		const b = L.badge
		const by = r.y + (r.h - b) / 2
		roundRect(ctx, r.x, by, b, b, b * 0.22)
		ctx.fillStyle = col.fill
		ctx.fill()
		ctx.fillStyle = col.ink
		ctx.font = font(Math.round(b * 0.55), 800)
		ctx.textBaseline = 'middle'
		ctx.textAlign = 'center'
		ctx.fillText(r.letter, r.x + b / 2, by + b / 2 + 1)
		ctx.textAlign = 'left'

		const left = r.x + b + Math.round(b * 0.3)
		const tallied = r.count !== null && r.count !== undefined
		const countText = tallied ? r.count + ' · ' + r.pct + '%' : ''
		ctx.font = font(L.rowFs, 700)
		const countW = countText ? ctx.measureText(countText).width + L.rowFs : 0
		const labelW = r.w - (left - r.x) - countW
		ctx.fillStyle = r.correct ? theme.ok : theme.text
		ctx.font = font(L.rowFs, 600)
		// Without a tally (a quiz before the reveal) the label sits on the row's
		// middle line, like the option list of the open stage.
		ctx.textBaseline = tallied ? 'top' : 'middle'
		const labelY = tallied ? r.y + Math.round(r.h * 0.08) : r.y + r.h / 2
		ctx.fillText((r.correct ? '✓ ' : '') + clip(r.label, labelW), left, labelY)
		if (!tallied) continue
		ctx.fillStyle = theme.text
		ctx.font = font(L.rowFs, 700)
		ctx.textAlign = 'right'
		ctx.fillText(countText, r.x + r.w, labelY)
		ctx.textAlign = 'left'

		const barY = r.y + Math.round(r.h * 0.62)
		const barH = Math.max(3, Math.round(r.h * 0.26))
		ctx.fillStyle = theme.track
		roundRect(ctx, left, barY, r.w - (left - r.x), barH, barH / 2)
		ctx.fill()
		if (r.pct > 0) {
			ctx.fillStyle = col.fill
			roundRect(ctx, left, barY, Math.max(barH, (r.w - (left - r.x)) * r.pct / 100), barH, barH / 2)
			ctx.fill()
		}
	}

	// Note instead of rows (other question types, lobby)
	if (card.note) {
		ctx.fillStyle = theme.text2
		ctx.font = font(L.rowFs, 500)
		ctx.textBaseline = 'middle'
		ctx.fillText(clip(card.note, w - 2 * L.pad), L.pad, L.noteY)
	}

	// Footer: answers left, countdown or "revealed" right
	ctx.font = font(L.footFs, 600)
	ctx.textBaseline = 'bottom'
	ctx.fillStyle = theme.text2
	if (card.question) {
		ctx.fillText(n('vibears', '%n answer', '%n answers', card.answered), L.pad, L.footY)
	}
	let right = ''
	if (card.revealed) right = t('vibears', 'Revealed')
	else if (card.remaining !== null && card.remaining !== undefined) right = (card.remaining > 0 ? card.remaining : 0) + ' s'
	if (right) {
		ctx.textAlign = 'right'
		ctx.fillStyle = card.remaining !== null && card.remaining !== undefined && card.remaining <= 10 && !card.revealed ? theme.opt.a.fill : theme.text2
		ctx.fillText(right, w - L.pad, L.footY)
		ctx.textAlign = 'left'
	}
}

function roundRect(ctx, x, y, w, h, r) {
	const rr = Math.min(r, w / 2, h / 2)
	ctx.beginPath()
	ctx.moveTo(x + rr, y)
	ctx.arcTo(x + w, y, x + w, y + h, rr)
	ctx.arcTo(x + w, y + h, x, y + h, rr)
	ctx.arcTo(x, y + h, x, y, rr)
	ctx.arcTo(x, y, x + w, y, rr)
	ctx.closePath()
}

/**
 * Chromium: a document picture-in-picture window with the projector page
 * inside, scaled to the window like ScreenPreview scales the canvas.
 * Resolves to {close, window}; rejects when the browser refuses (no user
 * gesture, a window already open).
 * @param {string} url the projector page (with ?float=1, see Screen.vue)
 * @param {object} [opts] {width, height, onClose}
 */
export async function openDocumentFloat(url, { width = 480, height = 270, onClose = null } = {}) {
	const pip = await window.documentPictureInPicture.requestWindow({ width, height })
	const doc = pip.document
	doc.documentElement.style.cssText = 'height:100%;background:#000'
	doc.body.style.cssText = 'margin:0;height:100%;overflow:hidden;background:#000;display:grid;place-items:center'
	const box = doc.createElement('div')
	box.style.cssText = 'position:relative;overflow:hidden'
	const frame = doc.createElement('iframe')
	frame.src = url
	frame.title = t('vibears', 'vibeARS live view')
	frame.style.cssText = `width:${KIOSK_W}px;height:${KIOSK_H}px;border:0;transform-origin:top left;display:block`
	box.appendChild(frame)
	doc.body.appendChild(box)
	const fit = () => {
		const k = fitScale(pip.innerWidth, pip.innerHeight)
		box.style.width = Math.round(KIOSK_W * k) + 'px'
		box.style.height = Math.round(KIOSK_H * k) + 'px'
		frame.style.transform = 'scale(' + k + ')'
	}
	fit()
	pip.addEventListener('resize', fit)
	pip.addEventListener('pagehide', () => { if (onClose) onClose() })
	return { window: pip, close: () => { try { pip.close() } catch (e) { /* already gone */ } } }
}
