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
			CoreUnitSemantic::CIN             => self::scalarString($r['cin'] ?? null),
		];
	}

	/**
	 * Shape consumed by {@see CoreUnitFieldRegistry} for sideload into `bec_core_gallery`.
	 *
	 * - `items`: per-image URL, order, main flag, and a stable `key` for unit-scoped sync (Kross: remote id if present, else hash of normalised URL).
	 * - `urls`: ordered URLs (backward compatibility for filters and legacy consumers).
	 *
	 * @return array{items: list<array{url: string, key: string, order: int, main: bool}>, urls: list<string>, featured_url: string|null}
	 */
	private static function extractGalleryRemotePayload(array $raw): array
	{
		$images = self::imageRowsFromRaw($raw);
		if ($images === []) {
			return [
				'items'         => [],
				'urls'          => [],
				'featured_url' => null,
			];
		}

		$rows = [];
		$seq  = 0;
		foreach ($images as $img) {
			$imgRow = \is_array($img) ? $img : [];
			$url = self::imageUrlFromDescriptor($img);
			if ($url === '' || ( ! \str_starts_with($url, 'http://') && ! \str_starts_with($url, 'https://') )) {
				continue;
			}
			$nurl = \esc_url_raw($url);
			if ($nurl === '' || ( ! \str_starts_with($nurl, 'http://') && ! \str_starts_with($nurl, 'https://') )) {
				continue;
			}
			$imageOrder = null;
			if (isset($imgRow['image_order']) && $imgRow['image_order'] !== null && $imgRow['image_order'] !== '' && \is_numeric($imgRow['image_order'])) {
				$imageOrder = (int) $imgRow['image_order'];
			}
			$rows[] = [
				'url'           => $nurl,
				'image_order'   => $imageOrder,
				'seq'           => $seq,
				// Only explicit truthy API values mark the featured image (`main: true`; not `null` / empty).
				'main'          => \filter_var($imgRow['main'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
				'key'           => self::krossImageStableKey($imgRow, $nurl),
			];
			++$seq;
		}

		// Sort by Kross image_order when present; missing orders sort last, preserving payload order via seq. Tie-break equal orders by seq.
		\usort(
			$rows,
			static function (array $a, array $b): int {
				$aO = $a['image_order'];
				$bO = $b['image_order'];
				if ($aO === null && $bO === null) {
					return $a['seq'] <=> $b['seq'];
				}
				if ($aO === null) {
					return 1;
				}
				if ($bO === null) {
					return -1;
				}
				$c = $aO <=> $bO;

				return $c !== 0 ? $c : $a['seq'] <=> $b['seq'];
			}
		);

		$usedKeys = [];
		$items    = [];
		$urls     = [];
		foreach ($rows as $row) {
			$baseKey = (string) $row['key'];
			$k       = $baseKey;
			$n       = 0;
			while (isset($usedKeys[ $k ])) {
				++$n;
				$k = $baseKey . ':' . (string) $n;
			}
			$usedKeys[ $k ] = true;
			// Importer sorts by numeric `order`; use image_order when set, else a stable sentinel after real orders.
			$orderVal = $row['image_order'] !== null ? (int) $row['image_order'] : (1000000 + (int) $row['seq']);
			$items[] = [
				'url'   => (string) $row['url'],
				'key'   => $k,
				'order' => $orderVal,
				'main'  => (bool) $row['main'],
			];
			$urls[]  = (string) $row['url'];
		}

		$featured = null;
		foreach ($items as $it) {
			if (! empty($it['main'])) {
				$featured = (string) $it['url'];
				break;
			}
		}
		if ($featured === null && $urls !== []) {
			$featured = $urls[0];
		}

		return [
			'items'         => $items,
			'urls'          => $urls,
			'featured_url'  => $featured,
		];
	}

	/**
	 * @return list<mixed>
	 */
	private static function imageRowsFromRaw(array $raw): array
	{
		$candidates = ['images', 'images_full', 'room_type_images', 'gallery', 'photos', 'pictures'];
		foreach ($candidates as $key) {
			if (! isset($raw[ $key ]) || ! is_array($raw[ $key ])) {
				continue;
			}

			return \array_values($raw[ $key ]);
		}

		return [];
	}

	private static function imageUrlFromDescriptor($img): string
	{
		if (\is_string($img)) {
			return \trim($img);
		}

		if (! \is_array($img)) {
			return '';
		}

		$candidates = ['url', 'image_url', 'full_url', 'url_image', 'image', 'src'];
		foreach ($candidates as $key) {
			if (isset($img[ $key ]) && \is_scalar($img[ $key ])) {
				$url = \trim((string) $img[ $key ]);
				if ($url !== '') {
					return $url;
				}
			}
		}

		if (isset($img['urls']) && \is_array($img['urls'])) {
			foreach (['original', 'full', 'large', 'medium', 'url'] as $key) {
				if (isset($img['urls'][ $key ]) && \is_scalar($img['urls'][ $key ])) {
					$url = \trim((string) $img['urls'][ $key ]);
					if ($url !== '') {
						return $url;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Prefer a remote id from the API payload; otherwise hash the normalised URL.
	 *
	 * @param array<string, mixed> $img
	 */
	private static function krossImageStableKey(array $img, string $normalisedUrl): string
	{
		$candidates = ['id', 'id_image', 'id_img', 'image_id', 'id_image_type'];
		foreach ($candidates as $ck) {
			if (! isset($img[ $ck ])) {
				continue;
			}
			$v = $img[ $ck ];
			if (is_int($v) || is_float($v)) {
				$s = (string) ( (int) $v );
			} else {
				$s = \trim((string) $v);
			}
			if ($s !== '' && $s !== '0') {
				$part = \trim( \preg_replace( '/[^a-z0-9_-]+/i', '-', $s ), '-' );
				if ($part !== '') {
					return 'kross:' . $part;
				}
			}
		}

		return \hash('sha256', $normalisedUrl);
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
