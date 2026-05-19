<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Front\AmenitiesAssets;
use BookingEngineConnector\Sync\SyncPayloadEncoder;
use BookingEngineConnector\Units\AmenityItem;

/**
 * `[bec_unit_info key="..."]` renderers for Kross — maps keys to callables
 * that read the synced `bec_sync_payload` row (incl. `raw` API data).
 *
 * Amenity and bedroom labels come from the API (`labels` per locale); fixed UI strings belong in
 * gettext. When the API omits a label, the amenity key is shown — use the
 * `bec_kross_unit_amenity_display_label` filter to map or translate those fallbacks.
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
			'amenities_grid'        => [ self::class, 'renderAmenitiesGrid' ],
			'bedroom_arrangements'  => [ self::class, 'renderBedroomArrangements' ],
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
			$label = (string) \apply_filters(
				'bec_kross_unit_amenity_display_label',
				$label,
				$key,
				$labs,
				$locale,
				$postId
			);
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
	 * Bed type grid per `raw.bedroom_details` (Kross with `with_bed_bath_details`).
	 *
	 * Optional pass-through attributes:
	 * - `font_pack` — icon pack slug; default `font-1` (see filter `bec_amenities_font_packs`).
	 * - `columns` — grid column count, 1–6 (default 3).
	 * - `title` — custom section title (overrides the default translatable “Sleeping arrangements” when non-empty).
	 * - `show_title` — `0` to hide the section title; default is shown.
	 *
	 * @param array<string, mixed>    $syncPayload
	 * @param array<string, string>   $atts
	 * @param array{provider: string, locale: string} $context
	 */
	public static function renderBedroomArrangements(array $syncPayload, int $postId, array $atts, array $context): string
	{
		$locale = self::contextLocale($context);
		$raw    = $syncPayload['raw'] ?? null;
		if (! \is_array($raw)) {
			return '';
		}
		$rows = $raw['bedroom_details'] ?? null;
		if (! \is_array($rows) || $rows === []) {
			return '';
		}

		$bedIconMap = self::getFilteredBedroomBedIconMap($syncPayload, $postId, $context);
		$rooms = [];
		$roomIndex = 0;
		foreach ($rows as $one) {
			if (! \is_array($one)) {
				continue;
			}
			$roomIndex++;
			$type = isset($one['type']) ? \strtoupper((string) $one['type']) : 'BEDROOM';
			$bedsObj = $one['beds'] ?? null;
			if (! \is_array($bedsObj) || $bedsObj === []) {
				continue;
			}
			$bedLines = self::buildBedroomBedLines(
				$bedsObj,
				$bedIconMap,
				$raw,
				$locale,
				$syncPayload,
				$postId,
				$context
			);
			if ($bedLines === '') {
				continue;
			}
			$rooms[] = [
				'index'  => $roomIndex,
				'type'   => $type,
				'html'   => $bedLines,
			];
		}

		if ($rooms === []) {
			return '';
		}

		$columns  = \max(1, \min(6, self::intAttr($atts, 'columns', 3)));
		$rootStyle = 'style="' . \esc_attr('--bec-bedrooms-cols: ' . $columns . ';') . '"';

		$titleBlock = '';
		if (self::boolAttr($atts, 'show_title', true)) {
			$titleText = self::bedroomSectionTitle($atts);
			if ($titleText !== '') {
				$titleBlock = '<h3 class="bec-bedrooms__title">' . $titleText . '</h3>';
			}
		}

		$items = [];
		foreach ($rooms as $r) {
			$roomHeading = self::formatBedroomRoomHeading(
				(int) $r['index'],
				(string) $r['type']
			);
			$items[] = '<li class="bec-bedrooms__room">'
				. '<h4 class="bec-bedrooms__room-name">' . $roomHeading . '</h4>'
				. $r['html']
				. '</li>';
		}

		AmenitiesAssets::enqueueForKrossBedroomArrangements($postId, $atts);

		return '<section class="bec-bedrooms bec-bedrooms--kross" ' . $rootStyle
			. ' data-bec-bedrooms="1">'
			. $titleBlock
			. '<ul class="bec-bedrooms__grid" role="list">'
			. \implode('', $items)
			. '</ul>'
			. '</section>';
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
			return AmenityItem::repairLabelString((string) $labels[ $locale ]);
		}
		if (isset($labels['en']) && (string) $labels['en'] !== '') {
			return AmenityItem::repairLabelString((string) $labels['en']);
		}
		foreach ($labels as $text) {
			$t = AmenityItem::repairLabelString((string) $text);
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

	/**
	 * @param array{provider: string, locale: string} $context
	 * @return array<string, string> bed_key => icon_key (for `icon-*` in amenities font)
	 */
	private static function getFilteredBedroomBedIconMap(
		array $syncPayload,
		int $postId,
		array $context
	): array {
		$map = self::defaultBedroomBedIconMap();
		$map = (array) \apply_filters('bec_kross_bedroom_bed_map', $map, $syncPayload, $postId, $context);
		$out = [];
		foreach ($map as $k => $v) {
			$kk = \sanitize_key((string) $k);
			$iv = \sanitize_key((string) $v);
			if ($kk !== '' && $iv !== '') {
				$out[ $kk ] = $iv;
			}
		}

		return $out;
	}

	/**
	 * Kross `beds` object keys to amenities-font icon suffixes. Unknown keys at render
	 * time fall back to `queen_bed`.
	 *
	 * @return array<string, string>
	 */
	private static function defaultBedroomBedIconMap(): array
	{
		return [
			'double_bed'  => 'queen_bed',
			'queen_bed'   => 'queen_bed',
			'king_bed'    => 'king_bed',
			'single_bed'  => 'single_bed',
			'sofa_bed'    => 'sofa_bed',
			'bunk_bed'    => 'bunk_bed',
			'double_beds' => 'double_beds',
			'toddler_bed' => 'toddler_bed',
			'futon'       => 'futon',
			'full_bed'    => 'full_bed',
			'water_bed'   => 'water_bed',
			'murphy_bed'  => 'murphy_bed',
			'wall_bed'    => 'wall_bed',
		];
	}

	/**
	 * @param array<string, mixed> $bedsObject
	 * @param array<string, string> $bedIconMap
	 * @param array<string, mixed> $raw
	 * @param array{provider: string, locale: string} $context
	 */
	private static function buildBedroomBedLines(
		array $bedsObject,
		array $bedIconMap,
		array $raw,
		string $locale,
		array $syncPayload,
		int $postId,
		array $context
	): string {
		$pairs = [];
		foreach ($bedsObject as $k => $n) {
			$bedKey = \sanitize_key((string) $k);
			if ($bedKey === '') {
				continue;
			}
			$count = (int) $n;
			if ($count < 1) {
				continue;
			}
			$pairs[ $bedKey ] = $count;
		}
		if ($pairs === []) {
			return '';
		}
		\ksort($pairs, \SORT_NATURAL);

		$li = [];
		foreach ($pairs as $bedKey => $count) {
			$iconKey = $bedIconMap[ $bedKey ] ?? 'queen_bed';
			$label   = self::resolveBedroomBedLabel(
				$bedKey,
				$iconKey,
				$raw,
				$locale,
				$syncPayload,
				$postId,
				$context
			);
			$line   = \sprintf(
				/* translators: 1: number of beds, 2: bed type label */
				\__('%1$s × %2$s', 'booking-engine-connector'),
				(string) $count,
				$label
			);
			$li[]  = '<li class="bec-bedrooms__bed">'
				. '<i class="bec-bedrooms__bed-icon icon-' . \esc_attr($iconKey) . '" aria-hidden="true"></i>'
				. '<span class="bec-bedrooms__bed-text">' . \esc_html($line) . '</span>'
				. '</li>';
		}
		if ($li === []) {
			return '';
		}

		return '<ul class="bec-bedrooms__beds" role="list">' . \implode('', $li) . '</ul>';
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array{provider: string, locale: string} $context
	 */
	private static function resolveBedroomBedLabel(
		string $bedKey,
		string $iconKey,
		array $raw,
		string $locale,
		array $syncPayload,
		int $postId,
		array $context
	): string {
		$fromAmenities = self::labelFromRawAmenities($raw, $bedKey, $locale);
		$label         = $fromAmenities;
		if ($label === '') {
			$label = self::gettextLabelForBedKey($bedKey, $iconKey);
		}
		if ($label === '') {
			$label = self::humanizeBedKey($bedKey);
		}

		$label = (string) \apply_filters(
			'bec_kross_bedroom_label',
			$label,
			$bedKey,
			$iconKey,
			$postId,
			$syncPayload,
			$context
		);

		return $label;
	}

	/**
	 * Try to read `name_amenity_translations` from `raw.amenities` (same as sync) for
	 * a matching `cod_amenity` (and a few Kross-style aliases for double beds).
	 *
	 * @param array<string, mixed> $raw
	 */
	private static function labelFromRawAmenities(array $raw, string $bedKey, string $locale): string
	{
		$amen = $raw['amenities'] ?? null;
		if (! \is_array($amen) || $amen === []) {
			return '';
		}
		$cands = \array_values(\array_unique(self::bedKeyToAmenityCodeCandidates($bedKey)));
		foreach ($cands as $code) {
			$w = $code;
			foreach ($amen as $a) {
				if (! \is_array($a)) {
					continue;
				}
				$cod = isset($a['cod_amenity']) ? \sanitize_key((string) $a['cod_amenity']) : '';
				if ($cod === '' || $cod !== $w) {
					continue;
				}
				$labs = [];
				$tr   = $a['name_amenity_translations'] ?? null;
				if (\is_array($tr)) {
					foreach ($tr as $loc => $text) {
						$l = \sanitize_key((string) $loc);
						if ($l === '') {
							continue;
						}
						$labs[ $l ] = \sanitize_text_field((string) $text);
					}
				}
				if ($labs === [] && isset($a['name_amenity'])) {
					$labs['en'] = \sanitize_text_field((string) $a['name_amenity']);
				}
				$t = self::pickLabel($labs, $locale);
				if ($t !== '') {
					return $t;
				}
			}
		}

		return '';
	}

	/**
	 * @return list<string> sanitized cod_amenity values to look up in `raw.amenities`
	 */
	private static function bedKeyToAmenityCodeCandidates(string $bedKey): array
	{
		$out   = [ $bedKey ];
		$alias = [
			'double_bed'  => [ 'double_beds', 'queen_bed' ],
			'double_beds' => [ 'double_bed', 'queen_bed' ],
			'queen_bed'   => [ 'double_bed', 'double_beds' ],
		];
		$k = $bedKey;
		if (isset($alias[ $k ])) {
			foreach ((array) $alias[ $k ] as $x) {
				$out[] = \sanitize_key((string) $x);
			}
		}

		return $out;
	}

	private static function gettextLabelForBedKey(string $bedKey, string $iconKey): string
	{
		switch ($bedKey) {
			case 'double_bed':
				return (string) \_x('Queen bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'queen_bed':
				return (string) \_x('Queen bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'king_bed':
				return (string) \_x('King bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'single_bed':
				return (string) \_x('Single bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'sofa_bed':
				return (string) \_x('Sofa bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'bunk_bed':
				return (string) \_x('Bunk bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'double_beds':
				return (string) \_x('Double beds', 'bed type in bedroom details', 'booking-engine-connector');
			case 'toddler_bed':
				return (string) \_x('Toddler bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'futon':
				return (string) \_x('Futon', 'bed type in bedroom details', 'booking-engine-connector');
			case 'full_bed':
				return (string) \_x('Full bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'water_bed':
				return (string) \_x('Water bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'murphy_bed':
				return (string) \_x('Murphy bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'wall_bed':
				return (string) \_x('Wall bed', 'bed type in bedroom details', 'booking-engine-connector');
			default:
				return self::gettextLabelForKnownIconOnly($iconKey);
		}
	}

	/**
	 * Non-recursive: map icon suffix (when the bed key is unknown) to a label.
	 */
	private static function gettextLabelForKnownIconOnly(string $iconKey): string
	{
		switch ($iconKey) {
			case 'queen_bed':
				return (string) \_x('Queen bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'king_bed':
				return (string) \_x('King bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'single_bed':
				return (string) \_x('Single bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'sofa_bed':
				return (string) \_x('Sofa bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'bunk_bed':
				return (string) \_x('Bunk bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'double_beds':
				return (string) \_x('Double beds', 'bed type in bedroom details', 'booking-engine-connector');
			case 'toddler_bed':
				return (string) \_x('Toddler bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'futon':
				return (string) \_x('Futon', 'bed type in bedroom details', 'booking-engine-connector');
			case 'full_bed':
				return (string) \_x('Full bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'water_bed':
				return (string) \_x('Water bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'murphy_bed':
				return (string) \_x('Murphy bed', 'bed type in bedroom details', 'booking-engine-connector');
			case 'wall_bed':
				return (string) \_x('Wall bed', 'bed type in bedroom details', 'booking-engine-connector');
			default:
				return '';
		}
	}

	private static function humanizeBedKey(string $bedKey): string
	{
		$s = \str_replace('_', ' ', $bedKey);
		$s = \trim($s);
		if ($s === '') {
			return $bedKey;
		}
		if (\function_exists('mb_convert_case')) {
			return (string) \mb_convert_case($s, \MB_CASE_TITLE, 'UTF-8');
		}

		return (string) \ucwords($s);
	}

	/**
	 * @param array<string, string> $atts
	 */
	private static function bedroomSectionTitle(array $atts): string
	{
		if (isset($atts['title']) && \trim((string) $atts['title']) !== '') {
			return \esc_html(\trim((string) $atts['title']));
		}

		return (string) \esc_html(
			\__('Sleeping arrangements', 'booking-engine-connector')
		);
	}

	private static function formatBedroomRoomHeading(int $index, string $type): string
	{
		$type = \strtoupper($type);
		if ($type === '' || $type === 'BEDROOM') {
			return (string) \esc_html(
				\sprintf(
					/* translators: %d: bedroom number (1-based) */
					\__('Bedroom %d', 'booking-engine-connector'),
					$index
				)
			);
		}

		return (string) \esc_html(
			\sprintf(
				/* translators: %d: room number (1-based) */
				\__('Room %d', 'booking-engine-connector'),
				$index
			)
		);
	}

	/**
	 * @param array<string, string> $atts
	 */
	private static function boolAttr(array $atts, string $name, bool $default): bool
	{
		if (! isset($atts[ $name ]) || (string) $atts[ $name ] === '') {
			return $default;
		}
		$v = \strtolower(\trim((string) $atts[ $name ]));
		if ($v === '0' || $v === 'no' || $v === 'false' || $v === 'off' || $v === 'n') {
			return false;
		}
		if ($v === '1' || $v === 'yes' || $v === 'true' || $v === 'on' || $v === 'y') {
			return true;
		}

		return $default;
	}
}
