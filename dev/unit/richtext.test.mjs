#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/richtext.js, the inline markup of question texts
// and answer options, without a browser and without KaTeX:
//
//   node dev/unit/richtext.test.mjs      # exit 0 = all green
//
// - renderRich(): the only tags that come out are strong, em, code and the
//   formula placeholder; everything the author typed is escaped.
// - the dollar rules: amounts stay text, \$ is literal, a lone $ is literal.
// - plainText(): markers gone, formula source kept, for titles and aria.
// - hasRich(): true exactly when the text carries markup or a formula.

import assert from 'node:assert/strict'
import { escapeHtml, hasRich, plainText, renderRich, tokenize } from '../../src/util/richtext.js'

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

const math = (tex, display = false) =>
	'<span class="rt-math" data-tex="' + escapeHtml(tex) + '" data-display="' + (display ? '1' : '0') + '">'
	+ escapeHtml((display ? '$$' : '$') + tex + (display ? '$$' : '$')) + '</span>'

// ── Escaping: nothing the author typed reaches the DOM as HTML ───────────

test('plain text is escaped and otherwise untouched', () => {
	assert.equal(renderRich('a < b & c > "d"'), 'a &lt; b &amp; c &gt; &quot;d&quot;')
	assert.equal(renderRich(''), '')
	assert.equal(renderRich(null), '')
})

test('a script tag never survives, not even inside markup or a formula', () => {
	assert.equal(renderRich('**<script>x</script>**'), '<strong>&lt;script&gt;x&lt;/script&gt;</strong>')
	assert.equal(renderRich('`<img src=x onerror=alert(1)>`'), '<code>&lt;img src=x onerror=alert(1)&gt;</code>')
	const html = renderRich('$<b>$')
	assert.equal(html, math('<b>'))
	assert.ok(!html.includes('<b>'))
})

test('quotes inside a formula are escaped in the data attribute', () => {
	assert.equal(renderRich('$a"b$'), '<span class="rt-math" data-tex="a&quot;b" data-display="0">$a&quot;b$</span>')
})

// ── Emphasis and code ────────────────────────────────────────────────────

test('bold, italic and code', () => {
	assert.equal(renderRich('**bold** and *italic* and `code`'), '<strong>bold</strong> and <em>italic</em> and <code>code</code>')
	assert.equal(renderRich('***both***'), '<strong><em>both</em></strong>')
	assert.equal(plainText('***both***'), 'both')
})

test('a marker without its partner stays literal', () => {
	assert.equal(renderRich('2 * 3 * 4'), '2 * 3 * 4')
	assert.equal(renderRich('a ** b'), 'a ** b')
	assert.equal(renderRich('*open'), '*open')
	assert.equal(renderRich('`open'), '`open')
	assert.equal(renderRich('``'), '``')
})

test('emphasis is not read inside code or a formula', () => {
	assert.equal(renderRich('`a*b*c`'), '<code>a*b*c</code>')
	assert.equal(renderRich('$a*b*c$'), math('a*b*c'))
})

// ── Formulas ─────────────────────────────────────────────────────────────

test('inline and display formulas become placeholders with their source', () => {
	assert.equal(renderRich('Solve $x^2 = 4$.'), 'Solve ' + math('x^2 = 4') + '.')
	assert.equal(renderRich('$$\\int_0^1 x\\,dx$$'), math('\\int_0^1 x\\,dx', true))
	assert.equal(renderRich('a $x$ b $y$ c'), 'a ' + math('x') + ' b ' + math('y') + ' c')
})

test('amounts of money stay text', () => {
	assert.equal(renderRich('$5 and $6'), '$5 and $6')
	assert.equal(renderRich('costs $ 5'), 'costs $ 5')
	assert.equal(renderRich('the $ sign'), 'the $ sign')
	assert.equal(renderRich('$x $'), '$x $')
})

test('an escaped dollar is literal, inside and outside a formula', () => {
	assert.equal(renderRich('\\$5'), '$5')
	assert.equal(renderRich('$\\$x$'), math('\\$x'))
})

test('an unclosed formula stays text', () => {
	assert.equal(renderRich('$x^2'), '$x^2')
	assert.equal(renderRich('$$x'), '$$x')
	assert.equal(renderRich('$$$$'), '$$$$')
})

test('tokenize keeps the order and the kinds', () => {
	assert.deepEqual(tokenize('a `b` $c$ $$d$$ e'), [
		{ kind: 'text', value: 'a ' },
		{ kind: 'code', value: 'b' },
		{ kind: 'text', value: ' ' },
		{ kind: 'math', value: 'c', display: false },
		{ kind: 'text', value: ' ' },
		{ kind: 'math', value: 'd', display: true },
		{ kind: 'text', value: ' e' },
	])
})

// ── plainText / hasRich ──────────────────────────────────────────────────

test('plainText drops the markers and keeps the formula source', () => {
	assert.equal(plainText('**Solve** $x^2 = 4$ in `R`'), 'Solve x^2 = 4 in R')
	assert.equal(plainText('$5 and *it*'), '$5 and it')
	assert.equal(plainText('<b>'), '<b>')
})

test('hasRich tells markup from plain text', () => {
	assert.equal(hasRich('Which planet is the largest?'), false)
	assert.equal(hasRich('$5 and $6'), false)
	assert.equal(hasRich('a < b'), false)
	assert.equal(hasRich('**bold**'), true)
	assert.equal(hasRich('$x$'), true)
	assert.equal(hasRich('`x`'), true)
})

console.log(`${passed} ok / ${failures.length} failed`)
process.exit(failures.length ? 1 : 0)
