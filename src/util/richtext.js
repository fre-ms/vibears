/**
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Question texts and answer options carry a small inline markup:
 * **bold**, *italic*, `code`, and formulas between $…$ (inline) or $$…$$
 * (displayed on a line of its own). Everything else is text and is escaped,
 * so the result is safe for v-html: the only tags that come out are
 * <strong>, <em>, <code> and the placeholder <span class="rt-math"> that
 * RichText.vue hands to KaTeX once it is loaded. Until then (and if KaTeX
 * cannot be loaded) the placeholder shows the formula's source.
 *
 * The dollar rules follow Pandoc: an opening $ must be followed by a
 * non-space, a closing $ must be preceded by a non-space and must not be
 * followed by a digit — "$5 and $6" stays text. \$ is a literal dollar.
 * A marker without its partner is literal as well.
 *
 * Pure functions without the DOM, so dev/unit/richtext.test.mjs can run
 * them under plain Node.
 */

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }

export function escapeHtml(s) {
	return String(s ?? '').replace(/[&<>"']/g, (c) => ESC[c])
}

/**
 * Split a text into tokens: {kind: 'text'|'code'|'math', value, display}.
 * Math and code are found first, so that * inside a formula or a code span
 * is never read as emphasis.
 */
export function tokenize(text) {
	const s = String(text ?? '')
	const tokens = []
	let buf = ''
	const flush = () => {
		if (buf !== '') tokens.push({ kind: 'text', value: buf })
		buf = ''
	}
	let i = 0
	while (i < s.length) {
		const c = s[i]
		if (c === '\\' && s[i + 1] === '$') {
			buf += '$'
			i += 2
			continue
		}
		if (c === '`') {
			const j = s.indexOf('`', i + 1)
			if (j > i + 1) {
				flush()
				tokens.push({ kind: 'code', value: s.slice(i + 1, j) })
				i = j + 1
				continue
			}
			buf += c
			i++
			continue
		}
		if (c === '$') {
			if (s[i + 1] === '$') {
				const j = s.indexOf('$$', i + 2)
				if (j > i + 2) {
					flush()
					tokens.push({ kind: 'math', value: s.slice(i + 2, j), display: true })
					i = j + 2
					continue
				}
				buf += '$$'
				i += 2
				continue
			}
			const j = closingDollar(s, i)
			if (j !== -1) {
				flush()
				tokens.push({ kind: 'math', value: s.slice(i + 1, j), display: false })
				i = j + 1
				continue
			}
			buf += c
			i++
			continue
		}
		buf += c
		i++
	}
	flush()
	return tokens
}

/** Index of the closing $ of an inline formula opened at `open`, or -1. */
function closingDollar(s, open) {
	if (open + 1 >= s.length || /\s/.test(s[open + 1])) return -1
	for (let j = open + 1; j < s.length; j++) {
		if (s[j] !== '$' || s[j - 1] === '\\') continue
		if (/\s/.test(s[j - 1])) return -1
		if (j + 1 < s.length && /[0-9]/.test(s[j + 1])) return -1
		return j
	}
	return -1
}

// Emphasis on an already escaped text segment. No look-behind (older
// WebKit); the content must start and end with a non-space and must not
// contain the marker itself. Three stars first, or ***x*** would nest wrongly.
const BOTH = /\*\*\*(\S(?:[^*]*?\S)?)\*\*\*/g
const BOLD = /\*\*(\S(?:[^*]*?\S)?)\*\*/g
const ITALIC = /\*(\S(?:[^*]*?\S)?)\*/g

function emphasis(escaped) {
	return escaped
		.replace(BOTH, '<strong><em>$1</em></strong>')
		.replace(BOLD, '<strong>$1</strong>')
		.replace(ITALIC, '<em>$1</em>')
}

/** HTML for v-html: escaped text with the markup applied. */
export function renderRich(text) {
	let html = ''
	for (const tok of tokenize(text)) {
		if (tok.kind === 'code') {
			html += '<code>' + escapeHtml(tok.value) + '</code>'
		} else if (tok.kind === 'math') {
			const delim = tok.display ? '$$' : '$'
			html += '<span class="rt-math" data-tex="' + escapeHtml(tok.value) + '" data-display="' + (tok.display ? '1' : '0') + '">'
				+ escapeHtml(delim + tok.value + delim) + '</span>'
		} else {
			html += emphasis(escapeHtml(tok.value))
		}
	}
	return html
}

/**
 * The text without markers, for title attributes, aria labels and places
 * that cannot show HTML. A formula keeps its source without the dollars.
 */
export function plainText(text) {
	let out = ''
	for (const tok of tokenize(text)) {
		if (tok.kind === 'text') {
			out += tok.value.replace(BOTH, '$1').replace(BOLD, '$1').replace(ITALIC, '$1')
		} else {
			out += tok.value
		}
	}
	return out
}

/** True if the text carries any markup or formula (otherwise plain {{ }} would do). */
export function hasRich(text) {
	return renderRich(text) !== escapeHtml(text)
}
