<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\Integrations\CategoryTranslationSync;
use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Upserts synced provider categories as terms and assigns them to units after sync.
 */
final class UnitCategorySync
{
	/** @var array<string, int> providerSlug|externalId => canonical term ID (request cache) */
	private static array $canonicalTermCache = [];

	public static function register(): void
	{
		add_action('bec_after_unit_sync', [self::class, 'onAfterUnitSync'], 10, 3);
	}

	/**
	 * Prime canonical category terms once per sync batch (one term per provider category ID).
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	public static function syncUniqueDescriptorsFromRows(string $providerSlug, array $rows): void
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		self::$canonicalTermCache = [];

		do_action('bec_before_category_registry_sync');

		self::repairDuplicateCanonicalTerms($providerSlug);
		CategoryTranslationSync::cleanupExistingTranslationProviderMeta();

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
			$externalId = (string) ($descriptor['external_id'] ?? '');
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
			self::syncDescriptor($providerSlug, $descriptor);
		}
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	public static function syncDescriptor(string $providerSlug, array $descriptor): ?int
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return null;
		}

		/** @var array<string, mixed> $descriptor */
		$descriptor = apply_filters('bec_sync_unit_category', $descriptor, [], $providerSlug, 0);

		$externalId = (string) ($descriptor['external_id'] ?? '');
		if ($externalId === '') {
			return null;
		}

		$cacheKey = self::cacheKey($providerSlug, $externalId);
		if (isset(self::$canonicalTermCache[ $cacheKey ])) {
			return self::$canonicalTermCache[ $cacheKey ];
		}

		$termId = self::findCanonicalTermIdForProviderCategory($providerSlug, $externalId, $descriptor);

		if ($termId === null) {
			$termId = self::createTermForDescriptor($descriptor, $providerSlug, $externalId);
		} else {
			self::updateTermFromDescriptor($termId, $descriptor, $providerSlug, $externalId);
		}

		if ($termId === null) {
			return null;
		}

		self::$canonicalTermCache[ $cacheKey ] = $termId;

		/**
		 * @param int                  $termId Canonical category term ID.
		 * @param string               $providerSlug
		 * @param array<string, mixed> $descriptor
		 */
		do_action('bec_after_category_sync', $termId, $providerSlug, $descriptor);

		return $termId;
	}

	/**
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
		$externalId = (string) ($descriptor['external_id'] ?? '');
		if ($externalId === '') {
			return;
		}

		$termId = self::resolveCanonicalTermId($providerSlug, $externalId, $descriptor);
		if ($termId === null) {
			$termId = self::syncDescriptor($providerSlug, $descriptor);
		}

		if ($termId === null) {
			return;
		}

		wp_set_object_terms($postId, [$termId], UnitCategoryTaxonomy::getSlug(), false);

		CategoryTranslationSync::syncTranslationsForCanonicalTerm($termId, $providerSlug, $descriptor);
	}

	/**
	 * @param array<string, mixed>|null $descriptor
	 */
	private static function resolveCanonicalTermId(string $providerSlug, string $externalId, ?array $descriptor = null): ?int
	{
		$cacheKey = self::cacheKey($providerSlug, $externalId);
		if (isset(self::$canonicalTermCache[ $cacheKey ])) {
			return self::$canonicalTermCache[ $cacheKey ];
		}

		$termId = self::findCanonicalTermIdForProviderCategory($providerSlug, $externalId, $descriptor);
		if ($termId !== null) {
			self::$canonicalTermCache[ $cacheKey ] = $termId;
		}

		return $termId;
	}

	/**
	 * @param array<string, mixed>|null $descriptor
	 */
	public static function findCanonicalTermIdForProviderCategory(string $providerSlug, string $externalId, ?array $descriptor = null): ?int
	{
		$byMeta = self::findTermIdsByProviderMeta($providerSlug, $externalId);
		if ($byMeta !== []) {
			return self::pickCanonicalTermId($byMeta);
		}

		if ($descriptor !== null) {
			return self::findAdoptableExistingTerm($descriptor, $externalId);
		}

		return null;
	}

	/**
	 * @return list<int>
	 */
	private static function findTermIdsByProviderMeta(string $providerSlug, string $externalId): array
	{
		$fromQuery = self::findTermIdsByProviderMetaQuery($providerSlug, $externalId);
		if ($fromQuery !== []) {
			return $fromQuery;
		}

		return self::findTermIdsByProviderMetaDb($providerSlug, $externalId);
	}

	/**
	 * @return list<int>
	 */
	private static function findTermIdsByProviderMetaQuery(string $providerSlug, string $externalId): array
	{
		$terms = get_terms(
			[
				'taxonomy'         => UnitCategoryTaxonomy::getSlug(),
				'hide_empty'       => false,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => [
					'relation' => 'AND',
					[
						'key'   => 'bec_provider_slug',
						'value' => $providerSlug,
					],
					[
						'key'   => 'bec_external_id',
						'value' => $externalId,
					],
					MultilingualBridge::canonicalOnlyTermMetaQueryBranch(),
				],
			]
		);

		if (is_wp_error($terms) || ! is_array($terms)) {
			return [];
		}

		return self::normalizeTermIdList($terms);
	}

	/**
	 * @return list<int>
	 */
	private static function findTermIdsByProviderMetaDb(string $providerSlug, string $externalId): array
	{
		global $wpdb;

		if (! isset($wpdb->termmeta, $wpdb->term_taxonomy)) {
			return [];
		}

		$translationMetaKey = MultilingualBridge::META_TRANSLATION_OF_TERM;

		$sql = "
			SELECT tm_provider.term_id
			FROM {$wpdb->termmeta} tm_provider
			INNER JOIN {$wpdb->termmeta} tm_external
				ON tm_provider.term_id = tm_external.term_id
			INNER JOIN {$wpdb->term_taxonomy} tt
				ON tm_provider.term_id = tt.term_id
			WHERE tt.taxonomy = %s
				AND tm_provider.meta_key = 'bec_provider_slug'
				AND tm_provider.meta_value = %s
				AND tm_external.meta_key = 'bec_external_id'
				AND tm_external.meta_value = %s
				AND NOT EXISTS (
					SELECT 1
					FROM {$wpdb->termmeta} tm_trans
					WHERE tm_trans.term_id = tm_provider.term_id
						AND tm_trans.meta_key = %s
						AND tm_trans.meta_value != ''
						AND tm_trans.meta_value != '0'
				)
		";

		/** @var list<string>|null $raw */
		$raw = $wpdb->get_col(
			$wpdb->prepare(
				$sql,
				UnitCategoryTaxonomy::getSlug(),
				$providerSlug,
				$externalId,
				$translationMetaKey
			)
		);

		if (! is_array($raw)) {
			return [];
		}

		return self::normalizeTermIdList($raw);
	}

	/**
	 * @param array<int|string, mixed> $termIds
	 *
	 * @return list<int>
	 */
	private static function normalizeTermIdList(array $termIds): array
	{
		$ids = [];

		foreach ($termIds as $termId) {
			$termId = (int) $termId;
			if ($termId > 0) {
				$ids[] = $termId;
			}
		}

		return $ids;
	}

	/**
	 * @param list<int> $termIds
	 */
	private static function pickCanonicalTermId(array $termIds): ?int
	{
		$canonical = [];

		foreach ($termIds as $termId) {
			if (! self::isTranslationTerm($termId)) {
				$canonical[] = $termId;
			}
		}

		if ($canonical === []) {
			return null;
		}

		sort($canonical, SORT_NUMERIC);

		return $canonical[0];
	}

	public static function isTranslationTerm(int $termId): bool
	{
		if ($termId < 1) {
			return false;
		}

		return (int) get_term_meta($termId, MultilingualBridge::META_TRANSLATION_OF_TERM, true) > 0;
	}

	/**
	 * Reuse a pre-existing taxonomy term (manual or legacy) that lacks provider meta.
	 *
	 * @param array<string, mixed> $descriptor
	 */
	private static function findAdoptableExistingTerm(array $descriptor, string $externalId): ?int
	{
		foreach (self::slugCandidatesForDescriptor($descriptor, $externalId) as $slug) {
			$term = get_term_by('slug', $slug, UnitCategoryTaxonomy::getSlug());
			if (! $term instanceof \WP_Term) {
				continue;
			}

			$termId = (int) $term->term_id;
			if (self::canAdoptExistingTerm($termId, $externalId)) {
				return $termId;
			}
		}

		foreach (self::nameCandidatesForDescriptor($descriptor) as $name) {
			$terms = get_terms(
				[
					'taxonomy'         => UnitCategoryTaxonomy::getSlug(),
					'hide_empty'       => false,
					'fields'           => 'ids',
					'name'             => $name,
					'suppress_filters' => true,
				]
			);

			if (is_wp_error($terms) || ! is_array($terms)) {
				continue;
			}

			foreach ($terms as $termId) {
				$termId = (int) $termId;
				if ($termId > 0 && self::canAdoptExistingTerm($termId, $externalId)) {
					return $termId;
				}
			}
		}

		return null;
	}

	private static function canAdoptExistingTerm(int $termId, string $externalId): bool
	{
		if (self::isTranslationTerm($termId)) {
			return false;
		}

		$existingExternalId = (string) get_term_meta($termId, 'bec_external_id', true);
		if ($existingExternalId !== '' && $existingExternalId !== $externalId) {
			return false;
		}

		if (MultilingualBridge::isFeatureEnabled()) {
			$defaultLang = MultilingualBridge::getDefaultLanguage();
			if ($defaultLang !== '') {
				$termLang = MultilingualBridge::getTermLanguage($termId);
				if ($termLang !== '' && $termLang !== $defaultLang) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $descriptor
	 *
	 * @return list<string>
	 */
	private static function slugCandidatesForDescriptor(array $descriptor, string $externalId): array
	{
		$slugs = [self::buildSlugForDescriptor($descriptor, $externalId)];

		foreach (self::nameCandidatesForDescriptor($descriptor) as $name) {
			$slug = sanitize_title($name);
			if ($slug !== '') {
				$slugs[] = $slug;
			}
		}

		return array_values(array_unique($slugs));
	}

	/**
	 * @param array<string, mixed> $descriptor
	 *
	 * @return list<string>
	 */
	private static function nameCandidatesForDescriptor(array $descriptor): array
	{
		$names = [];

		$defaultName = UnitCategoryTaxonomy::resolveDefaultDisplayName($descriptor);
		if ($defaultName !== '') {
			$names[] = $defaultName;
		}

		$rawName = (string) ($descriptor['name'] ?? '');
		if ($rawName !== '') {
			$names[] = $rawName;
		}

		if (isset($descriptor['names']) && is_array($descriptor['names'])) {
			/** @var array<mixed, mixed> $namesRaw */
			$namesRaw = $descriptor['names'];
			foreach (UnitCategoryTaxonomy::coerceDescriptorNamesToMap($namesRaw) as $label) {
				if ($label !== '') {
					$names[] = $label;
				}
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function createTermForDescriptor(array $descriptor, string $providerSlug, string $externalId): ?int
	{
		$displayName = UnitCategoryTaxonomy::resolveDefaultDisplayName($descriptor);
		if ($displayName === '') {
			$displayName = sprintf(
				/* translators: %s provider category external id */
				__('Category %s', 'booking-engine-connector'),
				$externalId
			);
		}

		$slug = self::buildUniqueSlug($descriptor, $externalId);

		$result = wp_insert_term(
			$displayName,
			UnitCategoryTaxonomy::getSlug(),
			[
				'slug' => $slug,
			]
		);

		if (is_wp_error($result)) {
			$adopted = self::findAdoptableExistingTerm($descriptor, $externalId);
			if ($adopted === null) {
				return null;
			}

			self::persistTermMeta($adopted, $descriptor, $providerSlug, $externalId);
			self::ensureCanonicalTermLanguage($adopted);

			return $adopted;
		}

		$termId = (int) ($result['term_id'] ?? 0);
		if ($termId <= 0) {
			return null;
		}

		self::persistTermMeta($termId, $descriptor, $providerSlug, $externalId);
		self::ensureCanonicalTermLanguage($termId);

		return $termId;
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function updateTermFromDescriptor(int $termId, array $descriptor, string $providerSlug, string $externalId): void
	{
		$displayName = UnitCategoryTaxonomy::resolveDefaultDisplayName($descriptor);
		if ($displayName !== '') {
			wp_update_term(
				$termId,
				UnitCategoryTaxonomy::getSlug(),
				[
					'name' => $displayName,
					'slug' => self::buildSlugForDescriptor($descriptor, $externalId),
				]
			);
		}

		self::persistTermMeta($termId, $descriptor, $providerSlug, $externalId);
		self::ensureCanonicalTermLanguage($termId);
	}

	private static function ensureCanonicalTermLanguage(int $termId): void
	{
		if (! MultilingualBridge::isFeatureEnabled()) {
			return;
		}

		$defaultLang = MultilingualBridge::getDefaultLanguage();
		if ($defaultLang === '') {
			return;
		}

		$currentLang = MultilingualBridge::getTermLanguage($termId);
		if ($currentLang === '' || $currentLang !== $defaultLang) {
			MultilingualBridge::setTermLanguage($termId, $defaultLang);
		}
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function persistTermMeta(int $termId, array $descriptor, string $providerSlug, string $externalId): void
	{
		$namesMap = [];
		if (isset($descriptor['names']) && is_array($descriptor['names'])) {
			/** @var array<mixed, mixed> $names */
			$names    = $descriptor['names'];
			$namesMap = UnitCategoryTaxonomy::coerceDescriptorNamesToMap($names);
		}

		update_term_meta($termId, 'bec_provider_slug', $providerSlug);
		update_term_meta($termId, 'bec_external_id', $externalId);

		$encodedNames = wp_json_encode($namesMap, JSON_UNESCAPED_UNICODE);
		update_term_meta($termId, 'bec_category_names', $encodedNames !== false ? $encodedNames : '');

		$descriptorJson = wp_json_encode($descriptor, JSON_UNESCAPED_UNICODE);
		update_term_meta($termId, 'bec_category_normalized', $descriptorJson !== false ? $descriptorJson : '');

		update_term_meta($termId, 'bec_last_sync_at', current_time('mysql'));
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	public static function buildSlugForDescriptor(array $descriptor, string $externalId, ?string $displayName = null): string
	{
		unset($externalId);

		if ($displayName === null) {
			$displayName = UnitCategoryTaxonomy::resolveDefaultDisplayName($descriptor);
		}
		if ($displayName === '' && isset($descriptor['name']) && is_string($descriptor['name'])) {
			$displayName = trim($descriptor['name']);
		}

		$candidate = $displayName !== '' ? sanitize_title($displayName) : '';
		if ($candidate === '') {
			$candidate = 'category';
		}

		return $candidate;
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function buildUniqueSlug(array $descriptor, string $externalId): string
	{
		return self::buildSlugForDescriptor($descriptor, $externalId);
	}

	private static function cacheKey(string $providerSlug, string $externalId): string
	{
		return $providerSlug . '|' . $externalId;
	}

	/**
	 * Merge duplicate canonical provider category terms into one winner per provider/external ID.
	 */
	public static function repairDuplicateCanonicalTerms(?string $providerSlug = null): void
	{
		if (! UnitCategoryTaxonomy::isEnabled()) {
			return;
		}

		$taxonomy = UnitCategoryTaxonomy::getSlug();

		$terms = get_terms(
			[
				'taxonomy'         => $taxonomy,
				'hide_empty'       => false,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => [
					'relation' => 'AND',
					[
						'key'     => 'bec_provider_slug',
						'compare' => 'EXISTS',
					],
					[
						'key'     => 'bec_external_id',
						'compare' => 'EXISTS',
					],
					MultilingualBridge::canonicalOnlyTermMetaQueryBranch(),
				],
			]
		);

		if (is_wp_error($terms) || ! is_array($terms) || $terms === []) {
			return;
		}

		/** @var array<string, list<int>> $groups */
		$groups = [];

		foreach ($terms as $termId) {
			$termId = (int) $termId;
			if ($termId < 1 || self::isTranslationTerm($termId)) {
				continue;
			}

			$storedProvider = (string) get_term_meta($termId, 'bec_provider_slug', true);
			$storedExternal = (string) get_term_meta($termId, 'bec_external_id', true);
			if ($storedProvider === '' || $storedExternal === '') {
				continue;
			}

			if ($providerSlug !== null && $providerSlug !== '' && $storedProvider !== $providerSlug) {
				continue;
			}

			$groupKey = $storedProvider . '|' . $storedExternal;
			$groups[ $groupKey ][] = $termId;
		}

		foreach ($groups as $termIds) {
			if (count($termIds) < 2) {
				continue;
			}

			sort($termIds, SORT_NUMERIC);
			$winnerId = $termIds[0];
			$losers   = array_slice($termIds, 1);

			foreach ($losers as $loserId) {
				self::mergeCanonicalTermIntoWinner($winnerId, $loserId, $taxonomy);
			}
		}
	}

	private static function mergeCanonicalTermIntoWinner(int $winnerId, int $loserId, string $taxonomy): void
	{
		if ($loserId < 1 || $winnerId < 1 || $loserId === $winnerId) {
			return;
		}

		if (self::isTranslationTerm($loserId)) {
			return;
		}

		$objects = get_objects_in_term($loserId, $taxonomy);
		if (is_array($objects) && ! is_wp_error($objects)) {
			foreach ($objects as $objectId) {
				$objectId = (int) $objectId;
				if ($objectId < 1) {
					continue;
				}

				$current = wp_get_object_terms($objectId, $taxonomy, ['fields' => 'ids']);
				if (! is_array($current) || is_wp_error($current)) {
					continue;
				}

				$updated = [];
				$seen    = [];
				foreach ($current as $termId) {
					$termId = (int) $termId;
					if ($termId < 1) {
						continue;
					}

					$mappedId = $termId === $loserId ? $winnerId : $termId;
					if (! isset($seen[ $mappedId ])) {
						$updated[]           = $mappedId;
						$seen[ $mappedId ] = true;
					}
				}

				if ($updated !== []) {
					wp_set_object_terms($objectId, $updated, $taxonomy, false);
				}
			}
		}

		self::repointTranslationTerms($winnerId, $loserId);

		$storedMap = get_term_meta($loserId, MultilingualBridge::META_TRANSLATION_TERM_IDS, true);
		if (is_array($storedMap) && $storedMap !== []) {
			$winnerMap = get_term_meta($winnerId, MultilingualBridge::META_TRANSLATION_TERM_IDS, true);
			if (! is_array($winnerMap)) {
				$winnerMap = [];
			}
			foreach ($storedMap as $lang => $translationId) {
				if (! isset($winnerMap[ $lang ]) || (int) $winnerMap[ $lang ] < 1) {
					$winnerMap[ $lang ] = $translationId;
				}
			}
			update_term_meta($winnerId, MultilingualBridge::META_TRANSLATION_TERM_IDS, $winnerMap);
		}

		foreach (['bec_category_names', 'bec_category_normalized', 'bec_last_sync_at'] as $metaKey) {
			$winnerValue = get_term_meta($winnerId, $metaKey, true);
			$loserValue  = get_term_meta($loserId, $metaKey, true);
			if (($winnerValue === '' || $winnerValue === false) && $loserValue !== '' && $loserValue !== false) {
				update_term_meta($winnerId, $metaKey, $loserValue);
			}
		}

		wp_delete_term($loserId, $taxonomy);
	}

	private static function repointTranslationTerms(int $winnerId, int $loserId): void
	{
		$terms = get_terms(
			[
				'taxonomy'         => UnitCategoryTaxonomy::getSlug(),
				'hide_empty'       => false,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => [
					[
						'key'   => MultilingualBridge::META_TRANSLATION_OF_TERM,
						'value' => $loserId,
					],
				],
			]
		);

		if (! is_array($terms) || is_wp_error($terms)) {
			return;
		}

		$defaultLang = MultilingualBridge::getDefaultLanguage();

		foreach ($terms as $translationId) {
			$translationId = (int) $translationId;
			if ($translationId < 1) {
				continue;
			}

			update_term_meta($translationId, MultilingualBridge::META_TRANSLATION_OF_TERM, $winnerId);

			$lang = (string) get_term_meta($translationId, MultilingualBridge::META_TRANSLATION_TERM_LANG, true);
			if ($lang !== '' && $defaultLang !== '') {
				MultilingualBridge::linkTermTranslation($winnerId, $defaultLang, $translationId, $lang);
			}
		}
	}
}
