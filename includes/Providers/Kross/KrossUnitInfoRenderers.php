<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Front\AmenitiesAssets;
use BookingEngineConnector\Units\AmenityItem;

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
			'amenities_grid' => [ self::class, 'renderAmenitiesGrid' ],
		];

		/** @var array<string, callable> $out */
		$out = (array) \apply_filters('bec_kross_unit_info_renderers', $renderers);

		return $out;
	}

	/**
	 * Amenity grid: icon (amenities font `icon-{key}`) + localized label.
	 *
	 * Optional pass-through attributes:
	 * - `font_pack` — icon pack slug; default `font-1` (see filter `bec_amenities_font_packs`).
	 * - `columns` — grid column count, 1–6 (default 2).
	 * - `limit` — max number of items (0 = all).
	 * - `category` — if set, only items with this category (e.g. `amenity`). Legacy
	 *   `mandatory_service` entries are never shown in this grid, even in old `bec_core_amenities` meta.
	 *
	 * @param array<string, mixed>    $syncPayload
	 * @param array<string, string>   $atts
	 * @param array{provider: string, locale: string} $context
	 */
	public static function renderAmenitiesGrid(array $syncPayload, int $postId, array $atts, array $context): string
	{
		$locale  = self::contextLocale($context);
		$items   = self::loadAmenityItems($syncPayload, $postId);
		$items   = self::excludeStoredMandatoryServiceItems($items);
		$items   = self::filterByCategory($items, $atts);
		$items   = self::sortByLabel($items, $locale);
		$limit   = self::intAttr($atts, 'limit', 0);
		if ($limit > 0) {
			$items = \array_slice($items, 0, $limit);
		}
		if ($items === []) {
			return '';
		}

		$columns = \max(1, \min(6, self::intAttr($atts, 'columns', 2)));
		$style     = 'style="' . \esc_attr('--bec-amenities-cols: ' . $columns . ';') . '"';

		$li = [];
		foreach ($items as $row) {
			$key  = (string) ($row['key'] ?? '');
			$labs = \is_array($row['labels'] ?? null) ? (array) $row['labels'] : [];
			if ($key === '') {
				continue;
			}
			$label = self::pickLabel($labs, $locale);
			if ($label === '') {
				$label = $key;
			}
			$iconClass    = 'bec-amenities__icon icon-' . $key;
			$li[] = '<li class="bec-amenities__item">'
				. '<i class="' . \esc_attr($iconClass) . '" aria-hidden="true"></i>'
				. '<span class="bec-amenities__label">' . \esc_html($label) . '</span>'
				. '</li>';
		}

		if ($li === []) {
			return '';
		}

		AmenitiesAssets::enqueueForKross($postId, $atts);

		return '<ul class="bec-amenities bec-amenities--kross" ' . $style . ' role="list">'
			. \implode('', $li)
			. '</ul>';
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
		$decoded  = $fromMeta !== '' ? \json_decode($fromMeta, true) : null;
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
	 * Drops mandatory-service rows that may still be present in pre-change `bec_core_amenities` JSON
	 * (sync has not re-run since {@see KrossAmenitiesExtractor} stopped merging them).
	 *
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
		// Legacy keys from the old extractor: mandatory_service_*, mandatory_{slug} (not a Kross `cod_amenity`).
		if (\str_starts_with($key, 'mandatory_')) {
			return true;
		}

		return false;
	}

	/**
	 * @param list<array{key: string, labels: array<string, string>, category?: string, icon?: string}> $items
	 * @param array<string, string> $atts
	 * @return list<array{key: string, labels: array<string, string>, category?: string, icon?: string}>
	 */
	private static function filterByCategory(array $items, array $atts): array
	{
		if (! isset($atts['category']) || (string) $atts['category'] === '') {
			return $items;
		}
		$want = \sanitize_key((string) $atts['category']);
		if ($want === '') {
			return $items;
		}
		$out = [];
		foreach ($items as $row) {
			$cat = isset($row['category']) ? \sanitize_key((string) $row['category']) : '';
			if ($cat === $want) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * @param list<array{key: string, labels: array<string, string>, category?: string, icon?: string}> $items
	 * @return list<array{key: string, labels: array<string, string>, category?: string, icon?: string}>
	 */
	private static function sortByLabel(array $items, string $locale): array
	{
		\usort(
			$items,
			static function (array $a, array $b) use ($locale): int {
				$la = self::pickLabel(
					\is_array($a['labels'] ?? null) ? (array) $a['labels'] : [],
					$locale
				);
				$lb = self::pickLabel(
					\is_array($b['labels'] ?? null) ? (array) $b['labels'] : [],
					$locale
				);
				if ($la === '' && $lb === '') {
					return \strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
				}

				return \strnatcasecmp($la !== '' ? $la : (string) ($a['key'] ?? ''), $lb !== '' ? $lb : (string) ($b['key'] ?? ''));
			}
		);

		return $items;
	}

	/**
	 * @param array<string, string> $labels
	 */
	private static function pickLabel(array $labels, string $locale): string
	{
		if (isset($labels[ $locale ]) && (string) $labels[ $locale ] !== '') {
			return (string) $labels[ $locale ];
		}
		if (isset($labels['en']) && (string) $labels['en'] !== '') {
			return (string) $labels['en'];
		}
		foreach ($labels as $text) {
			$t = (string) $text;
			if ($t !== '') {
				return $t;
			}
		}

		return '';
	}

	/**
	 * @param array<string, string> $atts
	 */
	private static function intAttr(array $atts, string $name, int $default): int
	{
		if (! isset($atts[ $name ]) || (string) $atts[ $name ] === '') {
			return $default;
		}
		$v = (int) $atts[ $name ];

		return $v > 0 ? $v : $default;
	}
}
