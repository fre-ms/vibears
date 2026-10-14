/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// First: public path and CSP nonce for chunks (the KaTeX chunk), see util/webpack-env.js.
import './util/webpack-env.js'
import Vue from 'vue'
import { t, n } from './util/l10n.js'
// Design-system foundation: load tokens + primitives globally (un-scoped),
// so they reach the moderator and every scoped component style.
import './styles/vibears-tokens.css'
import './styles/vibears-ds.css'
import Moderator from './Moderator.vue'

// Translations globally: t/n are available in template and script (app id
// "vibears"). Nextcloud loads the language file l10n/<lang>.js before this bundle.
Vue.prototype.t = t
Vue.prototype.n = n

// eslint-disable-next-line no-new
new Vue({
	el: '#vibears-app',
	render: (h) => h(Moderator),
})
