<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Upserts synced provider categories as terms and assigns them to units after sync.
 */
final class UnitCategorySync
{
	public static function register(): void
	{
		add_action('bec_after_unit_sync', [self::class, 'onAfterUnitSync'], 10, 3);
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

		$termId = self::findTermIdForProviderCategory($providerSlug, $externalId, $descriptor);

		if ($termId === null) {
			$termId = self::createTermForDescriptor($descriptor, $providerSlug, $externalId);
			if ($termId === null) {
				return;
			}
		} else {
			self::updateTermFromDescriptor($termId, $descriptor, $providerSlug, $externalId);
		}

		wp_set_object_terms($postId, [$termId], UnitCategoryTaxonomy::getSlug(), false);
	}

	/**
	 * @param array<string, mixed>|null $descriptor
	 */
	private static function findTermIdForProviderCategory(string $providerSlug, string $externalId, ?array $descriptor = null): ?int
	{
		return self::findCanonicalTermIdForProviderCategory($providerSlug, $externalId, $descriptor);
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
				],
			]
		);

		if (is_wp_error($terms) || ! is_array($terms)) {
			return [];
		}

		$ids = [];
		foreach ($terms as $termId) {
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
			$names      = $descriptor['names'];
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
}
