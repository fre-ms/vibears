<!--
  - SPDX-FileCopyrightText: 2026 fre.ms
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<component :is="tag" class="rt" v-html="html" />
</template>

<script>
/*
 * A question text or answer option with the inline markup of
 * util/richtext.js: bold, italic, code and KaTeX formulas. The tag is the
 * element the plain text used to live in (h1, span, p), so the surrounding
 * styles keep working; class and other attributes fall through to it.
 *
 * The HTML comes from renderRich, which escapes everything it did not
 * generate itself — that is what makes v-html acceptable here. Formulas
 * arrive as placeholders with their source and are typeset in place once
 * the KaTeX chunk is there; a page without formulas never fetches it.
 */
import { renderRich } from '../util/richtext.js'
import { KATEX_OPTIONS, loadKatex } from '../util/katex-loader.js'

export default {
	name: 'RichText',
	props: {
		text: { type: String, default: '' },
		tag: { type: String, default: 'span' },
	},
	computed: {
		html() {
			return renderRich(this.text)
		},
	},
	watch: {
		html() {
			// v-html has replaced the content: the placeholders are back.
			this.$nextTick(this.typeset)
		},
	},
	mounted() {
		this.typeset()
	},
	methods: {
		typeset() {
			const root = this.$el
			if (!root || typeof root.querySelectorAll !== 'function') return
			const nodes = root.querySelectorAll('.rt-math:not(.is-typeset)')
			if (!nodes.length) return
			loadKatex().then((katex) => {
				if (!katex) return
				for (const el of nodes) {
					if (!el.isConnected) continue
					katex.render(el.dataset.tex || '', el, { ...KATEX_OPTIONS, displayMode: el.dataset.display === '1' })
					el.classList.add('is-typeset')
				}
			})
		},
	},
}
</script>
