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
	public static function register(): void
	{
		\add_action('bec_after_unit_sync', [self::class, 'onAfterUnitSync'], 15, 3);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function onAfterUnitSync(int $postId, string $providerSlug, array $row): void
	{
		if (! MultilingualBridge::isFeatureEnabled()) {
			return;
		}

		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		if ((int) \get_post_meta($postId, MultilingualBridge::META_TRANSLATION_OF, true) > 0) {
			return;
		}

		$descriptor = $row['unit_category'] ?? null;
		if (! \is_array($descriptor)) {
			return;
		}

		/** @var array<string, mixed> $descriptor */
		$externalId = (string) ($descriptor['external_id'] ?? '');
		if ($externalId === '') {
			return;
		}

		$canonicalTermId = UnitCategorySync::findCanonicalTermIdForProviderCategory($providerSlug, $externalId, $descriptor);
		if ($canonicalTermId === null) {
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
		$strings = \apply_filters('bec_category_translation_strings', [], $descriptor, $providerSlug, $canonicalTermId);
		if (! \is_array($strings)) {
			$strings = [];
		}

		$translationMap = [];
		foreach (MultilingualBridge::getActiveLanguages() as $lang) {
			if ($lang === $defaultLang) {
				$translationMap[ $lang ] = $canonicalTermId;
				continue;
			}

			$name = isset($strings[ $lang ]) ? \trim((string) $strings[ $lang ]) : '';
			if ($name === '') {
				$existingId = MultilingualBridge::getTranslatedTermId($canonicalTermId, $lang);
				if ($existingId !== null) {
					$translationMap[ $lang ] = $existingId;
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
			\update_term_meta($canonicalTermId, MultilingualBridge::META_TRANSLATION_TERM_IDS, $translationMap);
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
		$existingId = self::findExistingTranslationTermId($canonicalTermId, $lang, $name, $descriptor, $externalId);

		$slug = UnitCategorySync::buildSlugForDescriptor($descriptor, $externalId, $name);
		$taxonomy = UnitCategoryTaxonomy::getSlug();

		if ($existingId !== null && $existingId > 0) {
			$result = \wp_update_term(
				$existingId,
				$taxonomy,
				[
					'name' => $name,
					'slug' => $slug,
				]
			);
			if (\is_wp_error($result)) {
				return 0;
			}
			$translationId = $existingId;
		} else {
			$result = \wp_insert_term(
				$name,
				$taxonomy,
				[
					'slug' => $slug,
				]
			);
			if (\is_wp_error($result)) {
				$adoptedId = self::findAdoptableTranslationTermBySlug($slug, $lang, $canonicalTermId, $externalId);
				if ($adoptedId === null) {
					return 0;
				}
				$translationId = $adoptedId;
				\wp_update_term(
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

		\update_term_meta($translationId, MultilingualBridge::META_TRANSLATION_OF_TERM, $canonicalTermId);
		\update_term_meta($translationId, MultilingualBridge::META_TRANSLATION_TERM_LANG, $lang);

		self::copySharedTermMeta($canonicalTermId, $translationId);

		MultilingualBridge::setTermLanguage($translationId, $lang);
		MultilingualBridge::linkTermTranslation($canonicalTermId, $defaultLang, $translationId, $lang);

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
		$existingId = MultilingualBridge::getTranslatedTermId($canonicalTermId, $lang);
		if ($existingId !== null && $existingId > 0 && $existingId !== $canonicalTermId) {
			return $existingId;
		}

		$storedMap = \get_term_meta($canonicalTermId, MultilingualBridge::META_TRANSLATION_TERM_IDS, true);
		if (\is_array($storedMap) && isset($storedMap[ $lang ])) {
			$candidate = (int) $storedMap[ $lang ];
			if ($candidate > 0 && $candidate !== $canonicalTermId) {
				return $candidate;
			}
		}

		$terms = \get_terms(
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

		if (\is_array($terms) && ! \is_wp_error($terms)) {
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
		$term = \get_term_by('slug', $slug, UnitCategoryTaxonomy::getSlug());
		if (! $term instanceof \WP_Term) {
			return null;
		}

		$termId = (int) $term->term_id;
		if ($termId <= 0 || $termId === $canonicalTermId) {
			return null;
		}

		$translationOf = (int) \get_term_meta($termId, MultilingualBridge::META_TRANSLATION_OF_TERM, true);
		if ($translationOf > 0 && $translationOf !== $canonicalTermId) {
			return null;
		}

		$existingExternalId = (string) \get_term_meta($termId, 'bec_external_id', true);
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
			$value = \get_term_meta($canonicalTermId, $metaKey, true);
			if ($value === '' || $value === false) {
				\delete_term_meta($translationTermId, $metaKey);
			} else {
				\update_term_meta($translationTermId, $metaKey, $value);
			}
		}
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
		$filtered = \apply_filters('bec_category_translation_shared_term_meta_keys', $keys);

		return \is_array($filtered) ? \array_values(\array_unique(\array_map('strval', $filtered))) : $keys;
	}
}
