<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units\Completeness;

use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Admin-configured mandatory unit fields.
 */
final class UnitMandatoryFieldSettings
{
	public const OPTION_KEY = 'bec_unit_mandatory_field_settings';

	public const SCOPE_CANONICAL = 'canonical';

	/**
	 * @return list<string>
	 */
	public static function defaultEnabledFields(): array
	{
		return [
			CoreUnitSemantic::DESCRIPTION,
			UnitMandatoryFieldRegistry::FIELD_FEATURED_IMAGE,
			UnitMandatoryFieldRegistry::FIELD_GALLERY,
			CoreUnitSemantic::ROOMS,
			CoreUnitSemantic::OCC_MAX,
			CoreUnitSemantic::SQM,
		];
	}

	public static function defaultGalleryMinCount(): int
	{
		return 3;
	}

	/**
	 * @return array{enabled_fields: list<string>, gallery_min_count: int, scope: string}
	 */
	public static function getSettings(): array
	{
		$raw = \get_option(self::OPTION_KEY, []);
		if (! \is_array($raw)) {
			return self::defaultSettings();
		}

		$enabled = [];
		if (isset($raw['enabled_fields']) && \is_array($raw['enabled_fields'])) {
			$validIds = \array_keys(UnitMandatoryFieldRegistry::definitions());
			foreach ($raw['enabled_fields'] as $fieldId) {
				$fieldId = \sanitize_key((string) $fieldId);
				if ($fieldId !== '' && \in_array($fieldId, $validIds, true)) {
					$enabled[] = $fieldId;
				}
			}
		} else {
			$enabled = self::defaultEnabledFields();
		}

		$galleryMin = isset($raw['gallery_min_count']) ? (int) $raw['gallery_min_count'] : self::defaultGalleryMinCount();
		if ($galleryMin < 1) {
			$galleryMin = 1;
		}

		$scope = isset($raw['scope']) ? (string) $raw['scope'] : self::SCOPE_CANONICAL;
		if ($scope !== self::SCOPE_CANONICAL) {
			$scope = self::SCOPE_CANONICAL;
		}

		return [
			'enabled_fields'    => \array_values(\array_unique($enabled)),
			'gallery_min_count' => $galleryMin,
			'scope'             => $scope,
		];
	}

	/**
	 * @return list<string>
	 */
	public static function getEnabledFields(): array
	{
		return self::getSettings()['enabled_fields'];
	}

	public static function getGalleryMinCount(): int
	{
		return self::getSettings()['gallery_min_count'];
	}

	public static function isFieldEnabled(string $fieldId): bool
	{
		return \in_array($fieldId, self::getEnabledFields(), true);
	}

	/**
	 * @param array{enabled_fields?: list<string>, gallery_min_count?: int, scope?: string} $input
	 */
	public static function save(array $input): void
	{
		$validIds = \array_keys(UnitMandatoryFieldRegistry::definitions());
		$enabled  = [];

		if (isset($input['enabled_fields']) && \is_array($input['enabled_fields'])) {
			foreach ($input['enabled_fields'] as $fieldId) {
				$fieldId = \sanitize_key((string) $fieldId);
				if ($fieldId !== '' && \in_array($fieldId, $validIds, true)) {
					$enabled[] = $fieldId;
				}
			}
		}

		$galleryMin = isset($input['gallery_min_count']) ? (int) $input['gallery_min_count'] : self::defaultGalleryMinCount();
		if ($galleryMin < 1) {
			$galleryMin = 1;
		}

		\update_option(
			self::OPTION_KEY,
			[
				'enabled_fields'    => \array_values(\array_unique($enabled)),
				'gallery_min_count' => $galleryMin,
				'scope'             => self::SCOPE_CANONICAL,
			],
			false
		);
	}

	/**
	 * @return array{enabled_fields: list<string>, gallery_min_count: int, scope: string}
	 */
	private static function defaultSettings(): array
	{
		return [
			'enabled_fields'    => self::defaultEnabledFields(),
			'gallery_min_count' => self::defaultGalleryMinCount(),
			'scope'             => self::SCOPE_CANONICAL,
		];
	}
}
