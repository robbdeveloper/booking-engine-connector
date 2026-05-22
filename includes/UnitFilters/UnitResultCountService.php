<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\SearchContext;
use WP_Query;

/**
 * Counts units matching current unit filters and (when search is complete) availability.
 */
final class UnitResultCountService
{
	/** @var array<string, int> */
	private static array $cache = [];

	/**
	 * Number of units that would appear in a BEC-filtered listing for this request.
	 *
	 * @param WP_Query|null $loopQuery Optional in-loop query; used only when availability pruning is off and the query is a meaningful unit loop.
	 */
	public static function getCount(?WP_Query $loopQuery = null): int
	{
		if ($loopQuery !== null && ! self::isMeaningfulUnitLoopQuery($loopQuery)) {
			$loopQuery = null;
		}

		$key = self::cacheKey($loopQuery);
		if (isset(self::$cache[ $key ])) {
			return self::$cache[ $key ];
		}

		$count = self::computeCount($loopQuery);
		self::$cache[ $key ] = $count;

		/**
		 * @param int       $count
		 * @param WP_Query|null $loopQuery
		 */
		$count = (int) \apply_filters('bec_available_units_count', $count, $loopQuery);

		self::$cache[ $key ] = $count;

		return $count;
	}

	private static function computeCount(?WP_Query $loopQuery): int
	{
		if (
			$loopQuery instanceof WP_Query
			&& ! UnitListingAvailability::shouldApplyAvailabilityPruning()
			&& self::isMeaningfulUnitLoopQuery($loopQuery)
		) {
			return \max(0, (int) $loopQuery->found_posts);
		}

		$ids = UnitListingAvailability::getAvailableUnitIds($loopQuery);

		return \count($ids);
	}

	private static function isMeaningfulUnitLoopQuery(WP_Query $query): bool
	{
		$slug = UnitPostType::getSlug();

		if ($query->is_post_type_archive($slug) && $query->is_main_query()) {
			return true;
		}

		$postType = $query->get('post_type');
		if ($postType === $slug || $postType === [ $slug ]) {
			return (int) $query->found_posts >= 0;
		}

		return false;
	}

	private static function cacheKey(?WP_Query $loopQuery): string
	{
		$filterRequest = UnitFilterRequest::fromRequest();
		$ctx           = SearchContext::fromRequest();

		$payload = [
			'filters'     => $filterRequest->toQueryArgs(),
			'search'      => $ctx->toQueryArgs(),
			'prune'       => UnitListingAvailability::shouldApplyAvailabilityPruning($ctx),
			'loop_sig'    => $loopQuery instanceof WP_Query ? self::loopQuerySignature($loopQuery) : '',
		];

		return \md5((string) \wp_json_encode($payload));
	}

	private static function loopQuerySignature(WP_Query $query): string
	{
		$parts = [
			'post__in'   => $query->get('post__in'),
			'post__not_in' => $query->get('post__not_in'),
			'tax_query'  => $query->get('tax_query'),
			'meta_query' => $query->get('meta_query'),
			'orderby'    => $query->get('orderby'),
			'order'      => $query->get('order'),
		];

		return \md5((string) \wp_json_encode($parts));
	}
}
