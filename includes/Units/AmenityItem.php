<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units;

/**
 * Normalises amenities to a portable list for JSON storage in {@see CoreUnitSemantic::AMENITIES}.
 *
 * Shape per item:
 * - `key` (string, stable id, e.g. "wifi")
 * - `labels` (array<string, string>) locale code => human label
 * - `icon` (string, optional) icon token for the theme (dashicon slug, SVG id, etc.)
 * - `category` (string, optional) e.g. amenity vs mandatory_service
 *
 * Providers plug in via {@see bec_provider_amenities_from_row} or their own extractor before
 * values reach {@see ProviderInterface::extractCoreUnitFields()}.
 */
final class AmenityItem
{
	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array{key: string, labels: array<string, string>, icon?: string, category?: string}>
	 */
	public static function normalizeList(array $items): array
	{
		$out = [];
		foreach ($items as $item) {
			if (! is_array($item)) {
				continue;
			}
			$key = isset($item['key']) ? \sanitize_key((string) $item['key']) : '';
			if ($key === '') {
				continue;
			}
			$labels = [];
			if (isset($item['labels']) && is_array($item['labels'])) {
				foreach ($item['labels'] as $loc => $text) {
					$loc = \sanitize_key((string) $loc);
					if ($loc === '') {
						continue;
					}
					$labels[ $loc ] = \sanitize_text_field((string) $text);
				}
			}
			$row = [
				'key'    => $key,
				'labels' => $labels,
			];
			if (isset($item['icon']) && $item['icon'] !== '') {
				$row['icon'] = \sanitize_text_field((string) $item['icon']);
			}
			if (isset($item['category']) && $item['category'] !== '') {
				$row['category'] = \sanitize_key((string) $item['category']);
			}
			$out[] = $row;
		}

		return $out;
	}
}
