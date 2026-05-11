<?php

declare(strict_types=1);

namespace BookingEngineConnector\Elementor;

use BookingEngineConnector\Fallback\FallbackService;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use WP_Query;

/**
 * Elementor Loop Grid integration: a custom Query ID that filters out unit
 * posts without availability for the current search context.
 *
 * Usage:
 *   1. Edit a Loop Grid widget in Elementor.
 *   2. Open the "Query" section and set the "Query ID" field to
 *      `bec_available_only` (or the value returned by the
 *      `bec_elementor_availability_query_id` filter).
 *   3. The widget will hide unit cards that have no availability for the
 *      current `bec_checkin` / `bec_checkout` / occupancy URL params.
 *
 * When no search context is present (e.g. a landing page without search
 * params), the filter does not run and the grid renders normally.
 */
final class AvailabilityQueryFilter
{
	/**
	 * Default Elementor Query ID that triggers the availability filter.
	 * Override via the `bec_elementor_availability_query_id` filter.
	 */
	public const DEFAULT_QUERY_ID = 'bec_available_only';

	/** @var int Re-entrancy guard (nested WP_Query must not recurse via Elementor's pre_get_posts). */
	private static int $filterDepth = 0;

	public static function register(): void
	{
		$queryId = self::getQueryId();
		if ($queryId === '') {
			return;
		}

		\add_action('elementor/query/' . $queryId, [self::class, 'filterByAvailability']);
	}

	public static function getQueryId(): string
	{
		$id = (string) \apply_filters('bec_elementor_availability_query_id', self::DEFAULT_QUERY_ID);

		return \sanitize_key($id);
	}

	/**
	 * Restrict the Elementor loop to only `bec_unit` posts that are available
	 * for the current search context.
	 */
	public static function filterByAvailability(WP_Query $query): void
	{
		/**
		 * Elementor wires custom Query IDs to {@see \pre_get_posts}. Calling
		 * {@see \get_posts()} / {@see \WP_Query} inside this callback retriggers
		 * that hook and causes unbounded recursion / memory exhaustion
		 * (see elementor/elementor#27513). A re-entrancy guard covers any nested
		 * queries; candidate IDs for grids without `post__in` are loaded via a
		 * suppressed secondary {@see \WP_Query} that omits Elementor's query id.
		 */
		if (self::$filterDepth > 0) {
			return;
		}

		++self::$filterDepth;
		try {
			$ctx = SearchContext::fromRequest();
			if (! $ctx->isComplete()) {
				return;
			}

			/**
			 * Globally forced fallback means the site is showing contact CTAs
			 * instead of bookable cards; in that case do not filter out units.
			 */
			if (FallbackService::isAlwaysOn()) {
				return;
			}

			$candidateIds = self::getCandidateUnitIds($query);
			if ($candidateIds === []) {
				$query->set('post__in', [0]);

				return;
			}

			$availableIds = self::filterAvailableIds($candidateIds, $ctx);
			$availableIds = \array_values(\array_map('intval', (array) \apply_filters(
				'bec_elementor_available_post_ids',
				$availableIds,
				$candidateIds,
				$ctx,
				$query
			)));

			$existing = $query->get('post__in');
			if (\is_array($existing) && $existing !== []) {
				$existing     = \array_map('intval', $existing);
				$availableIds = \array_values(\array_intersect($existing, $availableIds));
			}

			$excluded = $query->get('post__not_in');
			if (\is_array($excluded) && $excluded !== []) {
				$excluded     = \array_map('intval', $excluded);
				$availableIds = \array_values(\array_diff($availableIds, $excluded));
			}

			if ($availableIds === []) {
				$query->set('post__in', [0]);

				return;
			}

			$query->set('post__in', $availableIds);
			$query->set('ignore_sticky_posts', true);
		} finally {
			--self::$filterDepth;
		}
	}

	/**
	 * Universe of unit post IDs to check for availability.
	 *
	 * When Elementor already restricts the loop with `post__in`, that list is
	 * reused (filtered to published units with a non-empty `bec_external_id`).
	 * Otherwise a secondary query copies the Loop Grid's type/tax/meta/etc.
	 * constraints so availability is evaluated only for posts the grid would
	 * normally include.
	 *
	 * @return list<int>
	 */
	private static function getCandidateUnitIds(WP_Query $query): array
	{
		$existing = $query->get('post__in');
		if (\is_array($existing) && $existing !== []) {
			return self::filterUnitIdsWithExternalId($existing);
		}

		return self::discoverCandidateIdsViaSubquery($query);
	}

	/**
	 * @param list<int> $postIds
	 * @return list<int>
	 */
	private static function filterUnitIdsWithExternalId(array $postIds): array
	{
		global $wpdb;

		$ids = [];
		foreach ($postIds as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		$ids = \array_values(\array_unique($ids));
		if ($ids === []) {
			return [];
		}

		$inList   = \implode(',', $ids);
		$postType = \esc_sql(UnitPostType::getSlug());
		$sql      = "SELECT DISTINCT p.ID
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID
			WHERE p.ID IN ({$inList})
			AND p.post_type = '{$postType}'
			AND p.post_status = 'publish'
			AND pm.meta_key = 'bec_external_id'
			AND pm.meta_value <> ''";

		$col = $wpdb->get_col($sql);
		if (! \is_array($col)) {
			return [];
		}

		return \array_values(\array_map('intval', $col));
	}

	/**
	 * Runs a non-Elementor {@see WP_Query} with the same core constraints as the
	 * Loop Grid query so taxonomy/meta filters still apply.
	 *
	 * @return list<int>
	 */
	private static function discoverCandidateIdsViaSubquery(WP_Query $query): array
	{
		$limit = (int) \apply_filters('bec_elementor_availability_max_units', 500, $query);
		$limit = $limit > 0 ? $limit : 500;

		$postType = $query->get('post_type');
		if ($postType === '' || $postType === null) {
			$postType = UnitPostType::getSlug();
		}

		$postStatus = $query->get('post_status');
		if ($postStatus === '' || $postStatus === null) {
			$postStatus = 'publish';
		}

		$args = [
			'post_type'              => $postType,
			'post_status'            => $postStatus,
			'meta_query'             => self::mergeExternalIdMetaQuery($query->get('meta_query')),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
			'orderby'                => $query->get('orderby'),
			'order'                  => $query->get('order'),
		];

		$taxQuery = $query->get('tax_query');
		if (\is_array($taxQuery) && $taxQuery !== []) {
			$args['tax_query'] = $taxQuery;
		}

		$postNotIn = $query->get('post__not_in');
		if (\is_array($postNotIn) && $postNotIn !== []) {
			$args['post__not_in'] = $postNotIn;
		}

		$postParent = $query->get('post_parent');
		if ($postParent !== '' && $postParent !== null && $postParent !== false) {
			$args['post_parent'] = $postParent;
		}

		$author = $query->get('author');
		if ($author !== '' && $author !== null && (int) $author !== 0) {
			$args['author'] = (int) $author;
		}

		$authorIn = $query->get('author__in');
		if (\is_array($authorIn) && $authorIn !== []) {
			$args['author__in'] = $authorIn;
		}

		$authorNotIn = $query->get('author__not_in');
		if (\is_array($authorNotIn) && $authorNotIn !== []) {
			$args['author__not_in'] = $authorNotIn;
		}

		$dateQuery = $query->get('date_query');
		if (\is_array($dateQuery) && $dateQuery !== []) {
			$args['date_query'] = $dateQuery;
		}

		++self::$filterDepth;
		try {
			$sub = new WP_Query($args);
		} finally {
			--self::$filterDepth;
		}

		$postIds = [];
		if (isset($sub->posts) && \is_array($sub->posts)) {
			foreach ($sub->posts as $id) {
				$postIds[] = (int) $id;
			}
		}

		if (\count($postIds) > $limit) {
			$postIds = \array_slice($postIds, 0, $limit);
		}

		return $postIds;
	}

	/**
	 * @param array<string|int, mixed>|null $existing
	 * @return array<string|int, mixed>
	 */
	private static function mergeExternalIdMetaQuery($existing): array
	{
		$externalBranch = [
			'relation' => 'AND',
			[
				'key'     => 'bec_external_id',
				'compare' => 'EXISTS',
			],
			[
				'key'     => 'bec_external_id',
				'value'   => '',
				'compare' => '!=',
			],
		];

		if (! \is_array($existing) || $existing === []) {
			return $externalBranch;
		}

		return [
			'relation' => 'AND',
			$existing,
			$externalBranch,
		];
	}

	/**
	 * @param list<int>     $candidateIds
	 * @return list<int>
	 */
	private static function filterAvailableIds(array $candidateIds, SearchContext $ctx): array
	{
		$available = [];
		foreach ($candidateIds as $postId) {
			$quote = QuoteService::getQuote($postId, $ctx);

			if ($quote instanceof \WP_Error) {
				continue;
			}
			if (! \is_array($quote)) {
				continue;
			}
			if (empty($quote['available'])) {
				continue;
			}

			$available[] = $postId;
		}

		return $available;
	}
}
