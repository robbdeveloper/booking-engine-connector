<?php

declare(strict_types=1);

namespace BookingEngineConnector\Taxonomies;

use BookingEngineConnector\Integrations\Multilingual;
use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Unit category taxonomy: stable internal key {@see TAXONOMY}, configurable public rewrite slug.
 */
final class UnitCategoryTaxonomy
{
	public const TAXONOMY = 'bec_unit_category';

	public const OPTION_ENABLED = 'bec_unit_category_enabled';

	public const OPTION_PERMALINK_SLUG = 'bec_unit_category_permalink_slug';

	public const DEFAULT_REWRITE_SLUG = 'unit-category';

	private static string $rewriteSlug = self::DEFAULT_REWRITE_SLUG;

	public static function register(): void
	{
		add_action('init', [self::class, 'onInit'], 6);
	}

	public static function getSlug(): string
	{
		return self::TAXONOMY;
	}

	public static function getPermalinkSlug(): string
	{
		return self::$rewriteSlug;
	}

	public static function isEnabled(): bool
	{
		$stored = get_option(self::OPTION_ENABLED, false);

		return (bool) apply_filters(
			'bec_unit_category_enabled',
			is_scalar($stored) ? (bool) $stored : false
		);
	}

	public static function resolvePermalinkSlug(): string
	{
		$stored = get_option(self::OPTION_PERMALINK_SLUG, '');
		if (! is_string($stored)) {
			$stored = '';
		}
		$stored = trim($stored);
		$candidate = $stored === '' ? self::DEFAULT_REWRITE_SLUG : sanitize_title($stored);
		if ($candidate === '') {
			$candidate = self::DEFAULT_REWRITE_SLUG;
		}
		$filtered = (string) apply_filters('bec_unit_category_rewrite_slug', $candidate);
		$filtered = sanitize_title($filtered);
		if ($filtered === '') {
			$filtered = self::DEFAULT_REWRITE_SLUG;
		}

		return $filtered;
	}

	public static function onInit(): void
	{
		self::$rewriteSlug = self::resolvePermalinkSlug();

		$enabled = self::isEnabled();

		$args = [
			'labels'            => self::labels(),
			'public'            => $enabled,
			'hierarchical'      => true,
			'show_ui'           => $enabled,
			'show_admin_column' => $enabled,
			'show_in_nav_menus' => $enabled,
			'show_in_rest'      => $enabled,
			'rewrite'           => $enabled ? ['slug' => self::$rewriteSlug] : false,
			'query_var'         => $enabled ? self::TAXONOMY : false,
		];

		/** @var array<string, mixed> $args */
		$args = apply_filters('bec_unit_category_taxonomy_args', $args, self::TAXONOMY);

		register_taxonomy(self::TAXONOMY, [UnitPostType::POST_TYPE], $args);

		self::registerTermMeta();

		self::registerArchiveTitleFilters();
	}

	/**
	 * @return array<string, string>
	 */
	private static function labels(): array
	{
		return [
			'name'                       => _x('Unit categories', 'taxonomy general name', 'booking-engine-connector'),
			'singular_name'              => _x('Unit category', 'taxonomy singular name', 'booking-engine-connector'),
			'search_items'               => __('Search unit categories', 'booking-engine-connector'),
			'popular_items'              => __('Popular unit categories', 'booking-engine-connector'),
			'all_items'                  => __('All unit categories', 'booking-engine-connector'),
			'parent_item'                => __('Parent unit category', 'booking-engine-connector'),
			'parent_item_colon'          => __('Parent unit category:', 'booking-engine-connector'),
			'edit_item'                  => __('Edit unit category', 'booking-engine-connector'),
			'update_item'                => __('Update unit category', 'booking-engine-connector'),
			'add_new_item'               => __('Add new unit category', 'booking-engine-connector'),
			'new_item_name'              => __('New unit category name', 'booking-engine-connector'),
			'separate_items_with_commas' => __('Separate unit categories with commas', 'booking-engine-connector'),
			'add_or_remove_items'        => __('Add or remove unit categories', 'booking-engine-connector'),
			'choose_from_most_used'      => __('Choose from the most used unit categories', 'booking-engine-connector'),
			'not_found'                  => __('No unit categories found.', 'booking-engine-connector'),
			'menu_name'                  => __('Unit categories', 'booking-engine-connector'),
			'back_to_items'              => __('← Back to unit categories', 'booking-engine-connector'),
		];
	}

	private static function registerArchiveTitleFilters(): void
	{
		add_filter('single_term_title', [self::class, 'filterSingleTermTitle'], 10, 1);
	}

	public static function filterSingleTermTitle(string $term_name, $term = null): string
	{
		if (! self::isEnabled()) {
			return $term_name;
		}

		if (! $term instanceof \WP_Term) {
			$queried = get_queried_object();
			if ($queried instanceof \WP_Term) {
				$term = $queried;
			}
		}

		if (! $term instanceof \WP_Term || $term->taxonomy !== self::TAXONOMY) {
			return $term_name;
		}

		$label = self::resolveLocalizedLabelForTerm($term);

		return $label !== '' ? $label : $term_name;
	}

	private static function registerTermMeta(): void
	{
		$auth = static function ($allowed, string $meta_key, $term): bool {
			if ($term instanceof \WP_Term) {
				return current_user_can('edit_term', (int) $term->term_id);
			}

			return false;
		};

		register_term_meta(
			self::TAXONOMY,
			'bec_external_id',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'default'           => '',
			]
		);

		register_term_meta(
			self::TAXONOMY,
			'bec_provider_slug',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'default'           => '',
			]
		);

		register_term_meta(
			self::TAXONOMY,
			'bec_category_names',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => [self::class, 'sanitizeCategoryNamesMeta'],
				'auth_callback'     => $auth,
				'default'           => '',
			]
		);

		register_term_meta(
			self::TAXONOMY,
			'bec_category_normalized',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => [self::class, 'sanitizeJsonMeta'],
				'auth_callback'     => $auth,
				'default'           => '',
			]
		);

		register_term_meta(
			self::TAXONOMY,
			'bec_last_sync_at',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'default'           => '',
			]
		);
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitizeCategoryNamesMeta($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		if (is_array($value)) {
			$encoded = wp_json_encode(self::normalizeNamesArray($value), JSON_UNESCAPED_UNICODE);

			return $encoded !== false ? $encoded : '';
		}

		if (! is_string($value)) {
			return '';
		}

		$value = trim($value);
		if ($value === '') {
			return '';
		}

		$decoded = json_decode($value, true);
		if (! is_array($decoded)) {
			return '';
		}

		$encoded = wp_json_encode(self::normalizeNamesArray($decoded), JSON_UNESCAPED_UNICODE);

		return $encoded !== false ? $encoded : '';
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitizeJsonMeta($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		if (is_array($value)) {
			$encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE);

			return $encoded !== false ? $encoded : '';
		}

		if (! is_string($value)) {
			return '';
		}

		return trim($value);
	}

	/**
	 * @param array<mixed, mixed> $names
	 * @return array<string, string>
	 */
	public static function normalizeNamesArray(array $names): array
	{
		$out = [];

		foreach ($names as $k => $v) {
			if (! is_string($k)) {
				continue;
			}
			$lang = strtolower(trim($k));
			if (preg_match('/^[a-z]{2}/', $lang, $m)) {
				$lang = substr($m[0], 0, 2);
			} else {
				continue;
			}

			if (! is_string($v)) {
				continue;
			}

			$t = trim($v);
			if ($t !== '') {
				$out[ $lang ] = $t;
			}
		}

		return $out;
	}

	/**
	 * Two-letter locale code for label preference (WP locale / multilingual filter).
	 */
	public static function currentLabelLocaleCode(): string
	{
		$locale = Multilingual::filteredSiteLocale('unit_category');
		$locale = str_replace('-', '_', $locale);
		$primary = explode('_', $locale, 2)[0];
		$code = strtolower(substr($primary, 0, 2));

		return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : 'en';
	}

	/**
	 * @param array<string, string> $names Map of two-letter lang => label
	 */
	public static function resolveLocalizedLabelFromNames(array $names, ?string $preferredLocale = null): string
	{
		if ($names === []) {
			return '';
		}

		$active = $preferredLocale ?? self::currentLabelLocaleCode();
		if (isset($names[ $active ]) && $names[ $active ] !== '') {
			return $names[ $active ];
		}

		foreach (['en', 'it', 'de', 'fr', 'es'] as $lang) {
			if (isset($names[ $lang ]) && $names[ $lang ] !== '') {
				return $names[ $lang ];
			}
		}

		foreach ($names as $label) {
			if (is_string($label) && trim($label) !== '') {
				return trim($label);
			}
		}

		return '';
	}

	public static function resolveLocalizedLabelForTerm(\WP_Term $term): string
	{
		$raw = (string) get_term_meta($term->term_id, 'bec_category_names', true);
		if ($raw === '') {
			return '';
		}

		$decoded = json_decode($raw, true);
		if (! is_array($decoded)) {
			return '';
		}

		/** @var array<mixed, mixed> $decoded */
		$map = self::normalizeNamesArray($decoded);

		return self::resolveLocalizedLabelFromNames($map);
	}

	/**
	 * Default display name for sync (site locale, then fallbacks).
	 *
	 * @param array<string, mixed> $descriptor Normalized {@see unit_category} row fragment
	 */
	public static function resolveDefaultDisplayName(array $descriptor): string
	{
		$namesRaw = $descriptor['names'] ?? [];
		$names    = [];
		if (is_array($namesRaw)) {
			/** @var array<mixed, mixed> $namesRaw */
			$names = self::coerceDescriptorNamesToMap($namesRaw);
		}

		$fromNames = self::resolveLocalizedLabelFromNames($names);
		if ($fromNames !== '') {
			return $fromNames;
		}

		return (string) ($descriptor['name'] ?? '');
	}

	/**
	 * @param array<mixed, mixed> $names
	 * @return array<string, string>
	 */
	public static function coerceDescriptorNamesToMap(array $names): array
	{
		$flat = [];

		if ($names !== [] && array_keys($names) === range(0, count($names) - 1)) {
			foreach ($names as $item) {
				if (! is_array($item)) {
					continue;
				}
				$lang = isset($item['lang']) ? strtolower(trim((string) $item['lang'])) : '';
				if ($lang === '' && isset($item['language'])) {
					$lang = strtolower(trim((string) $item['language']));
				}
				if (preg_match('/^[a-z]{2}/', $lang, $m)) {
					$lang = substr($m[0], 0, 2);
				} else {
					continue;
				}

				$text = '';
				if (isset($item['name']) && is_string($item['name'])) {
					$text = trim($item['name']);
				} elseif (isset($item['text']) && is_string($item['text'])) {
					$text = trim($item['text']);
				} elseif (isset($item['value']) && is_string($item['value'])) {
					$text = trim($item['value']);
				}

				if ($text !== '') {
					$flat[ $lang ] = $text;
				}
			}

			return self::normalizeNamesArray($flat);
		}

		$stringKeyed = [];
		foreach ($names as $k => $v) {
			if (! is_string($k) || ! is_string($v)) {
				continue;
			}
			$stringKeyed[ strtolower(trim($k)) ] = trim($v);
		}

		return self::normalizeNamesArray($stringKeyed);
	}
}
