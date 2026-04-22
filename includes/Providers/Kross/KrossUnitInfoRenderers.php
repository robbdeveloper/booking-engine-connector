<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * `[bec_unit_info key="..."]` renderers for Kross — maps keys to callables
 * that read the synced `bec_sync_payload` row (incl. `raw` API data).
 *
 * @see KrossProvider::getUnitInfoRenderers()
 */
final class KrossUnitInfoRenderers
{
	/**
	 * @return array<string, callable>
	 */
	public static function get(): array
	{
		$renderers = [
			// 'rooms_beds' => [ self::class, 'renderRoomsBeds' ],
		];

		/** @var array<string, callable> $out */
		$out = (array) \apply_filters('bec_kross_unit_info_renderers', $renderers);

		return $out;
	}
}
