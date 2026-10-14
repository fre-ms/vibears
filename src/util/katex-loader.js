/**
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * KaTeX as a chunk of its own (js/vibears-katex.js), fetched the first time
 * a page meets a formula: most decks have none, and the participant bundle
 * is loaded by every phone in the hall. The chunk is found through the
 * public path set in util/webpack-env.js. Its stylesheet and fonts are
 * static files under css/katex/ (build/vendor-katex.js), loaded by the
 * page templates.
 */

let pending = null

/** Resolves to the katex module, or to null if the chunk cannot be loaded. */
export function loadKatex() {
	if (!pending) {
		pending = import(/* webpackChunkName: "katex" */ 'katex')
			.then((m) => m.default || m)
			.catch((e) => {
				console.warn('vibeARS: KaTeX could not be loaded, formulas stay as source text', e)
				return null
			})
	}
	return pending
}

/** Options for katex.render: never throw, no console noise, bounded size and expansion. */
export const KATEX_OPTIONS = {
	throwOnError: false,
	strict: 'ignore',
	// A hostile or careless macro must not freeze a phone.
	maxSize: 50,
	maxExpand: 1000,
}
