<?php

declare(strict_types=1);

namespace BookingEngineConnector\Core;

use BookingEngineConnector\Sync\SyncCron;

/**
 * Runs on plugin deactivation (no data removal).
 */
final class Deactivator
{
	public static function deactivate(): void
	{
		SyncCron::clear();
	}
}
