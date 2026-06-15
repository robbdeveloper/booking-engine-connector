<?php

declare(strict_types=1);

namespace BookingEngineConnector\Integrations;

use BookingEngineConnector\Sync\UnitCategorySync;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Auto-managed linked translation terms for synced unit categories.
 */
final class CategoryTranslationSync
{
	/** @var array<string, true> canonicalTermId|lang */
	private static array $syncedTranslationKeys = [];

	public static function register(): void
	{
		add_action('bec_after_category_sync', [self::class, 'onAfterCategorySync'], 10, 3);
		add_action('bec_before_category_registry_sync', [self::class, 'resetSyncState'], 10, 0);
	}

	public static function resetSyncState(): void
	{
		self::$syncedTranslationKeys = [];
	}

	/**
	 * Remove provider lookup meta from all managed translation category terms (heals legacy duplicates).
	 */
	public static function cleanupExistingTranslationProviderMeta(): void
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		$terms = get_terms(
			[
				'taxonomy'         => UnitCategoryTaxonomy::getSlug(),
				'hide_empty'       => false,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => [
					[
						'key'     => MultilingualBridge::META_TRANSLATION_OF_TERM,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		if (! is_array($terms) || is_wp_error($terms)) {
			return;
		}

		foreach ($terms as $termId) {
			$termId = (int) $termId;
			if ($termId > 0) {
				self::stripProviderLookupMeta($termId);
			}
		}
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	public static function onAfterCategorySync(int $canonicalTermId, string $providerSlug, array $descriptor): void
	{
		self::syncTranslationsForCanonicalTerm($canonicalTermId, $providerSlug, $descriptor);
	}

	/**
	 * Idempotent: ensure linked translation terms exist for a canonical category term.
	 *
	 * @param array<string, mixed> $descriptor
	 */
	public static function syncTranslationsForCanonicalTerm(int $canonicalTermId, string $providerSlug, array $descriptor): void
	{
		if (! MultilingualBridge::isFeatureEnabled()) {
			return;
		}

		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		if ($canonicalTermId < 1 || UnitCategorySync::isTranslationTerm($canonicalTermId)) {
			return;
		}

		$externalId = (string) ($descriptor['external_id'] ?? '');
		if ($externalId === '') {
			return;
		}

		$defaultLang = MultilingualBridge::getDefaultLanguage();
		if ($defaultLang === '') {
			return;
		}

		$currentLang = MultilingualBridge::getTermLanguage($canonicalTermId);
		if ($currentLang === '' || $currentLang !== $defaultLang) {
			MultilingualBridge::setTermLanguage($canonicalTermId, $defaultLang);
		}

		/** @var array<string, string> $strings */
		$strings = apply_filters('bec_category_translation_strings', [], $descriptor, $providerSlug, $canonicalTermId);
		if (! is_array($strings)) {
			$strings = [];
		}

		$translationMap = [];
		foreach (MultilingualBridge::getActiveLanguages() as $lang) {
			if ($lang === $defaultLang) {
				$translationMap[ $lang ] = $canonicalTermId;
				continue;
			}

			$name = isset($strings[ $lang ]) ? trim((string) $strings[ $lang ]) : '';
			if ($name === '') {
				$existingId = MultilingualBridge::resolveTranslatedCategoryTermId($canonicalTermId, $lang);
				if ($existingId !== null) {
					$translationMap[ $lang ] = $existingId;
					self::stripProviderLookupMeta($existingId);
				}
				continue;
			}

			$translationId = self::upsertTranslationTerm(
				$canonicalTermId,
				$defaultLang,
				$lang,
				$name,
				$descriptor,
				$externalId
			);
			if ($translationId > 0) {
				$translationMap[ $lang ] = $translationId;
			}
		}

		if ($translationMap !== []) {
			update_term_meta($canonicalTermId, MultilingualBridge::META_TRANSLATION_TERM_IDS, $translationMap);
		}
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function upsertTranslationTerm(
		int $canonicalTermId,
		string $defaultLang,
		string $lang,
		string $name,
		array $descriptor,
		string $externalId
	): int {
		$dedupeKey = $canonicalTermId . '|' . $lang;
		if (isset(self::$syncedTranslationKeys[ $dedupeKey ])) {
			$existingId = self::findExistingTranslationTermId($canonicalTermId, $lang, $name, $descriptor, $externalId);
			if ($existingId !== null && $existingId > 0) {
				return $existingId;
			}
		} else {
			$existingId = self::findExistingTranslationTermId($canonicalTermId, $lang, $name, $descriptor, $externalId);
		}

		$slug     = UnitCategorySync::buildSlugForDescriptor($descriptor, $externalId, $name);
		$taxonomy = UnitCategoryTaxonomy::getSlug();

		if ($existingId !== null && $existingId > 0) {
			$result = wp_update_term(
				$existingId,
				$taxonomy,
				[
					'name' => $name,
					'slug' => $slug,
				]
			);
			if (is_wp_error($result)) {
				return 0;
			}
			$translationId = $existingId;
		} else {
			$result = wp_insert_term(
				$name,
				$taxonomy,
				[
					'slug' => $slug,
				]
			);
			if (is_wp_error($result)) {
				$adoptedId = self::findAdoptableTranslationTermBySlug($slug, $lang, $canonicalTermId, $externalId);
				if ($adoptedId === null) {
					return 0;
				}
				$translationId = $adoptedId;
				wp_update_term(
					$translationId,
					$taxonomy,
					[
						'name' => $name,
						'slug' => $slug,
					]
				);
			} else {
				$translationId = (int) ($result['term_id'] ?? 0);
				if ($translationId <= 0) {
					return 0;
				}
			}
		}

		update_term_meta($translationId, MultilingualBridge::META_TRANSLATION_OF_TERM, $canonicalTermId);
		update_term_meta($translationId, MultilingualBridge::META_TRANSLATION_TERM_LANG, $lang);

		self::copySharedTermMeta($canonicalTermId, $translationId);
		self::stripProviderLookupMeta($translationId);

		MultilingualBridge::setTermLanguage($translationId, $lang);
		MultilingualBridge::linkTermTranslation($canonicalTermId, $defaultLang, $translationId, $lang);

		self::$syncedTranslationKeys[ $dedupeKey ] = true;

		return $translationId;
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function findExistingTranslationTermId(
		int $canonicalTermId,
		string $lang,
		string $name,
		array $descriptor,
		string $externalId
	): ?int {
		$existingId = MultilingualBridge::resolveTranslatedCategoryTermId($canonicalTermId, $lang);
		if ($existingId !== null && $existingId > 0 && $existingId !== $canonicalTermId) {
			return $existingId;
		}

		$storedMap = get_term_meta($canonicalTermId, MultilingualBridge::META_TRANSLATION_TERM_IDS, true);
		if (is_array($storedMap) && isset($storedMap[ $lang ])) {
			$candidate = (int) $storedMap[ $lang ];
			if ($candidate > 0 && $candidate !== $canonicalTermId) {
				return $candidate;
			}
		}

		$terms = get_terms(
			[
				'taxonomy'         => UnitCategoryTaxonomy::getSlug(),
				'hide_empty'       => false,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => [
					'relation' => 'AND',
					[
						'key'   => MultilingualBridge::META_TRANSLATION_OF_TERM,
						'value' => $canonicalTermId,
					],
					[
						'key'   => MultilingualBridge::META_TRANSLATION_TERM_LANG,
						'value' => $lang,
					],
				],
			]
		);

		if (is_array($terms) && ! is_wp_error($terms)) {
			foreach ($terms as $termId) {
				$termId = (int) $termId;
				if ($termId > 0 && $termId !== $canonicalTermId) {
					return $termId;
				}
			}
		}

		$slug = UnitCategorySync::buildSlugForDescriptor($descriptor, $externalId, $name);

		return self::findAdoptableTranslationTermBySlug($slug, $lang, $canonicalTermId, $externalId);
	}

	private static function findAdoptableTranslationTermBySlug(
		string $slug,
		string $lang,
		int $canonicalTermId,
		string $externalId
	): ?int {
		$term = get_term_by('slug', $slug, UnitCategoryTaxonomy::getSlug());
		if (! $term instanceof \WP_Term) {
			return null;
		}

		$termId = (int) $term->term_id;
		if ($termId <= 0 || $termId === $canonicalTermId) {
			return null;
		}

		$translationOf = (int) get_term_meta($termId, MultilingualBridge::META_TRANSLATION_OF_TERM, true);
		if ($translationOf > 0 && $translationOf !== $canonicalTermId) {
			return null;
		}

		$existingExternalId = (string) get_term_meta($termId, 'bec_external_id', true);
		if ($existingExternalId !== '' && $existingExternalId !== $externalId) {
			return null;
		}

		if (MultilingualBridge::isFeatureEnabled()) {
			$termLang = MultilingualBridge::getTermLanguage($termId);
			if ($termLang !== '' && $termLang !== $lang) {
				return null;
			}
		}

		return $termId;
	}

	private static function copySharedTermMeta(int $canonicalTermId, int $translationTermId): void
	{
		foreach (self::sharedTermMetaKeys() as $metaKey) {
			$value = get_term_meta($canonicalTermId, $metaKey, true);
			if ($value === '' || $value === false) {
				delete_term_meta($translationTermId, $metaKey);
			} else {
				update_term_meta($translationTermId, $metaKey, $value);
			}
		}
	}

	/**
	 * Translation terms must not carry provider lookup meta or they appear as duplicate canonicals.
	 */
	private static function stripProviderLookupMeta(int $translationTermId): void
	{
		delete_term_meta($translationTermId, 'bec_external_id');
		delete_term_meta($translationTermId, 'bec_provider_slug');
		delete_term_meta($translationTermId, MultilingualBridge::META_TRANSLATION_TERM_IDS);
	}

	/**
	 * @return list<string>
	 */
	private static function sharedTermMetaKeys(): array
	{
		$keys = [
			'bec_category_names',
			'bec_category_normalized',
			'bec_last_sync_at',
		];

		/** @var list<string> $filtered */
		$filtered = apply_filters('bec_category_translation_shared_term_meta_keys', $keys);

		return is_array($filtered) ? array_values(array_unique(array_map('strval', $filtered))) : $keys;
	}
}
