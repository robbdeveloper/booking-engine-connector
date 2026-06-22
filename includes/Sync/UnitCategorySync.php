<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Upserts synced provider categories as terms (Phase 1) and assigns them to units (Phase 2).
 *
 * Each language term is keyed by bec_provider_slug + bec_external_id + bec_term_lang.
 * Unit sync never creates categories — only assigns existing terms.
 */
final class UnitCategorySync
{
	public const META_TERM_LANG = 'bec_term_lang';

	public static function register(): void
	{
		add_action('bec_after_unit_sync', [self::class, 'onAfterUnitSync'], 10, 3);
	}

	/**
	 * Phase 1: upsert one term per active language for each provider category descriptor.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	public static function syncUniqueDescriptorsFromRows(string $providerSlug, array $rows): void
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		/** @var array<string, array<string, mixed>> $unique */
		$unique = [];

		foreach ($rows as $row) {
			if (! is_array($row)) {
				continue;
			}

			$descriptor = $row['unit_category'] ?? null;
			if (! is_array($descriptor)) {
				continue;
			}

			/** @var array<string, mixed> $descriptor */
			$externalId = (string) ( $descriptor['external_id'] ?? '' );
			if ($externalId === '') {
				continue;
			}

			$unique[ $externalId ] = $descriptor;
		}

		/** @var array<string, array<string, mixed>> $unique */
		$unique = apply_filters('bec_sync_provider_category_descriptors', $unique, $providerSlug, $rows);

		foreach ($unique as $descriptor) {
			if (! is_array($descriptor)) {
				continue;
			}

			/** @var array<string, mixed> $descriptor */
			self::syncCategory($providerSlug, $descriptor);
		}
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	public static function syncCategory(string $providerSlug, array $descriptor): ?int
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return null;
		}

		/** @var array<string, mixed> $descriptor */
		$descriptor = apply_filters('bec_sync_unit_category', $descriptor, [], $providerSlug, 0);

		$externalId = (string) ( $descriptor['external_id'] ?? '' );
		if ($externalId === '') {
			return null;
		}

		$multilingual = MultilingualBridge::isFeatureEnabled();
		$defaultLang  = $multilingual ? MultilingualBridge::getDefaultLanguage() : '';
		$activeLangs  = $multilingual ? MultilingualBridge::getActiveLanguages() : [];

		if ($activeLangs === []) {
			$activeLangs = [ $defaultLang !== '' ? $defaultLang : '' ];
		}

		/** @var array<string, string> $strings */
		$strings = apply_filters('bec_category_translation_strings', [], $descriptor, $providerSlug, 0);
		if (! is_array($strings)) {
			$strings = [];
		}

		$canonicalTermId = null;
		/** @var array<string, int> $translationMap */
		$translationMap = [];

		foreach ($activeLangs as $lang) {
			$lang = (string) $lang;
			$name = self::resolveNameForLanguage($descriptor, $strings, $lang, $defaultLang);

			if ($name === '' && $lang !== $defaultLang) {
				$existingId = self::findTermId($providerSlug, $externalId, $lang);
				if ($existingId !== null) {
					$translationMap[ $lang ] = $existingId;
				}
				continue;
			}

			if ($name === '') {
				$name = self::fallbackDisplayName($descriptor, $externalId);
			}

			$termId = self::findTermId($providerSlug, $externalId, $lang);

			if ($termId === null) {
				$termId = self::createTerm($name, $descriptor, $providerSlug, $externalId, $lang);
			} else {
				self::updateTerm($termId, $name, $descriptor, $providerSlug, $externalId, $lang);
			}

			if ($termId === null) {
				continue;
			}

			if ($lang === $defaultLang || ( $defaultLang === '' && $canonicalTermId === null )) {
				$canonicalTermId = $termId;
			}

			$translationMap[ $lang ] = $termId;

			if ($multilingual && $lang !== '') {
				MultilingualBridge::setTermLanguage($termId, $lang);
			}
		}

		if ($canonicalTermId !== null && $multilingual && $defaultLang !== '') {
			foreach ($translationMap as $lang => $termId) {
				if ($lang === $defaultLang || $termId === $canonicalTermId) {
					continue;
				}

				MultilingualBridge::linkTermTranslation($canonicalTermId, $defaultLang, $termId, $lang);
			}

			update_term_meta($canonicalTermId, MultilingualBridge::META_TRANSLATION_TERM_IDS, $translationMap);
		}

		if ($canonicalTermId !== null) {
			do_action('bec_after_category_sync', $canonicalTermId, $providerSlug, $descriptor);
		}

		return $canonicalTermId;
	}

	public static function findTermId(string $providerSlug, string $externalId, string $lang): ?int
	{
		if ($externalId === '' || $providerSlug === '') {
			return null;
		}

		global $wpdb;

		$taxonomy = UnitCategoryTaxonomy::getSlug();

		if ($lang !== '') {
			$sql = $wpdb->prepare(
				"SELECT tt.term_id
				   FROM {$wpdb->term_taxonomy} tt
				   INNER JOIN {$wpdb->termmeta} mp
				     ON mp.term_id = tt.term_id
				    AND mp.meta_key = 'bec_provider_slug'
				    AND mp.meta_value = %s
				   INNER JOIN {$wpdb->termmeta} me
				     ON me.term_id = tt.term_id
				    AND me.meta_key = 'bec_external_id'
				    AND me.meta_value = %s
				   INNER JOIN {$wpdb->termmeta} ml
				     ON ml.term_id = tt.term_id
				    AND ml.meta_key = %s
				    AND ml.meta_value = %s
				  WHERE tt.taxonomy = %s
				  LIMIT 1",
				$providerSlug,
				$externalId,
				self::META_TERM_LANG,
				$lang,
				$taxonomy
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT tt.term_id
				   FROM {$wpdb->term_taxonomy} tt
				   INNER JOIN {$wpdb->termmeta} mp
				     ON mp.term_id = tt.term_id
				    AND mp.meta_key = 'bec_provider_slug'
				    AND mp.meta_value = %s
				   INNER JOIN {$wpdb->termmeta} me
				     ON me.term_id = tt.term_id
				    AND me.meta_key = 'bec_external_id'
				    AND me.meta_value = %s
				    LEFT JOIN {$wpdb->termmeta} ml
				     ON ml.term_id = tt.term_id
				    AND ml.meta_key = %s
				  WHERE tt.taxonomy = %s
				    AND ( ml.meta_value IS NULL OR ml.meta_value = '' )
				  LIMIT 1",
				$providerSlug,
				$externalId,
				self::META_TERM_LANG,
				$taxonomy
			);
		}

		$termId = (int) $wpdb->get_var($sql);

		return $termId > 0 ? $termId : null;
	}

	/**
	 * Phase 2: assign an existing category term to a unit. Never creates categories.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function onAfterUnitSync(int $postId, string $providerSlug, array $row): void
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		$descriptor = $row['unit_category'] ?? null;

		/** @var mixed $descriptor */
		$descriptor = apply_filters('bec_sync_unit_category', $descriptor, $row, $providerSlug, $postId);

		if (! is_array($descriptor)) {
			return;
		}

		/** @var array<string, mixed> $descriptor */
		$externalId = (string) ( $descriptor['external_id'] ?? '' );
		if ($externalId === '') {
			return;
		}

		$lang = MultilingualBridge::getPostLanguage($postId);
		if ($lang === '' && MultilingualBridge::isFeatureEnabled()) {
			$lang = MultilingualBridge::getDefaultLanguage();
		}

		$termId = self::findTermId($providerSlug, $externalId, $lang);
		if ($termId === null) {
			return;
		}

		wp_set_object_terms($postId, [ $termId ], UnitCategoryTaxonomy::getSlug(), false);
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function createTerm(
		string $displayName,
		array $descriptor,
		string $providerSlug,
		string $externalId,
		string $lang
	): ?int {
		$slug = sanitize_title($displayName);
		if ($slug === '') {
			$slug = 'category';
		}

		$result = wp_insert_term(
			$displayName,
			UnitCategoryTaxonomy::getSlug(),
			[
				'slug' => $slug,
			]
		);

		if (is_wp_error($result)) {
			return null;
		}

		$termId = (int) ( $result['term_id'] ?? 0 );
		if ($termId <= 0) {
			return null;
		}

		self::persistTermMeta($termId, $descriptor, $providerSlug, $externalId, $lang);

		return $termId;
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function updateTerm(
		int $termId,
		string $displayName,
		array $descriptor,
		string $providerSlug,
		string $externalId,
		string $lang
	): void {
		if ($displayName !== '') {
			wp_update_term(
				$termId,
				UnitCategoryTaxonomy::getSlug(),
				[
					'name' => $displayName,
				]
			);
		}

		self::persistTermMeta($termId, $descriptor, $providerSlug, $externalId, $lang);
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function persistTermMeta(
		int $termId,
		array $descriptor,
		string $providerSlug,
		string $externalId,
		string $lang
	): void {
		$namesMap = [];
		if (isset($descriptor['names']) && is_array($descriptor['names'])) {
			/** @var array<mixed, mixed> $names */
			$names    = $descriptor['names'];
			$namesMap = UnitCategoryTaxonomy::coerceDescriptorNamesToMap($names);
		}

		update_term_meta($termId, 'bec_provider_slug', $providerSlug);
		update_term_meta($termId, 'bec_external_id', $externalId);
		update_term_meta($termId, self::META_TERM_LANG, $lang);

		$encodedNames = wp_json_encode($namesMap, JSON_UNESCAPED_UNICODE);
		update_term_meta($termId, 'bec_category_names', $encodedNames !== false ? $encodedNames : '');

		$descriptorJson = wp_json_encode($descriptor, JSON_UNESCAPED_UNICODE);
		update_term_meta($termId, 'bec_category_normalized', $descriptorJson !== false ? $descriptorJson : '');

		update_term_meta($termId, 'bec_last_sync_at', current_time('mysql'));
	}

	/**
	 * @param array<string, mixed>  $descriptor
	 * @param array<string, string> $strings
	 */
	private static function resolveNameForLanguage(
		array $descriptor,
		array $strings,
		string $lang,
		string $defaultLang
	): string {
		if ($lang !== '' && isset($strings[ $lang ]) && trim($strings[ $lang ]) !== '') {
			return trim($strings[ $lang ]);
		}

		if ($lang === $defaultLang || $defaultLang === '') {
			return UnitCategoryTaxonomy::resolveDefaultDisplayName($descriptor);
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function fallbackDisplayName(array $descriptor, string $externalId): string
	{
		$name = UnitCategoryTaxonomy::resolveDefaultDisplayName($descriptor);
		if ($name !== '') {
			return $name;
		}

		return sprintf(
			/* translators: %s provider category external id */
			__('Category %s', 'booking-engine-connector'),
			$externalId
		);
	}
}
