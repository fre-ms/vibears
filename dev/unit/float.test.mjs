#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for the pure parts of src/util/float.js, the floating results
// window, without a browser:
//
//   node dev/unit/float.test.mjs      # exit 0 = all green
//
// - floatMode(): which way a browser can float, from the APIs it has.
// - fitScale(): the kiosk scaled into a box.
// - floatCard(): the card's content from the projector's state — rows with
//   the shared letters, percentages, the reveal, the note for other types.
// - wrapText() and floatLayout(): the text budget and the geometry at any size.

import assert from 'node:assert/strict'
import { CARD_H, CARD_W, fitScale, floatCard, floatLayout, floatMode, revealedOf, wrapText } from '../../src/util/float.js'

let passed = 0
const failures = []
function test(name, fn) {
	try {
		fn()
		passed++
	} catch (e) {
		failures.push(name)
		console.log('FAIL ' + name + '\n     ' + String(e.message).split('\n').join('\n     '))
	}
}

// ── floatMode ────────────────────────────────────────────────────────────

const win = ({ dpip = false, capture = true, pipApi = true, webkit = false, enabled = true } = {}) => ({
	document: { pictureInPictureEnabled: enabled },
	documentPictureInPicture: dpip ? { requestWindow() {} } : undefined,
	HTMLCanvasElement: { prototype: capture ? { captureStream() {} } : {} },
	HTMLVideoElement: { prototype: { ...(pipApi ? { requestPictureInPicture() {} } : {}), ...(webkit ? { webkitSetPresentationMode() {} } : {}) } },
})

test('floatMode: Chromium with document picture-in-picture', () => {
	assert.equal(floatMode(win({ dpip: true })), 'document')
})

test('floatMode: video picture-in-picture (Safari, older Chromium)', () => {
	assert.equal(floatMode(win()), 'video')
	assert.equal(floatMode(win({ pipApi: false, webkit: true })), 'video')
})

test('floatMode: Firefox keeps the video on the page', () => {
	assert.equal(floatMode(win({ pipApi: false, enabled: undefined })), 'inline')
})

test('floatMode: no canvas stream, no window', () => {
	assert.equal(floatMode(win({ capture: false })), 'none')
	assert.equal(floatMode(null), 'none')
	assert.equal(floatMode({ document: {} }), 'none')
})

// ── fitScale ─────────────────────────────────────────────────────────────

test('fitScale keeps the kiosk inside the box', () => {
	assert.equal(fitScale(1280, 720), 1)
	assert.equal(fitScale(640, 720), 0.5)
	assert.equal(fitScale(1280, 180), 0.25)
	assert.equal(fitScale(0, 100), 0)
	assert.equal(fitScale(NaN, 100), 0)
})

// ── floatCard ────────────────────────────────────────────────────────────

const poll = (type = 'choice', extra = {}) => ({
	id: 7,
	type,
	question: 'Which is **largest**: $x^2$ or $x$?',
	status: 'active',
	options: [{ id: 'A1', label: 'Jupiter' }, { id: 'B2', label: '*Saturn*' }, { id: 'C3', label: 'Earth' }],
	correctOption: 'A1',
	...extra,
})

test('floatCard: rows from the tally with the shared letters and percentages', () => {
	const card = floatCard({
		poll: poll(),
		results: { total: 4, results: [{ id: 'A1', label: 'Jupiter', count: 3 }, { id: 'B2', label: '*Saturn*', count: 1 }, { id: 'C3', label: 'Earth', count: 0 }] },
		answered: 4,
		remaining: 12,
	})
	assert.equal(card.question, 'Which is largest: x^2 or x?')
	assert.deepEqual(card.rows.map((r) => [r.letter, r.key, r.label, r.count, r.pct, r.correct]), [
		['A', 'a', 'Jupiter', 3, 75, false],
		['B', 'b', 'Saturn', 1, 25, false],
		['C', 'c', 'Earth', 0, 0, false],
	])
	assert.equal(card.answered, 4)
	assert.equal(card.remaining, 12)
	assert.equal(card.revealed, false)
	assert.equal(card.note, '')
})

test('floatCard: without a tally (a quiz before the reveal) the options stand without counts', () => {
	const card = floatCard({ poll: poll(), results: null, answered: 3 })
	assert.deepEqual(card.rows.map((r) => [r.label, r.count, r.pct]), [['Jupiter', null, null], ['Saturn', null, null], ['Earth', null, null]])
	assert.equal(card.answered, 3)
	// A tally with zero votes is a tally: zeros.
	const zero = floatCard({ poll: poll(), results: { total: 0, results: [{ id: 'A1', label: 'Jupiter', count: 0 }] } })
	assert.deepEqual(zero.rows.map((r) => [r.count, r.pct]), [[0, 0]])
})

test('floatCard: the reveal marks the key and ends the countdown', () => {
	const card = floatCard({ poll: poll('choice', { status: 'locked' }), results: { total: 2, results: [{ id: 'A1', label: 'Jupiter', count: 2 }, { id: 'B2', label: 'Saturn', count: 0 }] }, remaining: 5 })
	assert.equal(card.revealed, true)
	assert.equal(card.remaining, null)
	assert.deepEqual(card.rows.map((r) => r.correct), [true, false])
	// Multiple answers: the key is a list.
	const multi = floatCard({ poll: poll('multi', { correctOption: '', answerKey: { correct: ['B2', 'C3'] }, revealed: true }), results: null })
	assert.deepEqual(multi.rows.map((r) => r.correct), [false, true, true])
})

test('floatCard: at most eight rows', () => {
	const many = Array.from({ length: 12 }, (_, i) => ({ id: 'O' + i, label: 'Option ' + i }))
	const card = floatCard({ poll: poll('choice', { options: many }), results: null })
	assert.equal(card.rows.length, 8)
	assert.equal(card.rows[7].letter, 'H')
})

test('floatCard: other types and the lobby carry a note instead of rows', () => {
	const words = floatCard({ poll: poll('words'), results: { total: 9, results: [] }, answered: 9 })
	assert.deepEqual(words.rows, [])
	assert.equal(words.note, 'Shown in full on the projector')
	assert.equal(words.answered, 9)
	const lobby = floatCard({ poll: null })
	assert.equal(lobby.question, '')
	assert.equal(lobby.note, 'Waiting for the next question …')
})

test('revealedOf reads the flag first, then the status', () => {
	assert.equal(revealedOf({ revealed: false, status: 'locked' }), false)
	assert.equal(revealedOf({ status: 'ended' }), true)
	assert.equal(revealedOf({ status: 'active' }), false)
	assert.equal(revealedOf(null), false)
})

// ── wrapText / floatLayout ───────────────────────────────────────────────

test('wrapText wraps by words and ends a cut text in an ellipsis', () => {
	assert.deepEqual(wrapText('one two three four', 9, 2), ['one two', 'three…'])
	assert.deepEqual(wrapText('one two', 20, 2), ['one two'])
	assert.deepEqual(wrapText('', 20, 2), [])
	assert.deepEqual(wrapText('supercalifragilistic', 8, 1), ['superca…'])
	assert.deepEqual(wrapText('a b c d e f', 3, 2), ['a b', 'c …'])
})

test('floatLayout: rows sit between the question and the footer, at any size', () => {
	const card = floatCard({ poll: poll('choice', { options: Array.from({ length: 8 }, (_, i) => ({ id: 'O' + i, label: 'Option ' + i })) }), results: null })
	for (const [w, h] of [[CARD_W, CARD_H], [480, 270], [1920, 1080]]) {
		const L = floatLayout(card, w, h)
		assert.equal(L.rows.length, 8)
		const first = L.rows[0]
		const last = L.rows[7]
		assert.ok(first.y > L.pad + L.qFs, `${w}x${h}: rows start below the question`)
		assert.ok(last.y + last.h <= L.footY - L.footFs, `${w}x${h}: rows end above the footer`)
		assert.ok(first.h >= 1 && first.x === L.pad && first.w === w - 2 * L.pad)
		assert.ok(L.badge <= first.h && L.rowFs >= 10)
	}
})

test('floatLayout: a caller may wrap the question with its own measure', () => {
	const card = floatCard({ poll: poll(), results: null })
	const seen = []
	const L = floatLayout(card, CARD_W, CARD_H, (text, perLine, maxLines, maxW, px) => {
		seen.push([perLine, maxLines, maxW, px])
		return ['line one', 'line two']
	})
	assert.deepEqual(L.qLines, ['line one', 'line two'])
	assert.deepEqual(seen, [[Math.floor((CARD_W - 64) / (36 * 0.6)), 2, CARD_W - 64, 36]])
	// Two lines push the rows down by one line height.
	const one = floatLayout(card, CARD_W, CARD_H, () => ['one'])
	assert.equal(L.rows[0].y - one.rows[0].y, Math.round(36 * 1.25))
})

test('floatLayout: a single row does not grow without bound', () => {
	const L = floatLayout(floatCard({ poll: poll('truefalse', { options: [{ id: 'T', label: 'True' }] }), results: null }))
	assert.equal(L.rows.length, 1)
	assert.ok(L.rows[0].h <= 66)
})

console.log(`\n==== float.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)
