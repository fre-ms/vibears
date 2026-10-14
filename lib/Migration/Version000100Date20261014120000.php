<?php

// SPDX-FileCopyrightText: 2026 fre.ms
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Vibears\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The address a room's join link uses (JoinAddresses): a host from
 * trusted_domains, or NULL for the address of the page the link is shown on.
 */
class Version000100Date20261014120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $rooms = $schema->getTable('vibears_rooms');
        if (!$rooms->hasColumn('join_host')) {
            $rooms->addColumn('join_host', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        }
        return $schema;
    }
}
