<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Contracts;

/**
 * Providers that can return all units for a search context in one remote call
 * (e.g. Kross {@see /v5/calendar/book} without `id_room_types`).
 *
 * {@see \BookingEngineConnector\Search\QuoteService} caches the batch once per
 * search context and slices per unit for archive loops.
 */
interface BulkQuoteProviderInterface
{
	/**
	 * Transient key for the full batch response (same TTL as per-quote cache).
	 *
	 * @param array<string, mixed> $searchContext After {@see bec_quote_search_context}
	 */
	public function getBulkQuoteCacheKey(array $searchContext): string;

	/**
	 * One API call returning availability for all bookable units in context.
	 *
	 * @param array<string, mixed> $searchContext
	 *
	 * @return mixed Provider-specific batch payload (must match {@see quoteFromBulk()})
	 */
	public function fetchBulkQuotes(array $searchContext): mixed;

	/**
	 * @param mixed                  $bulk          Value from {@see fetchBulkQuotes()} or transient
	 * @param array<string, mixed> $searchContext Same as for {@see fetchBulkQuotes()}
	 *
	 * @return mixed Same shape as {@see ProviderInterface::getQuoteForUnit()}
	 */
	public function quoteFromBulk(mixed $bulk, string $remoteUnitId, array $searchContext): mixed;
}
