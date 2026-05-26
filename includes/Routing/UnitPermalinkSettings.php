<?php

declare(strict_types=1);

namespace BookingEngineConnector\Routing;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Admin-selectable URL structures for unit singles and unit category term archives.
 */
final class UnitPermalinkSettings
{
	public const OPTION_UNIT_STRUCTURE = 'bec_unit_url_structure';

	public const OPTION_CATEGORY_STRUCTURE = 'bec_unit_category_url_structure';

	/** @var string `/{unitSlug}/{post}` */
	public const UNIT_BASE = 'base';

	/** @var string `/{unitSlug}/{term}/{post}` */
	public const UNIT_BASE_CATEGORY = 'base_category';

	/** @var string `/{term}/{post}` */
	public const UNIT_CATEGORY_ONLY = 'category_only';

	/** @var string `/{categorySlug}/{term}` */
	public const CAT_CATEGORY_BASE = 'category_base';

	/** @var string `/{unitSlug}/{term}` */
	public const CAT_UNIT_BASE = 'unit_base';

	/** @var string `/{term}` */
	public const CAT_BARE = 'bare';

	/**
	 * @return array<string, string>
	 */
	public static function unitStructureChoices(): array
	{
		return [
			self::UNIT_BASE           => __('/{unit slug}/{unit name}', 'booking-engine-connector'),
			self::UNIT_BASE_CATEGORY  => __('/{unit slug}/{category}/{unit name}', 'booking-engine-connector'),
			self::UNIT_CATEGORY_ONLY  => __('/{category}/{unit name}', 'booking-engine-connector'),
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function categoryStructureChoices(): array
	{
		return [
			self::CAT_CATEGORY_BASE => __('/{category slug}/{term name}', 'booking-engine-connector'),
			self::CAT_UNIT_BASE     => __('/{unit slug}/{term name}', 'booking-engine-connector'),
			self::CAT_BARE          => __('/{term name}', 'booking-engine-connector'),
		];
	}

	public static function resolveUnitStructure(): string
	{
		$stored = get_option(self::OPTION_UNIT_STRUCTURE, self::UNIT_BASE);
		if (! is_string($stored)) {
			$stored = self::UNIT_BASE;
		}

		$stored = sanitize_key($stored);
		$allowed = array_keys(self::unitStructureChoices());
		if (! in_array($stored, $allowed, true)) {
			$stored = self::UNIT_BASE;
		}

		$filtered = (string) apply_filters('bec_unit_url_structure', $stored);

		return in_array($filtered, $allowed, true) ? $filtered : self::UNIT_BASE;
	}

	public static function resolveCategoryStructure(): string
	{
		$stored = get_option(self::OPTION_CATEGORY_STRUCTURE, self::CAT_CATEGORY_BASE);
		if (! is_string($stored)) {
			$stored = self::CAT_CATEGORY_BASE;
		}

		$stored = sanitize_key($stored);
		$allowed = array_keys(self::categoryStructureChoices());
		if (! in_array($stored, $allowed, true)) {
			$stored = self::CAT_CATEGORY_BASE;
		}

		$filtered = (string) apply_filters('bec_unit_category_url_structure', $stored);

		return in_array($filtered, $allowed, true) ? $filtered : self::CAT_CATEGORY_BASE;
	}

	public static function unitStructureRequiresCategories(string $structure): bool
	{
		return in_array($structure, [self::UNIT_BASE_CATEGORY, self::UNIT_CATEGORY_ONLY], true);
	}

	public static function categoryStructureRequiresCategories(string $structure): bool
	{
		return in_array($structure, [self::CAT_UNIT_BASE, self::CAT_BARE], true);
	}

	public static function usesCustomCategoryRewrite(string $structure): bool
	{
		return $structure !== self::CAT_CATEGORY_BASE;
	}

	public static function usesCustomUnitRewrite(string $structure): bool
	{
		return $structure !== self::UNIT_BASE;
	}

	/**
	 * Whether the two selected structures can coexist without ambiguous two-segment URLs.
	 */
	public static function isCompatibleCombination(string $unitStructure, string $categoryStructure): bool
	{
		if ($unitStructure === self::UNIT_BASE && $categoryStructure === self::CAT_UNIT_BASE) {
			return false;
		}

		return true;
	}

	/**
	 * @return array<int, string>
	 */
	public static function validationErrors(
		string $unitStructure,
		string $categoryStructure,
		bool $categoriesEnabled
	): array {
		$errors = [];

		if (! self::isCompatibleCombination($unitStructure, $categoryStructure)) {
			$errors[] = __(
				'Category URLs under the unit slug cannot be used together with unit URLs in the “/{unit slug}/{unit name}” format, because both use two URL segments and cannot be distinguished.',
				'booking-engine-connector'
			);
		}

		if (! $categoriesEnabled) {
			if (self::unitStructureRequiresCategories($unitStructure)) {
				$errors[] = __(
					'The selected unit URL structure requires unit categories to be enabled.',
					'booking-engine-connector'
				);
			}

			if (self::categoryStructureRequiresCategories($categoryStructure)) {
				$errors[] = __(
					'The selected category URL structure requires unit categories to be enabled.',
					'booking-engine-connector'
				);
			}
		}

		if ($categoryStructure === self::CAT_BARE) {
			$conflicts = self::detectBareTermSlugConflicts();
			if ($conflicts !== []) {
				$errors[] = sprintf(
					/* translators: %s: comma-separated list of conflicting slugs */
					__(
						'Top-level category URLs may conflict with existing content slugs: %s. Resolve these conflicts or choose a different structure.',
						'booking-engine-connector'
					),
					implode(', ', $conflicts)
				);
			}
		}

		return $errors;
	}

	/**
	 * Known public slugs that would collide with bare category term URLs.
	 *
	 * @return array<int, string>
	 */
	public static function detectBareTermSlugConflicts(): array
	{
		$termSlugs = self::allCategoryTermSlugs();
		if ($termSlugs === []) {
			return [];
		}

		$conflicts = [];

		foreach ($termSlugs as $termSlug) {
			if (self::slugConflictsWithCoreContent($termSlug)) {
				$conflicts[] = $termSlug;
			}
		}

		return array_values(array_unique($conflicts));
	}

	/**
	 * @return array<int, string>
	 */
	private static function allCategoryTermSlugs(): array
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => UnitCategoryTaxonomy::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'slugs',
			]
		);

		if (! is_array($terms)) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $terms)));
	}

	public static function slugConflictsWithCoreContent(string $slug): bool
	{
		$slug = sanitize_title($slug);
		if ($slug === '') {
			return false;
		}

		if (get_page_by_path($slug, OBJECT, 'page') instanceof \WP_Post) {
			return true;
		}

		if (get_page_by_path($slug, OBJECT, 'post') instanceof \WP_Post) {
			return true;
		}

		if (get_page_by_path($slug, OBJECT, UnitPostType::POST_TYPE) instanceof \WP_Post) {
			return true;
		}

		$unitSlug = UnitPostType::getPermalinkSlug();
		if ($slug === $unitSlug) {
			return true;
		}

		$categorySlug = UnitCategoryTaxonomy::getPermalinkSlug();
		if ($slug === $categorySlug) {
			return true;
		}

		global $wp_rewrite;
		if ($wp_rewrite instanceof \WP_Rewrite) {
			foreach ((array) $wp_rewrite->endpoints as $endpoint) {
				if (isset($endpoint[1]) && sanitize_title((string) $endpoint[1]) === $slug) {
					return true;
				}
			}
		}

		return (bool) apply_filters('bec_unit_permalink_slug_conflicts_with_core', false, $slug);
	}

	/**
	 * Example URLs for the admin UI.
	 *
	 * @return array{unit:string,category:string}
	 */
	public static function exampleUrls(
		string $unitStructure,
		string $categoryStructure,
		string $unitSlug,
		string $categorySlug
	): array {
		$home = trailingslashit(home_url('/'));
		$unitName = 'sample-unit';
		$termName = 'sample-category';

		$unitUrl = match ($unitStructure) {
			self::UNIT_BASE_CATEGORY => $home . $unitSlug . '/' . $termName . '/' . $unitName . '/',
			self::UNIT_CATEGORY_ONLY => $home . $termName . '/' . $unitName . '/',
			default                  => $home . $unitSlug . '/' . $unitName . '/',
		};

		$categoryUrl = match ($categoryStructure) {
			self::CAT_UNIT_BASE     => $home . $unitSlug . '/' . $termName . '/',
			self::CAT_BARE          => $home . $termName . '/',
			default                 => $home . $categorySlug . '/' . $termName . '/',
		};

		return [
			'unit'     => $unitUrl,
			'category' => $categoryUrl,
		];
	}
}
