<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

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

		$termId = self::findTermIdForProviderCategory($providerSlug, $externalId);

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

	private static function findTermIdForProviderCategory(string $providerSlug, string $externalId): ?int
	{
		$terms = get_terms(
			[
				'taxonomy'               => UnitCategoryTaxonomy::getSlug(),
				'hide_empty'             => false,
				'number'                   => 1,
				'fields'                   => 'ids',
				'suppress_filters'          => false,
				'meta_query'               => [
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

		if (is_wp_error($terms) || ! is_array($terms) || $terms === []) {
			return null;
		}

		return (int) $terms[0];
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
			return null;
		}

		$termId = (int) ($result['term_id'] ?? 0);
		if ($termId <= 0) {
			return null;
		}

		self::persistTermMeta($termId, $descriptor, $providerSlug, $externalId);

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
				]
			);
		}

		self::persistTermMeta($termId, $descriptor, $providerSlug, $externalId);
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
	private static function buildUniqueSlug(array $descriptor, string $externalId): string
	{
		$name        = UnitCategoryTaxonomy::resolveDefaultDisplayName($descriptor);
		$base        = $name !== '' ? sanitize_title($name) : '';
		if ($base === '') {
			$base = 'category';
		}

		$candidate = sanitize_title($base . '-' . $externalId);
		if ($candidate === '') {
			$candidate = 'category-' . sanitize_title($externalId);
		}

		return $candidate;
	}
}
