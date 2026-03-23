<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Sync\UnitSyncFieldDefinition;

/**
 * Optional provider-specific meta (beyond {@see CoreUnitSemantic} / `bec_core_*`).
 * Default: none — add entries via this class, or filter `bec_unit_sync_field_definitions`.
 */
final class KrossUnitSyncFieldDefinitions
{
	/**
	 * @return list<UnitSyncFieldDefinition>
	 */
	public static function get(): array
	{
		return [];
	}
}
