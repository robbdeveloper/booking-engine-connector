<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

/**
 * Admin-curated amenity options for the front-end filter facet.
 */
final class UnitFilterSettings
{
	public const OPTION_AMENITY_OPTIONS = 'bec_unit_filters_amenity_options';

	/**
	 * @return array{enabled: list<string>, order: list<string>, labels: array<string, string>}
	 */
	public static function getAmenityOptions(): array
	{
		$raw = \get_option(self::OPTION_AMENITY_OPTIONS, []);
		if (! \is_array($raw)) {
			return self::emptyAmenityOptions();
		}

		$enabled = [];
		if (isset($raw['enabled']) && \is_array($raw['enabled'])) {
			foreach ($raw['enabled'] as $key) {
				$key = \sanitize_key((string) $key);
				if ($key !== '') {
					$enabled[] = $key;
				}
			}
		}

		$order = [];
		if (isset($raw['order']) && \is_array($raw['order'])) {
			foreach ($raw['order'] as $key) {
				$key = \sanitize_key((string) $key);
				if ($key !== '') {
					$order[] = $key;
				}
			}
		}

		$labels = [];
		if (isset($raw['labels']) && \is_array($raw['labels'])) {
			foreach ($raw['labels'] as $key => $label) {
				$key = \sanitize_key((string) $key);
				$label = \sanitize_text_field((string) $label);
				if ($key !== '' && $label !== '') {
					$labels[ $key ] = $label;
				}
			}
		}

		return [
			'enabled' => \array_values(\array_unique($enabled)),
			'order'   => \array_values(\array_unique($order)),
			'labels'  => $labels,
		];
	}

	/**
	 * @param array{enabled?: list<string>, order?: list<string>, labels?: array<string, string>} $input
	 */
	public static function saveAmenityOptions(array $input): void
	{
		$current = self::getAmenityOptions();

		$enabled = $current['enabled'];
		if (isset($input['enabled']) && \is_array($input['enabled'])) {
			$enabled = [];
			foreach ($input['enabled'] as $key) {
				$key = \sanitize_key((string) $key);
				if ($key !== '') {
					$enabled[] = $key;
				}
			}
		}

		$order = $current['order'];
		if (isset($input['order']) && \is_array($input['order'])) {
			$order = [];
			foreach ($input['order'] as $key) {
				$key = \sanitize_key((string) $key);
				if ($key !== '') {
					$order[] = $key;
				}
			}
		}

		$labels = $current['labels'];
		if (isset($input['labels']) && \is_array($input['labels'])) {
			$labels = [];
			foreach ($input['labels'] as $key => $label) {
				$key = \sanitize_key((string) $key);
				$label = \sanitize_text_field((string) $label);
				if ($key !== '' && $label !== '') {
					$labels[ $key ] = $label;
				}
			}
		}

		\update_option(
			self::OPTION_AMENITY_OPTIONS,
			[
				'enabled' => \array_values(\array_unique($enabled)),
				'order'   => \array_values(\array_unique($order)),
				'labels'  => $labels,
			],
			false
		);
	}

	/**
	 * @return array{enabled: list<string>, order: list<string>, labels: array<string, string>}
	 */
	public static function emptyAmenityOptions(): array
	{
		return [
			'enabled' => [],
			'order'   => [],
			'labels'  => [],
		];
	}
}
