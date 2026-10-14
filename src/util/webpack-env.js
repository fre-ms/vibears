/**
 * SPDX-FileCopyrightText: 2026 fre.ms
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Runtime settings for webpack's chunk loader, imported first by every
 * entry: the public path (where js/vibears-*.js chunks are fetched from —
 * with the instance's web root, which the build cannot know) and the CSP
 * nonce the injected <script> tags have to carry. Only chunks need this;
 * the entry bundles themselves are loaded by the page templates.
 */
import { getRequestToken } from '@nextcloud/auth'
import { chunkBase } from './routes.js'

// eslint-disable-next-line camelcase, no-undef
__webpack_public_path__ = chunkBase()
// eslint-disable-next-line camelcase, no-undef
__webpack_nonce__ = btoa(getRequestToken() || '')
