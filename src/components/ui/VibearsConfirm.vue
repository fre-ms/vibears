<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pconfirm-overlay" @click.self="onCancel">
		<div ref="dialog"
			class="pconfirm"
			:class="{ 'has-alt': !!altLabel }"
			role="dialog"
			aria-modal="true"
			:aria-label="title"
			tabindex="-1">
			<h5 class="pconfirm-title">
				<VibearsIcon v-if="danger" name="warning" class="pconfirm-warn" size="1.2em" />
				{{ title }}
			</h5>
			<p v-if="text" class="pconfirm-text">{{ text }}</p>
			<div class="pconfirm-row">
				<button ref="cancel" type="button" class="vibears-btn is-secondary" @click="onCancel">
					{{ cancelLabel }}
				</button>
				<button v-if="altLabel" type="button" class="vibears-btn is-secondary pconfirm-alt" @click="onAlt">
					{{ altLabel }}
				</button>
				<button type="button"
					:class="['vibears-btn', danger ? 'is-danger is-solid' : 'is-primary']"
					@click="onConfirm">
					{{ confirmLabel }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
/*
 * VibearsConfirm — themeable confirmation dialog, replaces window.confirm().
 * Usually not used directly in a template but imperatively via
 * util/confirm.js -> vibearsConfirm({...}): Promise<boolean>.
 * Destructive variant (danger): warning icon, "Cancel" on the left, red action on the right.
 * altLabel: a third, understated button between Cancel and Confirm
 * (e.g. "Stop without releasing" next to "Close and release") — vibearsConfirm
 * then resolves with 'alt'.
 * Own fixed overlay + focus trap (no NcModal, full control over the look).
 */
import { t } from '../../util/l10n.js'
import VibearsIcon from './VibearsIcon.vue'

export default {
	name: 'VibearsConfirm',
	components: { VibearsIcon },
	props: {
		title: { type: String, default: t('vibears', 'Are you sure?') },
		text: { type: String, default: '' },
		confirmLabel: { type: String, default: 'OK' },
		cancelLabel: { type: String, default: t('vibears', 'Cancel') },
		danger: { type: Boolean, default: false },
		altLabel: { type: String, default: '' },
	},
	mounted() {
		this._onKey = (e) => {
			if (e.key === 'Escape') { e.preventDefault(); this.onCancel() }
		}
		document.addEventListener('keydown', this._onKey)
		// Safe default for destructive actions: focus on "Cancel".
		this.$nextTick(() => { (this.$refs.cancel || this.$refs.dialog).focus() })
	},
	beforeDestroy() {
		document.removeEventListener('keydown', this._onKey)
	},
	methods: {
		onConfirm() { this.$emit('confirm') },
		onCancel() { this.$emit('cancel') },
		onAlt() { this.$emit('alt') },
	},
}
</script>

<style scoped>
.pconfirm-overlay {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: grid;
	place-items: center;
	padding: 16px;
	background: rgba(0, 0, 0, .5);
}
.pconfirm {
	width: 100%;
	max-width: 360px;
	background: var(--vibears-bg);
	border: 1px solid var(--vibears-border);
	border-radius: var(--vibears-r-card);
	box-shadow: var(--vibears-shadow-pop);
	padding: 22px;
}
.pconfirm-title {
	margin: 0 0 6px;
	font-size: var(--t-lead);
	color: var(--vibears-text);
	display: flex;
	align-items: center;
	gap: 10px;
}
.pconfirm-warn { color: var(--vibears-error); }
.pconfirm-text {
	margin: 0 0 18px;
	font-size: var(--t-sm);
	color: var(--vibears-text-2);
	line-height: 1.5;
}
.pconfirm-row {
	display: flex;
	gap: 10px;
	justify-content: flex-end;
}
/* Three buttons do not fit into 360 px: make it wider and wrap if needed. */
.pconfirm.has-alt { max-width: 480px; }
.pconfirm.has-alt .pconfirm-row { flex-wrap: wrap; }
</style>
