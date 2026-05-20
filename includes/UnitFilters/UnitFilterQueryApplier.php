<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\Taxonomies\UnitAmenityTaxonomy;
use BookingEngineConnector\Units\AmenityItem;
use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\CoreUnitSemantic;
use WP_Query;

/**
 * Merges unit filter constraints into a {@see WP_Query}.
 */
final class UnitFilterQueryApplier
{
	public static function apply(WP_Query $query, UnitFilterRequest $request): void
	{
		if (! $request->hasActiveFilters()) {
			return;
		}

		$defs = UnitFilterRegistry::definitions();

		if ($request->getOrder() !== '') {
			$query->set('order', $request->getOrder());
		}

		$metaBranches = [];

		if ($request->getRoomsMin() > 0) {
			$metaKey = $defs[ UnitFilterRegistry::FILTER_ROOMS ]['meta_key'] ?? 'bec_core_rooms';
			$metaBranches[] = [
				'key'     => $metaKey,
				'value'   => $request->getRoomsMin(),
				'type'    => 'NUMERIC',
				'compare' => '>=',
			];
		}

		if ($request->getBathroomsMin() > 0.0) {
			$metaKey = $defs[ UnitFilterRegistry::FILTER_BATHROOMS ]['meta_key'] ?? 'bec_core_bathrooms';
			$metaBranches[] = [
				'key'     => $metaKey,
				'value'   => UnitFilterRequest::formatBathroomsMin($request->getBathroomsMin()),
				'type'    => 'DECIMAL',
				'compare' => '>=',
			];
		}

		if ($metaBranches !== []) {
			self::mergeMetaQuery($query, $metaBranches);
		}

		$amenityKeys = $request->getAmenityKeys();
		if ($amenityKeys !== []) {
			self::applyAmenityFilter($query, $amenityKeys);
		}

		/**
		 * @param WP_Query           $query
		 * @param UnitFilterRequest  $request
		 */
		\do_action('bec_unit_filter_query_applied', $query, $request);
	}

	/**
	 * @param list<array<string, mixed>> $branches
	 */
	private static function mergeMetaQuery(WP_Query $query, array $branches): void
	{
		$existing = $query->get('meta_query');
		if (! \is_array($existing) || $existing === []) {
			$query->set('meta_query', [
				'relation' => 'AND',
				...$branches,
			]);

			return;
		}

		$query->set('meta_query', [
			'relation' => 'AND',
			$existing,
			...$branches,
		]);
	}

	/**
	 * @param list<string> $keys
	 */
	private static function applyAmenityFilter(WP_Query $query, array $keys): void
	{
		$keys = \array_values(\array_filter(\array_map('sanitize_key', $keys)));
		if ($keys === []) {
			return;
		}

		if (UnitAmenityIndexer::isIndexComplete()) {
			$termIds = [];
			foreach ($keys as $key) {
				$term = \get_term_by('slug', $key, UnitAmenityTaxonomy::TAXONOMY);
				if ($term instanceof \WP_Term) {
					$termIds[] = (int) $term->term_id;
				}
			}
			if ($termIds === []) {
				$query->set('post__in', [0]);

				return;
			}

			$taxBranches = [];
			foreach ($termIds as $termId) {
				$taxBranches[] = [
					'taxonomy' => UnitAmenityTaxonomy::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => [ $termId ],
				];
			}

			self::mergeTaxQuery($query, [
				'relation' => 'AND',
				...$taxBranches,
			]);

			return;
		}

		self::applyAmenityFilterViaMetaScan($query, $keys);
	}

	/**
	 * @param array<string, mixed> $branch
	 */
	private static function mergeTaxQuery(WP_Query $query, array $branch): void
	{
		$existing = $query->get('tax_query');
		if (! \is_array($existing) || $existing === []) {
			$query->set('tax_query', $branch);

			return;
		}

		$query->set('tax_query', [
			'relation' => 'AND',
			$existing,
			$branch,
		]);
	}

	/**
	 * Fallback when the amenity taxonomy index is not complete: restrict via post__in from meta scan.
	 *
	 * @param list<string> $keys
	 */
	private static function applyAmenityFilterViaMetaScan(WP_Query $query, array $keys): void
	{
		$metaKey = CoreUnitMetaKeys::metaKeyForSemantic(CoreUnitSemantic::AMENITIES) ?? 'bec_core_amenities';
		$postIds = self::postIdsMatchingAllAmenityKeys($metaKey, $keys);

		if ($postIds === []) {
			$query->set('post__in', [0]);

			return;
		}

		$existing = $query->get('post__in');
		if (\is_array($existing) && $existing !== []) {
			$existing = \array_map('intval', $existing);
			$postIds  = \array_values(\array_intersect($existing, $postIds));
		}

		if ($postIds === []) {
			$query->set('post__in', [0]);

			return;
		}

		$query->set('post__in', $postIds);
	}

	/**
	 * @param list<string> $requiredKeys
	 * @return list<int>
	 */
	private static function postIdsMatchingAllAmenityKeys(string $metaKey, array $requiredKeys): array
	{
		global $wpdb;

		$postType = \esc_sql(\BookingEngineConnector\PostTypes\UnitPostType::getSlug());
		$sql      = "SELECT p.ID, pm.meta_value
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID
			WHERE p.post_type = '{$postType}'
			AND p.post_status = 'publish'
			AND pm.meta_key = %s
			AND pm.meta_value <> ''";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- meta_key escaped via prepare
		$rows = $wpdb->get_results($wpdb->prepare($sql, $metaKey), \ARRAY_A);
		if (! \is_array($rows)) {
			return [];
		}

		$required = \array_fill_keys($requiredKeys, true);
		$matched  = [];

		foreach ($rows as $row) {
			$id = isset($row['ID']) ? (int) $row['ID'] : 0;
			if ($id < 1) {
				continue;
			}
			$items = self::decodeAmenitiesMeta($row['meta_value'] ?? '');
			if ($items === []) {
				continue;
			}
			$found = [];
			foreach ($items as $item) {
				$key = isset($item['key']) ? \sanitize_key((string) $item['key']) : '';
				if ($key !== '' && isset($required[ $key ])) {
					$found[ $key ] = true;
				}
			}
			if (\count($found) === \count($required)) {
				$matched[] = $id;
			}
		}

		return $matched;
	}

	/**
	 * @return list<array{key: string, labels?: array<string, string>, icon?: string, category?: string}>
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
