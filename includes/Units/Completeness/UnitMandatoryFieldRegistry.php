<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units\Completeness;

use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Provider-independent definitions for unit mandatory field checks.
 */
final class UnitMandatoryFieldRegistry
{
	public const FIELD_FEATURED_IMAGE = 'featured_image';

	public const FIELD_GALLERY = 'gallery';

	/**
	 * @return array<string, array{id: string, label: string, group: string, type: string, semantic?: string}>
	 */
	public static function definitions(): array
	{
		$defs = [];

		foreach (CoreUnitMetaKeys::definitions() as $semantic => $conf) {
			$type = (string) ( $conf['type'] ?? 'string' );
			if ($type === 'boolean' || $type === 'readonly') {
				continue;
			}

			$defs[ $semantic ] = [
				'id'       => $semantic,
				'label'    => (string) ( $conf['label'] ?? $semantic ),
				'group'    => self::groupForSemantic($semantic),
				'type'     => $type,
				'semantic' => $semantic,
			];
		}

		$defs[ self::FIELD_FEATURED_IMAGE ] = [
			'id'    => self::FIELD_FEATURED_IMAGE,
			'label' => \__('Featured image', 'booking-engine-connector'),
			'group' => 'media',
			'type'  => 'featured_image',
		];

		$defs[ self::FIELD_GALLERY ] = [
			'id'       => self::FIELD_GALLERY,
			'label'    => \__('Gallery', 'booking-engine-connector'),
			'group'    => 'media',
			'type'     => 'gallery_json',
			'semantic' => CoreUnitSemantic::GALLERY,
		];

		/**
		 * @param array<string, array{id: string, label: string, group: string, type: string, semantic?: string}> $defs
		 */
		return (array) \apply_filters('bec_unit_mandatory_field_definitions', $defs);
	}

	/**
	 * @return array<string, string>
	 */
	public static function groupLabels(): array
	{
		return [
			'content'  => \__('Content', 'booking-engine-connector'),
			'location' => \__('Location', 'booking-engine-connector'),
			'capacity' => \__('Capacity', 'booking-engine-connector'),
			'media'    => \__('Media', 'booking-engine-connector'),
			'other'    => \__('Other', 'booking-engine-connector'),
		];
	}

	public static function definitionFor(string $fieldId): ?array
	{
		$defs = self::definitions();

		return isset($defs[ $fieldId ]) ? $defs[ $fieldId ] : null;
	}

	public static function labelFor(string $fieldId): string
	{
		$def = self::definitionFor($fieldId);

		return $def !== null ? (string) $def['label'] : $fieldId;
	}

	private static function groupForSemantic(string $semantic): string
	{
		switch ($semantic) {
			case CoreUnitSemantic::DESCRIPTION:
			case CoreUnitSemantic::NAME:
				return 'content';
			case CoreUnitSemantic::ADDRESS_FULL:
			case CoreUnitSemantic::CITY:
			case CoreUnitSemantic::LAT:
			case CoreUnitSemantic::LNG:
				return 'location';
			case CoreUnitSemantic::OCC_MIN:
			case CoreUnitSemantic::OCC_MAX:
			case CoreUnitSemantic::ROOMS:
			case CoreUnitSemantic::BATHROOMS:
			case CoreUnitSemantic::SQM:
				return 'capacity';
			case CoreUnitSemantic::GALLERY:
				return 'media';
			case CoreUnitSemantic::CHECK_IN_FROM:
			case CoreUnitSemantic::CHECK_IN_TO:
			case CoreUnitSemantic::CHECK_OUT_UNTIL:
			case CoreUnitSemantic::AMENITIES:
			case CoreUnitSemantic::CIN:
			case CoreUnitSemantic::ONLY_REQUEST:
			case CoreUnitSemantic::STARTING_FROM:
			default:
				return 'other';
		}
	}
}
