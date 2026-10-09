<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Controller;

use OCA\Pulse\AppInfo\Application;
use OCA\Pulse\Service\Input;
use OCA\Pulse\Service\Retention;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Saves the administration settings (templates/admin.php). Deliberately
 * without #[NoAdminRequired]: only an administrator may change what the
 * daily job deletes. The CSRF check stays on; the settings page's script
 * sends the request token along, as every page script in Nextcloud does.
 */
class AdminSettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private Retention $retention,
        private IL10N $l10n,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Body: { enabled: bool, days: int }. The days are clamped to the
     * service's bounds; the answer carries what is stored now, so the form
     * can show it. Unusable input (a list, text, 1e999) is a 400.
     */
    public function retention(): JSONResponse {
        $enabled = Input::flag($this->request->getParam('enabled'));
        $days = Input::int($this->request->getParam('days'));
        if ($enabled === null || $days === null) {
            return new JSONResponse(['message' => $this->l10n->t('Invalid request.')], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse($this->retention->store($enabled, $days));
    }
}
