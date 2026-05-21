<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\Fallback\FallbackService;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use WP_Query;

/**
 * Shared candidate discovery and availability pruning for unit listings
 * (Elementor Loop Grid, shortcode counts, native archives).
 */
final class UnitListingAvailability
{
	/** @var int Re-entrancy guard for nested {@see WP_Query} during Elementor {@see pre_get_posts}. */
	private static int $queryDepth = 0;

	public static function isNestedQuery(): bool
	{
		return self::$queryDepth > 0;
	}

	/**
	 * Enter a listing filter scope (Elementor {@see pre_get_posts}); returns false if already nested.
	 */
	public static function enterListingScope(): bool
	{
		if (self::$queryDepth > 0) {
			return false;
		}

		++self::$queryDepth;

		return true;
	}

	public static function leaveListingScope(): void
	{
		if (self::$queryDepth > 0) {
			--self::$queryDepth;
		}
	}

	/**
	 * @param list<int> $postIds
	 * @return list<int>
	 */
	public static function filterUnitIdsWithExternalId(array $postIds): array
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
	 * @param array<string|int, mixed>|null $existing
	 * @return array<string|int, mixed>
	 */
	public static function mergeExternalIdMetaQuery($existing): array
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
	 * Candidate unit IDs for a loop or listing query (published units with external id).
	 *
	 * @return list<int>
	 */
	public static function getCandidateIdsFromQuery(WP_Query $query): array
	{
		$existing = $query->get('post__in');
		if (\is_array($existing) && $existing !== []) {
			return self::filterUnitIdsWithExternalId($existing);
		}

		return self::discoverCandidateIdsViaSubquery($query);
	}

	/**
	 * Runs a non-Elementor {@see WP_Query} with the same core constraints as the source query.
	 *
	 * @return list<int>
	 */
	public static function discoverCandidateIdsViaSubquery(WP_Query $query): array
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

		$postIds = self::runSuppressedIdQuery($args);

		if (\count($postIds) > $limit) {
			$postIds = \array_slice($postIds, 0, $limit);
		}

		return $postIds;
	}

	/**
	 * @param array<string, mixed> $args {@see WP_Query} arguments with `fields` => `ids`.
	 * @return list<int>
	 */
	public static function runSuppressedIdQuery(array $args): array
	{
		++self::$queryDepth;
		try {
			$sub = new WP_Query($args);
		} finally {
			--self::$queryDepth;
		}

		$postIds = [];
		if (isset($sub->posts) && \is_array($sub->posts)) {
			foreach ($sub->posts as $id) {
				$postIds[] = (int) $id;
			}
		}

		return $postIds;
	}

	/**
	 * @param list<int> $candidateIds
	 * @return list<int>
	 */
	public static function filterAvailableIds(array $candidateIds, SearchContext $ctx): array
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

	/**
	 * Whether availability pruning should run for the current request.
	 */
	public static function shouldApplyAvailabilityPruning(?SearchContext $ctx = null): bool
	{
		$ctx = $ctx ?? SearchContext::fromRequest();

		return $ctx->isComplete() && ! FallbackService::isAlwaysOn();
	}

	/**
	 * Available unit post IDs after unit filters and optional availability pruning.
	 *
	 * @param WP_Query|null $loopQuery Optional loop/archive query (Elementor grid constraints).
	 * @return list<int>
	 */
	public static function getAvailableUnitIds(?WP_Query $loopQuery = null): array
	{
		$filterRequest = UnitFilterRequest::fromRequest();
		$ctx           = SearchContext::fromRequest();

		if ($loopQuery instanceof WP_Query) {
			$working = clone $loopQuery;
		} else {
			$working = new WP_Query(self::defaultListingQueryVars());
		}

		UnitFilterQueryApplier::apply($working, $filterRequest);

		if (! self::shouldApplyAvailabilityPruning($ctx)) {
			return self::getCandidateIdsFromQuery($working);
		}

		$candidateIds = self::getCandidateIdsFromQuery($working);
		if ($candidateIds === []) {
			return [];
		}

		$availableIds = self::filterAvailableIds($candidateIds, $ctx);

		return \array_values(\array_map('intval', (array) \apply_filters(
			'bec_available_unit_ids',
			$availableIds,
			$candidateIds,
			$ctx,
			$loopQuery
		)));
	}

	/**
	 * Base vars for a full unit listing (no Elementor-specific constraints).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaultListingQueryVars(): array
	{
		return [
			'post_type'              => UnitPostType::getSlug(),
			'post_status'            => 'publish',
			'meta_query'             => self::mergeExternalIdMetaQuery(null),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		];
	}
}
