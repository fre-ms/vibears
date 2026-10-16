<!--
  - SPDX-FileCopyrightText: 2026 fre.ms
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- Firefox: no picture-in-picture API, only the browser's own button on
	     a video — so the video stays on the page with a hint. The other two
	     ways need no visible element: a 1 px video (a hidden one cannot be
	     floated) and a canvas that never joins the document. -->
	<div v-if="mode === 'inline' && shown" class="flt" role="region" :aria-label="t('vibears', 'Float results')">
		<video ref="video" class="flt-video" muted playsinline autoplay />
		<p class="flt-hint">{{ t('vibears', 'Hover the video and use the browser’s own picture-in-picture button.') }}</p>
		<button type="button" class="vibears-btn is-secondary is-sm" @click="close">{{ t('vibears', 'Close') }}</button>
	</div>
	<video v-else-if="mode === 'video'" ref="video" class="flt-offscreen" muted playsinline aria-hidden="true" />
</template>

<script>
/*
 * The floating results window (util/float.js): a parent puts this in its
 * template, hands it the projector page's address and the card's state,
 * and calls open() from a click — the browsers only float on a user
 * gesture. 'change' reports whether a window is floating.
 */
import { showError, showSuccess } from '../toast.js'
import { CARD_H, CARD_W, floatMode, openDocumentFloat, paintCard, readTheme } from '../util/float.js'

export default {
	name: 'FloatResults',
	props: {
		// The projector page to show in a document picture-in-picture window.
		screenUrl: { type: String, default: '' },
		// floatCard(): what the painted card shows.
		card: { type: Object, default: null },
	},
	data() {
		return {
			mode: floatMode(),
			shown: false,
			floating: false,
		}
	},
	computed: {
		available() {
			return this.mode !== 'none'
		},
	},
	watch: {
		card() {
			if (this.floating && this.mode !== 'document') this.paint()
		},
	},
	created() {
		// Outside data(): nothing here is for the template.
		this.handle = null
		this.canvas = null
		this.ctx = null
		this.theme = null
		this.timer = null
	},
	beforeDestroy() {
		this.close()
	},
	methods: {
		/** From a click. Opens the window, or tells why it could not. */
		async open() {
			if (this.floating) return
			try {
				if (this.mode === 'document') {
					this.handle = await openDocumentFloat(this.screenUrl, { onClose: () => this.ended() })
				} else if (this.mode === 'video') {
					this.startPainting()
					const video = this.$refs.video
					video.srcObject = this.canvas.captureStream(4)
					await video.play()
					if (typeof video.requestPictureInPicture === 'function') {
						await video.requestPictureInPicture()
					} else {
						video.webkitSetPresentationMode('picture-in-picture')
					}
					video.addEventListener('leavepictureinpicture', () => this.ended(), { once: true })
					video.addEventListener('webkitpresentationmodechanged', () => {
						if (video.webkitPresentationMode !== 'picture-in-picture') this.ended()
					})
				} else if (this.mode === 'inline') {
					this.shown = true
					await this.$nextTick()
					this.startPainting()
					const video = this.$refs.video
					video.srcObject = this.canvas.captureStream(4)
					await video.play()
				} else {
					return
				}
				this.floating = true
				this.$emit('change', true)
				if (this.mode !== 'inline') {
					showSuccess(this.t('vibears', 'The results now float above other windows. Drag the window onto the projector.'))
				}
			} catch (e) {
				this.stopPainting()
				this.shown = false
				showError(this.t('vibears', 'The floating window could not be opened.'))
			}
		},
		close() {
			if (this.handle) {
				this.handle.close()
				this.handle = null
			}
			const video = this.$refs.video
			if (video && document.pictureInPictureElement === video && document.exitPictureInPicture) {
				document.exitPictureInPicture().catch(() => {})
			}
			this.ended()
		},
		ended() {
			this.stopPainting()
			this.handle = null
			this.shown = false
			if (this.floating) {
				this.floating = false
				this.$emit('change', false)
			}
		},
		startPainting() {
			if (!this.canvas) {
				this.canvas = document.createElement('canvas')
				this.canvas.width = CARD_W
				this.canvas.height = CARD_H
				this.ctx = this.canvas.getContext('2d')
			}
			this.theme = readTheme(document)
			this.paint()
			// The countdown changes every second; the state arrives by polling.
			this.timer = window.setInterval(() => this.paint(), 1000)
		},
		stopPainting() {
			if (this.timer) {
				window.clearInterval(this.timer)
				this.timer = null
			}
			const video = this.$refs.video
			if (video && video.srcObject) {
				video.srcObject.getTracks().forEach((track) => track.stop())
				video.srcObject = null
			}
		},
		paint() {
			if (this.ctx && this.card) paintCard(this.ctx, this.card, this.theme || readTheme(document))
		},
	},
}
</script>

<style scoped>
.flt { position: fixed; right: 16px; bottom: 16px; z-index: 50; width: min(480px, 60vw); padding: 10px; border-radius: var(--vibears-r-card, 12px); background: var(--vibears-surface, #fff); color: var(--vibears-text); box-shadow: 0 8px 32px rgba(0, 0, 0, .35); display: grid; gap: 8px; justify-items: start; }
.flt-video { width: 100%; aspect-ratio: 16 / 9; border-radius: 8px; background: #000; display: block; }
.flt-hint { margin: 0; font-size: 13px; color: var(--vibears-text-2); }
.flt-offscreen { position: fixed; left: 0; top: 0; width: 2px; height: 2px; opacity: 0.01; pointer-events: none; }
</style>
