<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Controller;

use OCA\Vibears\AppInfo\Application;
use OCA\Vibears\Service\JoinAddresses;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(
        IRequest $request,
        private IInitialState $initialState,
        private JoinAddresses $joinAddresses,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Entry point of the moderator SPA. Any signed-in user may create rooms.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // The addresses the join link can use (trusted_domains), with the
        // current one first — the moderator picks one per room.
        $this->initialState->provideInitialState('joinHosts', $this->joinAddresses->view());
        $response = new TemplateResponse(Application::APP_ID, 'index');
        // The default CSP is enough: the app only talks same-origin to its own API.
        $response->setContentSecurityPolicy(new ContentSecurityPolicy());
        return $response;
    }
}
