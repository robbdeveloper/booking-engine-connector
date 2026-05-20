<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Built-in unit filter definitions. Extend via {@see bec_unit_filter_definitions}.
 */
final class UnitFilterRegistry
{
	public const FILTER_ORDER = 'order';

	public const FILTER_ROOMS = 'rooms';

	public const FILTER_BATHROOMS = 'bathrooms';

	public const FILTER_AMENITIES = 'amenities';

	/**
	 * @return array<string, array{
	 *   id: string,
	 *   param: string,
	 *   label: string,
	 *   meta_key?: string,
	 *   taxonomy?: string
	 * }>
	 */
	public static function definitions(): array
	{
		$roomsMeta     = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::ROOMS) ?? 'bec_core_rooms';
		$bathroomsMeta = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::BATHROOMS) ?? 'bec_core_bathrooms';

		$defs = [
			self::FILTER_ORDER => [
				'id'    => self::FILTER_ORDER,
				'param' => UnitFilterRequest::PARAM_ORDER,
				'label' => \__('Order', 'booking-engine-connector'),
			],
			self::FILTER_ROOMS => [
				'id'       => self::FILTER_ROOMS,
				'param'    => UnitFilterRequest::PARAM_ROOMS_MIN,
				'label'    => \__('Rooms', 'booking-engine-connector'),
				'meta_key' => $roomsMeta,
			],
			self::FILTER_BATHROOMS => [
				'id'       => self::FILTER_BATHROOMS,
				'param'    => UnitFilterRequest::PARAM_BATHROOMS_MIN,
				'label'    => \__('Bathrooms', 'booking-engine-connector'),
				'meta_key' => $bathroomsMeta,
			],
			self::FILTER_AMENITIES => [
				'id'         => self::FILTER_AMENITIES,
				'param'      => UnitFilterRequest::PARAM_AMENITIES,
				'label'      => \__('Amenities', 'booking-engine-connector'),
				'taxonomy'   => \BookingEngineConnector\Taxonomies\UnitAmenityTaxonomy::TAXONOMY,
			],
		];

		/** @var array<string, array{id: string, param: string, label: string, meta_key?: string, taxonomy?: string}> $filtered */
		$filtered = (array) \apply_filters('bec_unit_filter_definitions', $defs);

		return $filtered;
	}

	/**
	 * @param list<string> $ids Filter ids from shortcode `filters` attribute.
	 * @return list<array{id: string, param: string, label: string, meta_key?: string, taxonomy?: string}>
	 */
	public static function resolveForShortcode(array $ids): array
	{
		$defs = self::definitions();
		$out  = [];

		foreach ($ids as $id) {
			$id = \sanitize_key($id);
			if ($id === '' || ! isset($defs[ $id ])) {
				continue;
			}
			$out[] = $defs[ $id ];
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	public static function defaultShortcodeFilterIds(): array
	{
		return [
			self::FILTER_ORDER,
			self::FILTER_ROOMS,
			self::FILTER_BATHROOMS,
			self::FILTER_AMENITIES,
		];
	}
}
