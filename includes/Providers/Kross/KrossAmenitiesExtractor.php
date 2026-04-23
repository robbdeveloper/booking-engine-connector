<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Maps Kross `amenities[]` (room/amenity codes from the API) to modular amenity items.
 *
 * `mandatory_services` is separate product-level data; it is not merged into this list
 * (use {@see bec_kross_amenities_from_raw} or {@see bec_provider_amenities_from_row} if
 * you need to attach extra items for storage in `bec_core_amenities`).
 *
 * Filters: {@see bec_kross_amenities_from_raw}, {@see bec_provider_amenities_from_row}.
 */
final class KrossAmenitiesExtractor
{
	/**
	 * @param array<string, mixed> $normalisedRow
	 * @return list<array<string, mixed>>
	 */
	public static function fromRow(array $normalisedRow): array
	{
		$r = $normalisedRow['raw'] ?? null;
		if (! is_array($r)) {
			$r = [];
		}

		$items = self::fromAmenitiesArray($r['amenities'] ?? null);

		/** @var list<array<string, mixed>> $items */
		$out = \apply_filters('bec_kross_amenities_from_raw', $items, $r, $normalisedRow);

		return is_array($out) ? $out : [];
	}

	/**
	 * @param mixed $amenities
	 * @return list<array<string, mixed>>
	 */
	private static function fromAmenitiesArray($amenities): array
	{
		if (! is_array($amenities)) {
			return [];
		}

		$out = [];
		foreach ($amenities as $a) {
			if (! is_array($a)) {
				continue;
			}
			$code = isset($a['cod_amenity']) ? \sanitize_key((string) $a['cod_amenity']) : '';
			if ($code === '') {
				continue;
			}
			$labels = [];
			$tr     = $a['name_amenity_translations'] ?? null;
			if (is_array($tr)) {
				foreach ($tr as $loc => $text) {
					$loc = \sanitize_key((string) $loc);
					if ($loc === '') {
						continue;
					}
					$labels[ $loc ] = \sanitize_text_field((string) $text);
				}
			}
			if ($labels === [] && isset($a['name_amenity'])) {
				$labels['en'] = \sanitize_text_field((string) $a['name_amenity']);
			}
			$out[] = [
				'key'      => $code,
				'labels'   => $labels,
				'category' => 'amenity',
			];
		}

		return $out;
	}
}
