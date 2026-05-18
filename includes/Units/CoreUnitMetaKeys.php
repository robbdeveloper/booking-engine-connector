<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units;

/**
 * Maps {@see CoreUnitSemantic} values to post meta keys and editor types.
 */
final class CoreUnitMetaKeys
{
	/**
	 * @return array<string, array{meta_key: string, label: string, type: string}>
	 */
	public static function definitions(): array
	{
		return [
			CoreUnitSemantic::NAME => [
				'meta_key' => 'bec_core_name',
				'label'    => \__('Unit name', 'booking-engine-connector'),
				'type'     => 'string',
			],
			CoreUnitSemantic::ADDRESS_FULL => [
				'meta_key' => 'bec_core_address_full',
				'label'    => \__('Full address', 'booking-engine-connector'),
				'type'     => 'textarea',
			],
			CoreUnitSemantic::LAT => [
				'meta_key' => 'bec_core_lat',
				'label'    => \__('Latitude', 'booking-engine-connector'),
				'type'     => 'string',
			],
			CoreUnitSemantic::LNG => [
				'meta_key' => 'bec_core_lng',
				'label'    => \__('Longitude', 'booking-engine-connector'),
				'type'     => 'string',
			],
			CoreUnitSemantic::OCC_MIN => [
				'meta_key' => 'bec_core_occ_min',
				'label'    => \__('Min. guests', 'booking-engine-connector'),
				'type'     => 'number',
			],
			CoreUnitSemantic::OCC_MAX => [
				'meta_key' => 'bec_core_occ_max',
				'label'    => \__('Max. guests', 'booking-engine-connector'),
				'type'     => 'number',
			],
			CoreUnitSemantic::CHECK_IN_FROM => [
				'meta_key' => 'bec_core_check_in_from',
				'label'    => \__('Check-in from', 'booking-engine-connector'),
				'type'     => 'string',
			],
			CoreUnitSemantic::CHECK_IN_TO => [
				'meta_key' => 'bec_core_check_in_to',
				'label'    => \__('Check-in until', 'booking-engine-connector'),
				'type'     => 'string',
			],
			CoreUnitSemantic::CHECK_OUT_UNTIL => [
				'meta_key' => 'bec_core_check_out_until',
				'label'    => \__('Check-out by', 'booking-engine-connector'),
				'type'     => 'string',
			],
			CoreUnitSemantic::ROOMS => [
				'meta_key' => 'bec_core_rooms',
				'label'    => \__('Rooms (bedrooms)', 'booking-engine-connector'),
				'type'     => 'number',
			],
			CoreUnitSemantic::BATHROOMS => [
				'meta_key' => 'bec_core_bathrooms',
				'label'    => \__('Bathrooms', 'booking-engine-connector'),
				'type'     => 'bathrooms',
			],
			CoreUnitSemantic::DESCRIPTION => [
				'meta_key' => 'bec_core_description',
				'label'    => \__('Description', 'booking-engine-connector'),
				'type'     => 'textarea',
			],
			CoreUnitSemantic::SQM => [
				'meta_key' => 'bec_core_sqm',
				'label'    => \__('Size (m²)', 'booking-engine-connector'),
				'type'     => 'number',
			],
			CoreUnitSemantic::AMENITIES => [
				'meta_key' => 'bec_core_amenities',
				'label'    => \__('Amenities (JSON)', 'booking-engine-connector'),
				'type'     => 'amenities_json',
			],
			CoreUnitSemantic::GALLERY => [
				'meta_key' => 'bec_core_gallery',
				'label'    => \__('Gallery', 'booking-engine-connector'),
				'type'     => 'gallery_json',
			],
		];
	}

	public static function metaKeyForSemantic(string $semantic): ?string
	{
		$defs = self::definitions();

		return isset($defs[ $semantic ]['meta_key']) ? $defs[ $semantic ]['meta_key'] : null;
	}
}
