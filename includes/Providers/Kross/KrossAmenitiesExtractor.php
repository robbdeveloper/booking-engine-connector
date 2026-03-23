<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Maps Kross `amenities` and `mandatory_services` (when requested) to modular amenity items.
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
		$items = array_merge($items, self::fromMandatoryServices($r));

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

	/**
	 * @param array<string, mixed> $raw
	 * @return list<array<string, mixed>>
	 */
	private static function fromMandatoryServices(array $raw): array
	{
		$mandatory = $raw['mandatory_services'] ?? null;
		if (! is_array($mandatory)) {
			$be = $raw['be_info'] ?? null;
			if (is_array($be) && isset($be['mandatory_services']) && is_array($be['mandatory_services'])) {
				$mandatory = $be['mandatory_services'];
			}
		}
		if (! is_array($mandatory)) {
			return [];
		}

		$out = [];
		foreach ($mandatory as $svc) {
			if (! is_array($svc)) {
				continue;
			}
			$id = '';
			if (isset($svc['id_service'])) {
				$id = (string) $svc['id_service'];
			} elseif (isset($svc['id'])) {
				$id = (string) $svc['id'];
			}

			$key = $id !== '' ? 'mandatory_service_' . \sanitize_key($id) : '';

			$labels = [];
			foreach (['description', 'name_service', 'name', 'label'] as $k) {
				if (isset($svc[ $k ]) && (string) $svc[ $k ] !== '') {
					$labels['en'] = \sanitize_text_field((string) $svc[ $k ]);
					break;
				}
			}

			if ($key === '') {
				$slug = isset($svc['cod_service']) ? \sanitize_key((string) $svc['cod_service']) : '';
				if ($slug === '' && isset($labels['en'])) {
					$slug = \sanitize_key(\substr($labels['en'], 0, 40));
				}
				if ($slug === '') {
					continue;
				}
				$key = 'mandatory_' . $slug;
			}

			if ($labels === []) {
				$labels['en'] = $key;
			}

			$out[] = [
				'key'      => $key,
				'labels'   => $labels,
				'category' => 'mandatory_service',
			];
		}

		return $out;
	}
}
