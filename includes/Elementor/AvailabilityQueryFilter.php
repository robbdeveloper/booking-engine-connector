<?php

declare(strict_types=1);

namespace BookingEngineConnector\Elementor;

use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\UnitFilters\UnitFilterQueryApplier;
use BookingEngineConnector\UnitFilters\UnitFilterRequest;
use BookingEngineConnector\UnitFilters\UnitListingAvailability;
use WP_Query;

/**
 * Elementor Loop Grid integration: a custom Query ID that filters out unit
 * posts without availability for the current search context.
 *
 * Usage:
 *   1. Edit a Loop Grid widget in Elementor.
 *   2. Open the "Query" section and set the "Query ID" field to
 *      `bec_available_only`, `bec_filtered_units`, or another id from
 *      `bec_elementor_unit_filter_query_ids`.
 *   3. Unit filter GET params (`bec_filter_*`) always narrow the loop.
 *      When search context is complete, units without availability for
 *      `bec_checkin` / `bec_checkout` / occupancy are removed.
 *
 * When no search context is present, only unit filters apply (if any);
 * availability pruning is skipped.
 */
final class AvailabilityQueryFilter
{
	/**
	 * Default Elementor Query ID that triggers the availability filter.
	 * Override via the `bec_elementor_availability_query_id` filter.
	 */
	public const DEFAULT_QUERY_ID = 'bec_available_only';

	public const FILTERED_UNITS_QUERY_ID = 'bec_filtered_units';

	public static function register(): void
	{
		foreach (self::getUnitFilterQueryIds() as $queryId) {
			if ($queryId === '') {
				continue;
			}
			\add_action('elementor/query/' . $queryId, [self::class, 'filterByAvailability']);
		}
	}

	public static function getQueryId(): string
	{
		$id = (string) \apply_filters('bec_elementor_availability_query_id', self::DEFAULT_QUERY_ID);

		return \sanitize_key($id);
	}

	/**
	 * Elementor Query IDs that apply unit filters and (when search is complete) availability.
	 *
	 * @return list<string>
	 */
	public static function getUnitFilterQueryIds(): array
	{
		$ids = [
			self::getQueryId(),
			self::FILTERED_UNITS_QUERY_ID,
		];

		/** @var list<string> $filtered */
		$filtered = (array) \apply_filters('bec_elementor_unit_filter_query_ids', $ids);

		$out = [];
		foreach ($filtered as $id) {
			$key = \sanitize_key((string) $id);
			if ($key !== '' && ! \in_array($key, $out, true)) {
				$out[] = $key;
			}
		}

		return $out;
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
		if (! UnitListingAvailability::enterListingScope()) {
			return;
		}

		try {
			$filterRequest = UnitFilterRequest::fromRequest();
			UnitFilterQueryApplier::apply($query, $filterRequest);

			$ctx = SearchContext::fromRequest();
			if (! UnitListingAvailability::shouldApplyAvailabilityPruning($ctx)) {
				if ($filterRequest->hasActiveFilters()) {
					UnitListingAvailability::restrictQueryToCandidates($query);
				}

				return;
			}

			$candidateIds = UnitListingAvailability::getCandidateIdsFromQuery($query);
			if ($candidateIds === []) {
				$query->set('post__in', [0]);

				return;
			}

			$availableIds = UnitListingAvailability::filterAvailableIds($candidateIds, $ctx);
			$availableIds = UnitListingAvailability::finalizeAvailableIds($availableIds, $candidateIds, $ctx, $query);

			UnitListingAvailability::restrictQueryToUnitIds($query, $availableIds);
		} finally {
			UnitListingAvailability::leaveListingScope();
		}
	}
}
