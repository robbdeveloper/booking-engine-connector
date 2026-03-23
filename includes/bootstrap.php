<?php

declare(strict_types=1);

namespace BookingEngineConnector;

use BookingEngineConnector\Core\Migrations\MigrationRunner;
use BookingEngineConnector\Logging\Migrations\CreateApiLogTable;

MigrationRunner::register(1, [CreateApiLogTable::class, 'up']);
