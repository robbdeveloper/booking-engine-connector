<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Taxonomies\UnitAmenityTaxonomy;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;
use BookingEngineConnector\Units\AmenityItem;
use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Syncs {@see bec_core_amenities} into the {@see UnitAmenityTaxonomy} index.
 */
final class UnitAmenityIndexer
{
	public const OPTION_INDEX_COMPLETE = 'bec_unit_amenity_index_complete';

	public const OPTION_BACKFILL_OFFSET = 'bec_unit_amenity_backfill_offset';

	private const BACKFILL_BATCH_SIZE = 50;

	private const CRON_HOOK = 'bec_unit_amenity_backfill_batch';

	public static function register(): void
	{
		\add_action('bec_core_unit_fields_applied', [self::class, 'onCoreFieldsApplied'], 10, 2);
		\add_action(self::CRON_HOOK, [self::class, 'runBackfillBatch']);
		\add_action('init', [self::class, 'maybeScheduleBackfill'], 20);
	}

	public static function isIndexComplete(): bool
	{
		return (bool) \get_option(self::OPTION_INDEX_COMPLETE, false);
	}

	/**
	 * @param array<string, mixed> $data Core unit field map from sync.
	 */
	public static function onCoreFieldsApplied(int $postId, array $data): void
	{
		if (\get_post_type($postId) !== UnitPostType::getSlug()) {
			return;
		}

		$items = [];
		if (isset($data[ CoreUnitSemantic::AMENITIES ]) && \is_array($data[ CoreUnitSemantic::AMENITIES ])) {
			$items = AmenityItem::normalizeList($data[ CoreUnitSemantic::AMENITIES ]);
		} else {
			$metaKey = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::AMENITIES) ?? 'bec_core_amenities';
			$raw     = \get_post_meta($postId, $metaKey, true);
			$items   = self::decodeAmenitiesMeta($raw);
		}

		self::syncAmenitiesForPost($postId, $items);
	}

	/**
	 * @param list<array{key: string, labels: array<string, string>, icon?: string, category?: string}> $items
	 */
	public static function syncAmenitiesForPost(int $postId, array $items): void
	{
		$termIds = [];

		foreach ($items as $item) {
			$key = $item['key'] ?? '';
			if ($key === '') {
				continue;
			}

			$termId = self::upsertTermForAmenity($item);
			if ($termId > 0) {
				$termIds[] = $termId;
			}
		}

		\wp_set_object_terms($postId, $termIds, UnitAmenityTaxonomy::TAXONOMY, false);
	}

	/**
	 * @param array{key: string, labels: array<string, string>, icon?: string, category?: string} $item
	 */
	private static function upsertTermForAmenity(array $item): int
	{
		$key = $item['key'];
		$term = \get_term_by('slug', $key, UnitAmenityTaxonomy::TAXONOMY);

		$labels = isset($item['labels']) && \is_array($item['labels'])
			? UnitCategoryTaxonomy::normalizeNamesArray($item['labels'])
			: [];

		$displayName = UnitAmenityTaxonomy::resolveLocalizedLabelFromNames($labels);
		if ($displayName === '') {
			$displayName = $key;
		}

		if (! $term instanceof \WP_Term) {
			$created = \wp_insert_term($displayName, UnitAmenityTaxonomy::TAXONOMY, ['slug' => $key]);
			if ($created instanceof \WP_Error) {
				return 0;
			}
			$termId = (int) ($created['term_id'] ?? 0);
		} else {
			$termId = (int) $term->term_id;
			if ($term->name !== $displayName) {
				\wp_update_term($termId, UnitAmenityTaxonomy::TAXONOMY, ['name' => $displayName]);
			}
		}

		if ($termId < 1) {
			return 0;
		}

		$encoded = \wp_json_encode($labels, \JSON_UNESCAPED_UNICODE);
		if (\is_string($encoded)) {
			\update_term_meta($termId, UnitAmenityTaxonomy::TERM_META_LABELS, $encoded);
		}

		$category = isset($item['category']) ? \sanitize_key((string) $item['category']) : '';
		\update_term_meta($termId, UnitAmenityTaxonomy::TERM_META_CATEGORY, $category);

		return $termId;
	}

	public static function maybeScheduleBackfill(): void
	{
		if (self::isIndexComplete()) {
			return;
		}

		if (! \wp_next_scheduled(self::CRON_HOOK)) {
			\wp_schedule_single_event(\time() + 30, self::CRON_HOOK);
		}
	}

	public static function runBackfillBatch(): void
	{
		if (self::isIndexComplete()) {
			return;
		}

		$offset = (int) \get_option(self::OPTION_BACKFILL_OFFSET, 0);
		$offset = \max(0, $offset);

		$q = new \WP_Query([
			'post_type'              => UnitPostType::getSlug(),
			'post_status'            => 'any',
			'posts_per_page'         => self::BACKFILL_BATCH_SIZE,
			'offset'                 => $offset,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

		$ids = [];
		if (isset($q->posts) && \is_array($q->posts)) {
			foreach ($q->posts as $id) {
				$ids[] = (int) $id;
			}
		}

		if ($ids === []) {
			\update_option(self::OPTION_INDEX_COMPLETE, true, false);
			\delete_option(self::OPTION_BACKFILL_OFFSET);

			return;
		}

		$metaKey = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::AMENITIES) ?? 'bec_core_amenities';

		foreach ($ids as $postId) {
			$raw   = \get_post_meta($postId, $metaKey, true);
			$items = self::decodeAmenitiesMeta($raw);
			self::syncAmenitiesForPost($postId, $items);
		}

		$newOffset = $offset + \count($ids);
		\update_option(self::OPTION_BACKFILL_OFFSET, $newOffset, false);

		if (\count($ids) < self::BACKFILL_BATCH_SIZE) {
			\update_option(self::OPTION_INDEX_COMPLETE, true, false);
			\delete_option(self::OPTION_BACKFILL_OFFSET);

			return;
		}

		\wp_schedule_single_event(\time() + 5, self::CRON_HOOK);
	}

	/**
	 * @return list<array{key: string, labels: array<string, string>, icon?: string, category?: string}>
	 */
	private static function decodeAmenitiesMeta($raw): array
	{
		if (\is_string($raw) && $raw !== '') {
			$decoded = \json_decode($raw, true);
		} elseif (\is_array($raw)) {
			$decoded = $raw;
		} else {
			return [];
		}

		if (! \is_array($decoded)) {
			return [];
		}

		return AmenityItem::normalizeList($decoded);
	}
}
