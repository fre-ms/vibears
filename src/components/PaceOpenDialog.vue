<!--
  - SPDX-FileCopyrightText: 2026 hotochan123
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="pod-overlay" @click.self="cancel">
		<div ref="dialog"
			class="pod"
			:class="{ 'is-extend': extend }"
			role="dialog"
			aria-modal="true"
			aria-labelledby="pace-open-title"
			tabindex="-1"
			@keydown="onKey">
			<h2 id="pace-open-title" class="pod-title">{{ title }}</h2>

			<div class="pod-body">
				<!-- Practice run: reset and "Reset quiz" keep it (server) — anyone who
				     wants to open "for real" now has to see it here, not only at the
				     final standings without a leaderboard. -->
				<div v-if="practice && !extend" class="vibears-notice is-warn pod-practice">
					<VibearsIcon name="warning" size="1.1em" class="vibears-notice-ico" />
					<span class="pod-practice-txt">{{ t('vibears', 'This is a practice run — nothing counts.') }}</span>
					<button type="button" class="vibears-btn is-secondary is-sm pod-practice-end" :disabled="busy" @click="$emit('end-practice')">{{ t('vibears', 'End practice run') }}</button>
				</div>

				<section class="pod-group">
					<!-- When extending, the title already asks this question. -->
					<h3 v-if="!extend" class="pod-label">{{ t('vibears', 'When does the quiz end?') }}</h3>
					<VibearsSegmented :value="end" :options="endOptions" :label="t('vibears', 'When does the quiz end?')" @input="setEnd" />
					<div v-if="end === 'deadline'" class="pod-deadline">
						<label class="pod-sublabel" for="pace-open-deadline">{{ t('vibears', 'Deadline') }}</label>
						<!-- A preset only fills the field — the field stays the source of truth. -->
						<div class="pod-presets">
							<button v-for="p in presets"
								:key="p.key"
								type="button"
								class="vibears-btn is-secondary is-sm"
								:data-preset="p.key"
								@click="usePreset(p)">
								{{ p.label }}
							</button>
						</div>
						<input id="pace-open-deadline"
							ref="deadline"
							v-model="deadline"
							class="pace-input"
							type="datetime-local"
							:min="minInput"
							:max="maxInput"
							:aria-invalid="deadlineError ? 'true' : 'false'"
							aria-describedby="pace-open-rel">
						<!-- Relative + absolute: an AM/PM or date mix-up shows before
						     submitting, not only on the phone. -->
						<p id="pace-open-rel" class="pod-rel" :class="{ 'is-error': !!deadlineError }" aria-live="polite">
							{{ deadlineError || relLine }}
						</p>
						<!-- The old deadline had passed or was too close: tomorrow is prefilled,
						     with the old one below for comparison. -->
						<p v-if="wasAt" class="pod-help pod-was">{{ t('vibears', 'Was: {time}', { time: wasText }) }}</p>
						<p v-if="!extend" class="pod-help">{{ t('vibears', 'Participants must finish on the same device.') }}</p>
					</div>
					<template v-if="extend">
						<p v-if="end === 'manual'" class="pod-help">{{ t('vibears', 'Closing will then also release the results.') }}</p>
						<p v-if="reopen" class="pod-help">{{ t('vibears', 'People continue where they stopped; on timed questions their time may already be up.') }}</p>
					</template>
				</section>

				<!-- Timer and feedback are fixed since opening (server) —
				     when extending, only the end is in question. -->
				<template v-if="!extend">
					<section class="pod-group">
						<h3 class="pod-label">{{ t('vibears', 'Timer and speed points') }}</h3>
						<VibearsSegmented v-model="timed" :options="timerOptions" :label="t('vibears', 'Timer and speed points')" />
						<p class="pod-help">{{ timed ? t('vibears', 'Each question has its time limit; faster correct answers earn more points.') : t('vibears', 'No time pressure — every correct answer earns 1000 points.') }}</p>
					</section>

					<section class="pod-group">
						<h3 class="pod-label">{{ t('vibears', 'When do people see if they’re right?') }}</h3>
						<VibearsSegmented :value="practice ? 'each' : feedback" :options="feedbackOptions" :disabled="practice" :label="t('vibears', 'When do people see if they’re right?')" @input="feedback = $event" />
						<p v-if="practice" class="pod-help">{{ t('vibears', 'Practice run: always after each question.') }}</p>
						<p v-else class="pod-help">{{ feedback === 'each' ? t('vibears', 'Participants see right or wrong after every answer.') : t('vibears', 'Participants only see that their answer was saved. Results come when you release them.') }}</p>
						<p v-if="end === 'deadline' && feedback === 'each' && !practice" class="vibears-notice is-warn pod-warn">
							<VibearsIcon name="warning" size="1.1em" class="vibears-notice-ico" />
							<span>{{ t('vibears', 'Feedback after each question lets people pass on the right answers — for grades, choose “At the end”.') }}</span>
						</p>
					</section>
				</template>
			</div>

			<div class="pod-foot">
				<p v-if="!extend" class="vibears-notice is-info pod-lock">
					<VibearsIcon name="lock" size="1.1em" class="vibears-notice-ico" />
					<span>{{ t('vibears', 'Everyone starts on their own phone, with their own clock. From now on the questions are locked until you reset the room.') }}</span>
				</p>
				<p v-if="serverError" class="vibears-notice is-warn pod-error" role="alert">
					<VibearsIcon name="warning" size="1.1em" class="vibears-notice-ico" />
					<span>{{ serverError }}</span>
				</p>
				<div class="pod-actions">
					<span v-if="!extend" class="pod-sum">{{ n('vibears', '%n question', '%n questions', total) }}</span>
					<button ref="cancel" type="button" class="vibears-btn is-secondary" :disabled="busy" @click="cancel">{{ t('vibears', 'Cancel') }}</button>
					<button type="button" class="vibears-btn is-primary pod-submit" :disabled="busy" @click="submit">{{ busy ? '…' : submitLabel }}</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
/*
 * Open a self-paced quiz (spec §1.5, not in the public repository; mode `open`) and change
 * its end or reopen it (mode `extend`, from the run view).
 *
 * A form dialog in the look of VibearsConfirm — vibearsConfirm itself has
 * no fields. On opening there are three questions, each one a choice: When does it end?
 * With a timer? When does everyone see if they are right? The end sets the defaults of the
 * other two (no deadline means a race: timer on, feedback after each question; with a
 * deadline it is homework: no timer, reveal at the end). The dialog deliberately does not
 * take over the timed/feedback values stored in the room — it
 * sends both values explicitly, so the form shows exactly
 * what applies.
 *
 * When extending, only the first question remains: timer and feedback are
 * fixed since opening. The current end is prefilled; a deadline that has already
 * passed (or is too close) is replaced by "tomorrow", with the old one shown as
 * "Was: …" below. From a closed window this means reopening.
 *
 * The dialog sends the request itself; errors stay in the dialog (400
 * inline), only 409 goes up (`conflict`): then the room here is out of date,
 * and the moderator reloads it.
 *
 * "Now" is server time (laptop time + skew), ticking every second — the
 * server checks the deadline limits against its own clock.
 *
 * Emits: done(roomJson) · conflict(message) · end-practice · cancel
 */
import axios from '@nextcloud/axios'
import { MOD_TIMEOUT, roomApi } from '../util/routes.js'
import { t, n } from '../util/l10n.js'
import { serverMessage } from '../toast.js'
import { fmtDeadline, fmtDuration } from '../util/format.js'
import { DEADLINE_MIN, deadlineInRange, deadlineInputRange, toLocalInput, fromLocalInput, defaultDeadline, deadlinePresets, stopDeadline, windowState } from '../util/pace.js'
import VibearsIcon from './ui/VibearsIcon.vue'
import VibearsSegmented from './ui/VibearsSegmented.vue'

const serverNow = (skew) => Math.floor(Date.now() / 1000) + (Number(skew) || 0)

/**
 * Prefill when extending: without a deadline "When I close it"; with a deadline
 * that one — unless it has passed or is due within two minutes (the server's minute
 * plus one for reading the dialog before it runs out), then tomorrow (on the full
 * hour) and the old one for comparison. Legacy: a deadline set by the former
 * "Stop without releasing" (deadline in two minutes, then close —
 * two calls) counts as none (stopDeadline): nobody chose it. Since
 * close {release: false} the deadline stays 0 when stopping, and the first line
 * takes effect by itself.
 * @param {object|null} win `room.window`
 * @param {number} now now in server time
 * @return {{end: string, deadline: string, wasAt: number}}
 */
function extendStart(win, now) {
	const at = (win && Number(win.closesAt)) || 0
	if (at <= 0 || stopDeadline(win)) return { end: 'manual', deadline: '', wasAt: 0 }
	if (at < now + DEADLINE_MIN + 60) return { end: 'deadline', deadline: toLocalInput(defaultDeadline(now, 1)), wasAt: at }
	return { end: 'deadline', deadline: toLocalInput(at), wasAt: 0 }
}

export default {
	name: 'PaceOpenDialog',
	components: { VibearsIcon, VibearsSegmented },
	props: {
		room: { type: Object, required: true },
		// 'open' (deck, draft) | 'extend' (run view: change the end / reopen)
		mode: { type: String, default: 'open', validator: (v) => v === 'open' || v === 'extend' },
		skew: { type: Number, default: 0 },
	},
	data() {
		const now = serverNow(this.skew)
		const start = this.mode === 'extend' ? extendStart(this.room.window, now) : { end: 'manual', deadline: '', wasAt: 0 }
		return {
			now,
			end: start.end,     // 'manual' (When I close it) | 'deadline'
			deadline: start.deadline, // value of the datetime-local field (local time)
			wasAt: start.wasAt, // old, expired deadline (extending only)
			timed: true,
			feedback: 'each',
			busy: false,
			serverError: '',
		}
	},
	computed: {
		extend() {
			return this.mode === 'extend'
		},
		// Extending a closed window = reopening.
		reopen() {
			return this.extend && windowState(this.room.window, this.now) === 'closed'
		},
		practice() {
			return !!this.room.practice
		},
		title() {
			if (this.extend) return t('vibears', 'When does the quiz end?')
			return this.practice ? t('vibears', 'Open practice run') : t('vibears', 'Open quiz')
		},
		submitLabel() {
			if (this.extend) return this.reopen ? t('vibears', 'Reopen') : t('vibears', 'Save')
			return this.practice ? t('vibears', 'Open practice run') : t('vibears', 'Open quiz')
		},
		total() {
			const w = this.room.window
			return (w && w.total) || (this.room.polls || []).length
		},
		endOptions() {
			return [
				{ value: 'manual', label: t('vibears', 'When I close it') },
				{ value: 'deadline', label: t('vibears', 'At a set time') },
			]
		},
		timerOptions() {
			return [
				{ value: true, label: t('vibears', 'Timer on') },
				{ value: false, label: t('vibears', 'Timer off') },
			]
		},
		feedbackOptions() {
			return [
				{ value: 'each', label: t('vibears', 'After each question') },
				{ value: 'end', label: t('vibears', 'At the end') },
			]
		},
		presets() {
			const label = {
				'15m': t('vibears', 'In 15 min'),
				'1h': t('vibears', 'In 1 hour'),
				tomorrow8: t('vibears', 'Tomorrow 08:00'),
				'1w': t('vibears', 'In 1 week'),
			}
			return deadlinePresets(this.now).map((p) => ({ ...p, label: label[p.key] }))
		},
		// Browser limits of the field; the actual validation is deadlineError.
		minInput() {
			return toLocalInput(deadlineInputRange(this.now).min)
		},
		maxInput() {
			return toLocalInput(deadlineInputRange(this.now).max)
		},
		closesAt() {
			return this.end === 'manual' ? 0 : fromLocalInput(this.deadline)
		},
		// The server's bounds with a few seconds to spare (deadlineInRange) — and
		// the server's sentence, so a 400 from a slow request reads the same.
		deadlineError() {
			if (this.end !== 'deadline' || deadlineInRange(this.closesAt, this.now)) return ''
			return t('vibears', 'The deadline must be between one minute and 30 days from now.')
		},
		relLine() {
			if (this.end !== 'deadline' || this.deadlineError) return ''
			return t('vibears', 'Closes in {duration} — {time}', { duration: fmtDuration(this.closesAt - this.now), time: fmtDeadline(this.closesAt) })
		},
		wasText() {
			return fmtDeadline(this.wasAt)
		},
	},
	mounted() {
		// After closing, back to the trigger (in the deck: "Open quiz …";
		// in the run view PaceRun sets the focus on its menu itself).
		this.returnFocus = document.activeElement
		this.tick = setInterval(() => { this.now = serverNow(this.skew) }, 1000)
		document.addEventListener('keydown', this.onDocKey)
		this.$nextTick(() => {
			const first = this.$el.querySelector('.pseg-item[aria-checked="true"]')
			;(first || this.$refs.dialog).focus()
		})
	},
	beforeDestroy() {
		clearInterval(this.tick)
		document.removeEventListener('keydown', this.onDocKey)
		const el = this.returnFocus
		if (el && el.isConnected && typeof el.focus === 'function') el.focus()
	},
	methods: {
		t,
		n,
		// The end determines the defaults of the other two questions.
		setEnd(v) {
			this.end = v
			this.timed = v === 'manual'
			this.feedback = v === 'manual' ? 'each' : 'end'
			if (v === 'deadline' && !this.deadline) this.deadline = toLocalInput(defaultDeadline(this.now))
		},
		usePreset(p) {
			this.deadline = toLocalInput(p.ts)
		},
		cancel() {
			if (this.busy) return
			this.$emit('cancel')
		},
		async submit() {
			if (this.busy) return
			this.serverError = ''
			if (this.deadlineError) {
				if (this.$refs.deadline) this.$refs.deadline.focus()
				return
			}
			this.busy = true
			const fallback = this.extend ? t('vibears', 'Could not change the end.') : t('vibears', 'Could not open the quiz.')
			try {
				// Extending sends only the end — timer and feedback stay (server).
				const body = this.extend
					? { action: 'extend', closesAt: this.closesAt }
					: { action: 'open', closesAt: this.closesAt, timed: this.timed, feedback: this.practice ? 'each' : this.feedback }
				const { data } = await axios.post(roomApi(this.room.code, '/pace'), body, { timeout: MOD_TIMEOUT })
				this.$emit('done', data)
			} catch (e) {
				const msg = serverMessage(e, fallback)
				if (e?.response?.status === 409) {
					this.$emit('conflict', msg)
					return
				}
				this.serverError = msg
			} finally {
				this.busy = false
			}
		},
		onDocKey(e) {
			if (e.key === 'Escape') {
				e.preventDefault()
				this.cancel()
			}
		},
		// Focus trap: Tab stays inside the dialog.
		onKey(e) {
			if (e.key !== 'Tab') return
			const els = Array.from(this.$refs.dialog.querySelectorAll('button, input, [tabindex="0"]'))
				.filter((el) => !el.disabled && el.getAttribute('tabindex') !== '-1' && el.offsetParent !== null)
			if (!els.length) return
			const first = els[0]
			const last = els[els.length - 1]
			if (e.shiftKey && (document.activeElement === first || document.activeElement === this.$refs.dialog)) {
				e.preventDefault()
				last.focus()
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault()
				first.focus()
			}
		},
	},
}
</script>

<style scoped>
.pod-overlay {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: grid;
	place-items: center;
	/* Stay below the Nextcloud header: its search bar would otherwise lie over
	   the top edge of a tall dialog. */
	padding: calc(var(--header-height, 50px) + 12px) 16px 12px;
	background: rgba(0, 0, 0, .5);
}
/* Head and foot stay put, only the body scrolls — the buttons stay within
   reach, even at 768 px height with a deadline and a warning. */
.pod {
	width: 100%;
	max-width: 560px;
	max-height: calc(100vh - var(--header-height, 50px) - 24px);
	display: flex;
	flex-direction: column;
	background: var(--vibears-bg);
	color: var(--vibears-text);
	border: 1px solid var(--vibears-border);
	border-radius: var(--vibears-r-card);
	box-shadow: var(--vibears-shadow-pop);
	box-sizing: border-box;
}
.pod:focus { outline: none; }
.pod-title { margin: 0; padding: 18px 22px 2px; font-size: var(--t-lead); font-weight: 800; }
.pod-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 8px 22px 6px; display: flex; flex-direction: column; gap: 16px; }
.pod-group { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
.pod-label { margin: 0; font-size: var(--t-body); font-weight: 800; }
.pod-sublabel { font-size: var(--t-sm); font-weight: 700; color: var(--vibears-text-2); }
.pod-help { margin: 0; font-size: var(--t-sm); color: var(--vibears-text-2); line-height: 1.45; }
.pod-deadline { align-self: stretch; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; margin-top: 6px; }
.pod-presets { display: flex; flex-wrap: wrap; gap: 8px; }
.pod-rel { margin: 0; font-size: var(--t-sm); color: var(--vibears-text-2); font-variant-numeric: tabular-nums; }
.pod-rel.is-error { color: var(--vibears-error); font-weight: 700; }
.pod-was { font-variant-numeric: tabular-nums; }
.pod-practice { align-items: center; flex-wrap: wrap; }
.pod-practice-txt { flex: 1 1 12em; font-weight: 600; }
.pod-warn, .pod-error { margin: 4px 0 0; align-self: stretch; }
.pod-foot { padding: 12px 22px 16px; border-block-start: 1px solid var(--vibears-border); display: flex; flex-direction: column; gap: 10px; }
.pod-lock { margin: 0; }
.pod-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.pod-sum { flex: 1 1 auto; font-size: var(--t-sm); font-weight: 700; color: var(--vibears-text-2); font-variant-numeric: tabular-nums; }
.pod-submit { min-width: 150px; }

/* Date + time: the same rules as .pinput in the moderator (border, focus,
   vibeARS tokens), 16 px font — anything smaller makes iOS zoom on tap. NC styles
   inputs globally via element selectors, hence the !important resets. */
.pace-input {
	background: var(--vibears-bg) !important;
	color: var(--vibears-text) !important;
	border: 2px solid var(--vibears-border-strong) !important;
	border-radius: var(--vibears-r-el) !important;
	padding: 9px 12px !important;
	font: inherit;
	font-size: 16px !important;
	width: 100%;
	max-width: 300px;
	height: auto !important;
	min-height: 44px;
	margin: 0 !important;
	box-sizing: border-box;
	font-variant-numeric: tabular-nums;
}
.pace-input:focus { border-color: var(--vibears-primary) !important; }
.pace-input:focus-visible { outline: 2px solid var(--vibears-primary); outline-offset: 1px; }
.pace-input[aria-invalid="true"] { border-color: var(--vibears-error) !important; }

@media (max-width: 480px) {
	.pod-title { padding-inline: 16px; }
	.pod-body { padding-inline: 16px; }
	.pod-foot { padding-inline: 16px; }
	.pod-sum { flex-basis: 100%; }
}
</style>
