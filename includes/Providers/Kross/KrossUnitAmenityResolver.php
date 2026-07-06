<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Sync\SyncPayloadEncoder;
use BookingEngineConnector\Taxonomies\UnitAmenityTaxonomy;
use BookingEngineConnector\Units\AmenityItem;

/**
 * Resolves a single amenity item for `[bec_unit_amenity]` from Kross synced unit data.
 *
 * Labels come from API locale maps (`name_amenity_translations`); use
 * {@see bec_kross_unit_amenity_display_label} to override fallbacks.
 */
final class KrossUnitAmenityResolver
{
	/**
	 * @param array<string, mixed>    $syncPayload Decoded `bec_sync_payload`.
	 * @param array<string, string>   $atts        Pass-through shortcode attributes.
	 * @param array{provider: string, locale: string} $context
	 *
	 * @return array{key: string, label: string, labels: array<string, string>, category?: string, icon?: string}|null
	 */
	public static function resolve(array $syncPayload, int $postId, string $amenityKey, array $atts, array $context): ?array
	{
		$key = \sanitize_key($amenityKey);
		if ($key === '') {
			return null;
		}

		$locale = self::contextLocale($context);
		$items  = self::excludeStoredMandatoryServiceItems(self::loadAmenityItems($syncPayload, $postId));

		foreach ($items as $row) {
			if ((string) ($row['key'] ?? '') !== $key) {
				continue;
			}

			$labels = \is_array($row['labels'] ?? null) ? (array) $row['labels'] : [];
			$label  = UnitAmenityTaxonomy::resolveLocalizedLabelFromNames($labels, $locale);
			if ($label === '') {
				$label = $key;
			}

			$label = (string) \apply_filters(
				'bec_kross_unit_amenity_display_label',
				$label,
				$key,
				$labels,
				$locale,
				$postId
			);

			$out = [
				'key'    => $key,
				'label'  => $label,
				'labels' => $labels,
			];

			if (isset($row['category']) && (string) $row['category'] !== '') {
				$out['category'] = (string) $row['category'];
			}
			if (isset($row['icon']) && (string) $row['icon'] !== '') {
				$out['icon'] = (string) $row['icon'];
			}

			/** @var array{key: string, label: string, labels: array<string, string>, category?: string, icon?: string} $filtered */
			$filtered = (array) \apply_filters(
				'bec_kross_unit_amenity_item',
				$out,
				$key,
				$postId,
				$syncPayload,
				$atts,
				$context
			);

			if (! isset($filtered['key'], $filtered['label']) || (string) $filtered['key'] === '') {
				return null;
			}

			return $filtered;
		}

		return null;
	}

	/**
	 * @param array{provider: string, locale: string} $context
	 */
	private static function contextLocale(array $context): string
	{
		$loc = isset($context['locale']) ? \strtolower((string) $context['locale']) : 'en';
		if (! \preg_match('/^[a-z]{2}$/', $loc)) {
			$loc = 'en';
		}

		return $loc;
	}

	/**
	 * @return list<array{key: string, labels: array<string, string>, category?: string, icon?: string}>
	 */
	private static function loadAmenityItems(array $syncPayload, int $postId): array
	{
		$fromMeta = (string) \get_post_meta($postId, 'bec_core_amenities', true);
		$decoded  = $fromMeta !== '' ? SyncPayloadEncoder::decodeMetaJson($fromMeta) : null;
		if (\is_array($decoded) && $decoded !== []) {
			$norm = AmenityItem::normalizeList($decoded);
			if ($norm !== []) {
				return $norm;
			}
		}

		$rawItems = KrossAmenitiesExtractor::fromRow($syncPayload);
		$norm     = AmenityItem::normalizeList($rawItems);

		/** @var list<array{key: string, labels: array<string, string>, category?: string, icon?: string}> $norm */
		return $norm;
	}

	/**
	 * @param list<array{key: string, labels: array<string, string>, category?: string, icon?: string}> $items
	 * @return list<array{key: string, labels: array<string, string>, category?: string, icon?: string}>
	 */
	private static function excludeStoredMandatoryServiceItems(array $items): array
	{
		$out = [];
		foreach ($items as $row) {
			if (self::isLegacyMandatoryOrMandatoryService($row)) {
				continue;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @param array{key: string, labels: array<string, string>, category?: string, icon?: string} $row
	 */
	private static function isLegacyMandatoryOrMandatoryService(array $row): bool
	{
		$cat = isset($row['category']) ? \sanitize_key((string) $row['category']) : '';
		if ($cat === 'mandatory_service') {
			return true;
		}
		$key = isset($row['key']) ? (string) $row['key'] : '';
		if ($key === '') {
			return false;
		}
		if (\str_starts_with($key, 'mandatory_')) {
			return true;
		}

		return false;
	}
}
