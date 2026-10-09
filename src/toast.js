/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Minimal toast replacement for @nextcloud/dialogs.
// Reason: dialogs pulled ~3 MB of lazy chunks (FilePicker, rehype-highlight) into the bundle
// just so that showError() can display a message. This one has no dependencies at all:
// it works on the signed-in moderator page AND the public guest page,
// follows the NC theme via CSS variables and respects prefers-reduced-motion.
import { t } from './util/l10n.js'

let container = null
let stylesInjected = false

function injectStyles() {
	if (stylesInjected) { return }
	stylesInjected = true
	const style = document.createElement('style')
	style.textContent = `
	.vibears-toasts { position: fixed; top: 12px; left: 50%; transform: translateX(-50%);
		z-index: 100000; display: flex; flex-direction: column; gap: 8px; align-items: center;
		pointer-events: none; max-width: 92vw; }
	.vibears-toast { pointer-events: auto; cursor: pointer;
		background: var(--color-error, #c73e3e); color: #fff;
		padding: 12px 18px; border-radius: var(--border-radius-large, 12px);
		box-shadow: 0 4px 16px rgba(0, 0, 0, .28); font-size: 15px; font-weight: 500;
		max-width: 480px; line-height: 1.35;
		animation: vibears-toast-in .18s cubic-bezier(.22, 1, .36, 1); }
	.vibears-toast.is-ok { background: var(--color-success, #2d7d46); }
	.vibears-toast.is-out { animation: vibears-toast-out .18s ease forwards; }
	@keyframes vibears-toast-in { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
	@keyframes vibears-toast-out { to { opacity: 0; transform: translateY(-8px); } }
	@media (prefers-reduced-motion: reduce) { .vibears-toast, .vibears-toast.is-out { animation: none; } }
	`
	document.head.appendChild(style)
}

function ensureContainer() {
	if (container && document.body.contains(container)) { return container }
	injectStyles()
	container = document.createElement('div')
	container.className = 'vibears-toasts'
	document.body.appendChild(container)
	return container
}

/**
 * Shows a short error message. Auto-dismiss after `timeout` ms, a click closes it.
 * Signature-compatible with the previous showError usage.
 */
function showToast(message, opts = {}) {
	const variant = opts.variant || 'error'
	const el = document.createElement('div')
	el.className = variant === 'success' ? 'vibears-toast is-ok' : 'vibears-toast'
	el.setAttribute('role', opts.role || 'alert')
	el.textContent = String(message || (variant === 'success' ? t('vibears', 'Done') : t('vibears', 'Error')))
	const remove = () => {
		if (!el.parentNode) { return }
		el.classList.add('is-out')
		setTimeout(() => el.remove(), 200)
	}
	el.addEventListener('click', remove)
	ensureContainer().appendChild(el)
	setTimeout(remove, opts.timeout || 5000)
	return { hideToast: remove }
}

/** Short error message (red, role=alert). A click or the timeout closes it. */
export function showError(message, timeout = 5000) {
	return showToast(message, { variant: 'error', role: 'alert', timeout })
}

/**
 * The server's own message of a failed request (it says why), else `fallback`.
 * @param {Error} e axios error (no response on a timeout or network error)
 * @param {string} fallback text for when the server gave no message
 * @return {string} the message to show
 */
export function serverMessage(e, fallback) {
	return e?.response?.data?.message || fallback
}

/** Short success message (green, role=status). */
export function showSuccess(message, timeout = 3000) {
	return showToast(message, { variant: 'success', role: 'status', timeout })
}
