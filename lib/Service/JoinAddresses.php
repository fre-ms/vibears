<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Service;

use OCP\IConfig;
use OCP\IRequest;

/**
 * The addresses under which this Nextcloud is reachable, for the join link
 * and its QR code. An instance that is reachable under several names
 * (config.php: trusted_domains — an internal name, a public one, a short
 * alias for the lecture hall) lets the moderator choose which of them the
 * audience gets; the choice is kept per room (Room::joinHost), so the
 * moderator's panel, the projector and the PowerPoint add-in all show the
 * same address.
 *
 * The list is the host of the current request first (that is what the
 * link used before this setting existed), then every entry of
 * trusted_domains that is a plain host — wildcard entries such as
 * `*.example.com` cannot be turned into a link and are left out.
 *
 * The scheme of an address cannot be read from trusted_domains. The current
 * request's host gets the current request's scheme; every other address
 * gets `overwriteprotocol` where it is set, otherwise https — except names
 * that only make sense inside a network (localhost, loopback and private
 * addresses, *.local, *.localhost), which get http.
 */
class JoinAddresses {
    public function __construct(
        private IConfig $config,
        private IRequest $request,
    ) {
    }

    /**
     * @return list<string> the current host first, then the configured ones, each once, lower-cased
     */
    public function hosts(): array {
        $hosts = [];
        $current = $this->currentHost();
        if ($current !== '') {
            $hosts[] = $current;
        }
        $trusted = $this->config->getSystemValue('trusted_domains', []);
        foreach (is_array($trusted) ? $trusted : [] as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $host = strtolower(trim($entry));
            if ($host === '' || str_contains($host, '*') || preg_match('/[\s\/]/', $host) === 1) {
                continue;
            }
            if (!in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
        return $hosts;
    }

    public function currentHost(): string {
        return strtolower(trim($this->request->getServerHost()));
    }

    /** Is this one of the configured addresses? (case-insensitive) */
    public function isAllowed(string $host): bool {
        return in_array(strtolower(trim($host)), $this->hosts(), true);
    }

    /** scheme://host for a configured address. */
    public function originFor(string $host): string {
        $host = strtolower(trim($host));
        return $this->scheme($host) . '://' . $host;
    }

    /**
     * The origin a room's join link uses, or null when the room has no
     * address of its own or its address is no longer configured — the
     * client then falls back to the address of the page it is on.
     */
    public function joinOrigin(?string $host): ?string {
        if ($host === null || $host === '' || !$this->isAllowed($host)) {
            return null;
        }
        return $this->originFor($host);
    }

    /**
     * For the moderator page: every address with its origin, and the current one.
     *
     * @return array{hosts: list<array{host: string, origin: string}>, current: string}
     */
    public function view(): array {
        return [
            'hosts' => array_map(fn (string $h): array => ['host' => $h, 'origin' => $this->originFor($h)], $this->hosts()),
            'current' => $this->currentHost(),
        ];
    }

    private function scheme(string $host): string {
        if ($host === $this->currentHost()) {
            return $this->request->getServerProtocol();
        }
        $forced = $this->config->getSystemValueString('overwriteprotocol', '');
        if ($forced === 'http' || $forced === 'https') {
            return $forced;
        }
        return self::isLocalName($host) ? 'http' : 'https';
    }

    /** localhost, loopback and private addresses, *.local and *.localhost: typically served without TLS. */
    public static function isLocalName(string $host): bool {
        $bare = preg_replace('/:\d+$/', '', $host) ?? $host;
        if (in_array($bare, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            return true;
        }
        if (str_ends_with($bare, '.local') || str_ends_with($bare, '.localhost')) {
            return true;
        }
        return preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)\d/', $bare) === 1;
    }
}
