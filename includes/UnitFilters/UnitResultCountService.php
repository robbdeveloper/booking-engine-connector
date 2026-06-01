<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;
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
	 * @param WP_Query|null $loopQuery      Optional in-loop query; used only when availability pruning is off and the query is a meaningful unit loop.
	 * @param string        $categorySlug   Optional unit category term slug; scopes the count when set.
	 */
	public static function getCount(?WP_Query $loopQuery = null, string $categorySlug = ''): int
	{
		$categorySlug = \sanitize_title(\trim($categorySlug));

		if ($loopQuery !== null && ! self::isMeaningfulUnitLoopQuery($loopQuery)) {
			$loopQuery = null;
		}

		$loopQuery = self::resolveListingQuery($loopQuery, $categorySlug);

		$key = self::cacheKey($loopQuery, $categorySlug);
		if (isset(self::$cache[ $key ])) {
			return self::$cache[ $key ];
		}

		$count = self::computeCount($loopQuery, $categorySlug !== '');
		self::$cache[ $key ] = $count;

		/**
		 * @param int       $count
		 * @param WP_Query|null $loopQuery
		 */
		$count = (int) \apply_filters('bec_available_units_count', $count, $loopQuery);

		self::$cache[ $key ] = $count;

		return $count;
	}

	private static function computeCount(?WP_Query $loopQuery, bool $categoryOverride = false): int
	{
		if (
			! $categoryOverride
			&& $loopQuery instanceof WP_Query
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

		if ($query->is_tax(UnitCategoryTaxonomy::TAXONOMY) && $query->is_main_query()) {
			return true;
		}

		$postType = $query->get('post_type');
		if ($postType === $slug || $postType === [ $slug ]) {
			return (int) $query->found_posts >= 0;
		}

		return false;
	}

	/**
	 * Build the working listing query, optionally scoped to a unit category term.
	 */
	private static function resolveListingQuery(?WP_Query $loopQuery, string $categorySlug): ?WP_Query
	{
		if ($categorySlug === '') {
			return $loopQuery;
		}

		if ($loopQuery instanceof WP_Query) {
			$working = clone $loopQuery;
		} else {
			$working = new WP_Query(UnitListingAvailability::defaultListingQueryVars());
		}

		self::applyUnitCategorySlug($working, $categorySlug);

		return $working;
	}

	private static function applyUnitCategorySlug(WP_Query $query, string $categorySlug): void
	{
		$term = \get_term_by('slug', $categorySlug, UnitCategoryTaxonomy::TAXONOMY);
		if (! $term instanceof \WP_Term) {
			$query->set('post__in', [0]);

			return;
		}

		$branch = [
			'taxonomy'         => UnitCategoryTaxonomy::TAXONOMY,
			'field'            => 'slug',
			'terms'            => [ $categorySlug ],
			'include_children' => true,
		];

		$existing = $query->get('tax_query');
		if (! \is_array($existing) || $existing === []) {
			$query->set('tax_query', [ $branch ]);

			return;
		}

		$merged   = [];
		$replaced = false;
		foreach ($existing as $key => $clause) {
			if ($key === 'relation' || ! \is_array($clause)) {
				$merged[ $key ] = $clause;
				continue;
			}
			if (($clause['taxonomy'] ?? '') === UnitCategoryTaxonomy::TAXONOMY) {
				$merged[] = $branch;
				$replaced = true;
				continue;
			}
			$merged[] = $clause;
		}

		if (! $replaced) {
			$merged[] = $branch;
		}

		if (! isset($merged['relation'])) {
			$merged = \array_merge(['relation' => 'AND'], $merged);
		}

		$query->set('tax_query', $merged);
	}

	private static function cacheKey(?WP_Query $loopQuery, string $categorySlug = ''): string
	{
		$filterRequest = UnitFilterRequest::fromRequest();
		$ctx           = SearchContext::fromRequest();

		$payload = [
			'filters'     => $filterRequest->toQueryArgs(),
			'search'      => $ctx->toQueryArgs(),
			'prune'       => UnitListingAvailability::shouldApplyAvailabilityPruning($ctx),
			'category'    => $categorySlug,
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
