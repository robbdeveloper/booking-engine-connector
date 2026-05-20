<?php

declare(strict_types=1);

namespace BookingEngineConnector\Taxonomies;

use BookingEngineConnector\Integrations\Multilingual;
use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Hidden amenity index taxonomy for filterable unit facets.
 */
final class UnitAmenityTaxonomy
{
	public const TAXONOMY = 'bec_unit_amenity';

	public const TERM_META_LABELS = 'bec_amenity_labels';

	public const TERM_META_CATEGORY = 'bec_amenity_category';

	public static function register(): void
	{
		\add_action('init', [self::class, 'onInit'], 6);
	}

	public static function getSlug(): string
	{
		return self::TAXONOMY;
	}

	public static function onInit(): void
	{
		$args = [
			'labels'            => self::labels(),
			'public'            => false,
			'publicly_queryable'=> false,
			'hierarchical'      => false,
			'show_ui'           => false,
			'show_admin_column' => false,
			'show_in_nav_menus' => false,
			'show_in_rest'      => false,
			'rewrite'           => false,
			'query_var'         => false,
		];

		/** @var array<string, mixed> $args */
		$args = \apply_filters('bec_unit_amenity_taxonomy_args', $args, self::TAXONOMY);

		\register_taxonomy(self::TAXONOMY, [UnitPostType::POST_TYPE], $args);

		self::registerTermMeta();
	}

	/**
	 * @return array<string, string>
	 */
	private static function labels(): array
	{
		return [
			'name'          => _x('Unit amenities', 'taxonomy general name', 'booking-engine-connector'),
			'singular_name' => _x('Unit amenity', 'taxonomy singular name', 'booking-engine-connector'),
		];
	}

	private static function registerTermMeta(): void
	{
		$auth = static function ($allowed, string $meta_key, $term): bool {
			unset($allowed, $meta_key);

			return $term instanceof \WP_Term && \current_user_can('manage_options');
		};

		\register_term_meta(
			self::TAXONOMY,
			self::TERM_META_LABELS,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => [self::class, 'sanitizeLabelsMeta'],
				'auth_callback'     => $auth,
				'default'           => '',
			]
		);

		\register_term_meta(
			self::TAXONOMY,
			self::TERM_META_CATEGORY,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => $auth,
				'default'           => '',
			]
		);
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitizeLabelsMeta($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		if (\is_array($value)) {
			$encoded = \wp_json_encode(UnitCategoryTaxonomy::normalizeNamesArray($value), \JSON_UNESCAPED_UNICODE);

			return $encoded !== false ? $encoded : '';
		}

		if (! \is_string($value)) {
			return '';
		}

		$value = \trim($value);
		if ($value === '') {
			return '';
		}

		$decoded = \json_decode($value, true);
		if (! \is_array($decoded)) {
			return '';
		}

		$encoded = \wp_json_encode(UnitCategoryTaxonomy::normalizeNamesArray($decoded), \JSON_UNESCAPED_UNICODE);

		return $encoded !== false ? $encoded : '';
	}

	/**
	 * Two-letter locale for label preference.
	 */
	public static function currentLabelLocaleCode(): string
	{
		$locale = Multilingual::filteredSiteLocale('unit_amenity');
		$locale = \str_replace('-', '_', $locale);
		$primary = \explode('_', $locale, 2)[0];
		$code = \strtolower(\substr($primary, 0, 2));

		return \preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : 'en';
	}

	/**
	 * @param array<string, string> $names
	 */
	public static function resolveLocalizedLabelFromNames(array $names, ?string $preferredLocale = null): string
	{
		return UnitCategoryTaxonomy::resolveLocalizedLabelFromNames($names, $preferredLocale);
	}

	public static function resolveLocalizedLabelForTerm(\WP_Term $term): string
	{
		$raw = (string) \get_term_meta($term->term_id, self::TERM_META_LABELS, true);
		if ($raw === '') {
			return $term->name;
		}

		$decoded = \json_decode($raw, true);
		if (! \is_array($decoded)) {
			return $term->name;
		}

		/** @var array<mixed, mixed> $decoded */
		$map = UnitCategoryTaxonomy::normalizeNamesArray($decoded);
		$label = self::resolveLocalizedLabelFromNames($map);

		return $label !== '' ? $label : $term->name;
	}
}
