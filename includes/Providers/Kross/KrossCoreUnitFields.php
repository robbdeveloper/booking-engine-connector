<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Units\AmenityItem;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Maps Kross normalised rows to {@see CoreUnitSemantic} values for canonical post meta.
 */
final class KrossCoreUnitFields
{
	/**
	 * @param array<string, mixed> $row Normalised row (with `raw` from API).
	 *
	 * @return array<string, mixed> Keys are {@see CoreUnitSemantic} constants.
	 */
	public static function extract(array $row): array
	{
		$r = self::raw($row);
		$loc = self::resolveLocale($row);

		$name = self::pickLocaleString($r, 'be_name', $loc);
		if ($name === '') {
			$name = (string) ( $r['name_room_type'] ?? $row['name'] ?? '' );
		}

		$description = self::pickLocaleString($r, 'be_description', $loc);

		$amenities = \apply_filters('bec_provider_amenities_from_row', KrossAmenitiesExtractor::fromRow($row), $row, 'kross');
		$amenities = is_array($amenities) ? AmenityItem::normalizeList($amenities) : [];

		return [
			CoreUnitSemantic::NAME            => $name,
			CoreUnitSemantic::ADDRESS_FULL    => self::buildAddressFull($r),
			CoreUnitSemantic::LAT             => self::scalarString($r['latitude'] ?? null),
			CoreUnitSemantic::LNG             => self::scalarString($r['longitude'] ?? null),
			CoreUnitSemantic::OCC_MIN         => $r['min_occupancy'] ?? '',
			CoreUnitSemantic::OCC_MAX         => $r['max_occupancy'] ?? '',
			CoreUnitSemantic::CHECK_IN_FROM   => self::scalarString($r['check_in_from'] ?? null),
			CoreUnitSemantic::CHECK_IN_TO     => self::scalarString($r['check_in_to'] ?? null),
			CoreUnitSemantic::CHECK_OUT_UNTIL => self::scalarString($r['check_out_to'] ?? null),
			CoreUnitSemantic::ROOMS           => $r['n_bedrooms'] ?? '',
			CoreUnitSemantic::BATHROOMS       => self::pickBathrooms($r),
			CoreUnitSemantic::DESCRIPTION     => $description,
			CoreUnitSemantic::SQM             => $r['size_sqm'] ?? '',
			CoreUnitSemantic::AMENITIES       => $amenities,
			CoreUnitSemantic::GALLERY         => self::extractGalleryRemotePayload($r),
		];
	}

	/**
	 * Shape consumed by {@see CoreUnitFieldRegistry} for sideload into `bec_core_gallery`.
	 *
	 * @return array{urls: list<string>, featured_url: string|null}
	 */
	private static function extractGalleryRemotePayload(array $raw): array
	{
		$images = $raw['images'] ?? null;
		if (! is_array($images)) {
			return [
				'urls'          => [],
				'featured_url' => null,
			];
		}

		$rows = [];
		foreach ($images as $img) {
			if (! is_array($img)) {
				continue;
			}
			$url = isset($img['url']) ? \trim((string) $img['url']) : '';
			if ($url === '' || ( ! \str_starts_with($url, 'http://') && ! \str_starts_with($url, 'https://') )) {
				continue;
			}
			$order = isset($img['image_order']) ? (int) $img['image_order'] : 0;
			$rows[] = [
				'url'  => $url,
				'order' => $order,
				'main' => ! empty($img['main']),
			];
		}

		\usort(
			$rows,
			static function (array $a, array $b): int {
				return $a['order'] <=> $b['order'];
			}
		);

		$urls = [];
		foreach ($rows as $row) {
			$urls[] = $row['url'];
		}

		$featured = null;
		foreach ($rows as $row) {
			if ($row['main']) {
				$featured = $row['url'];
				break;
			}
		}
		if ($featured === null && $urls !== []) {
			$featured = $urls[0];
		}

		return [
			'urls'           => $urls,
			'featured_url'   => $featured,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function raw(array $row): array
	{
		$r = $row['raw'] ?? null;

		return is_array($r) ? $r : [];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function resolveLocale(array $row): string
	{
		$wp = \get_locale();
		$two = strlen($wp) >= 2 ? strtolower(substr($wp, 0, 2)) : 'en';

		return (string) \apply_filters('bec_core_unit_locale', $two, $wp, $row);
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	private static function pickLocaleString(array $raw, string $field, string $locale): string
	{
		$block = $raw[ $field ] ?? null;
		if (! is_array($block)) {
			return '';
		}

		$primary = isset($block[ $locale ]) ? (string) $block[ $locale ] : '';
		if ($primary !== '') {
			return $primary;
		}

		foreach (['en', 'it', 'de', 'fr', 'es'] as $fallback) {
			if (isset($block[ $fallback ]) && (string) $block[ $fallback ] !== '') {
				return (string) $block[ $fallback ];
			}
		}

		foreach ($block as $v) {
			if (is_string($v) && $v !== '') {
				return $v;
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private static function buildAddressFull(array $r): string
	{
		$parts = [];
		foreach (['address', 'city', 'post_code', 'area'] as $k) {
			$v = $r[ $k ] ?? null;
			if ($v !== null && $v !== '') {
				$parts[] = (string) $v;
			}
		}
		$cc = $r['cod_country'] ?? null;
		if ($cc !== null && $cc !== '') {
			$parts[] = (string) $cc;
		}

		return implode(', ', $parts);
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private static function pickBathrooms(array $r): string
	{
		foreach (['number_of_bathrooms', 'n_bathrooms', 'qt_bathrooms', 'bathrooms'] as $k) {
			if (isset($r[ $k ]) && $r[ $k ] !== null && $r[ $k ] !== '') {
				return is_numeric($r[ $k ]) ? (string) ( $r[ $k ] + 0 ) : (string) $r[ $k ];
			}
		}

		return '';
	}

	/**
	 * @param mixed $v
	 */
	private static function scalarString($v): string
	{
		if ($v === null || $v === '') {
			return '';
		}

		return (string) $v;
	}
}
