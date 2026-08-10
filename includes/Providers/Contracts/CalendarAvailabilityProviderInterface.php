<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Contracts;

/**
 * Providers that expose bulk PMS calendar availability for date-picker hints.
 *
 * {@see \BookingEngineConnector\Search\CalendarAvailabilityService} caches the batch
 * response and derives per-unit or site-wide unavailable date ranges for the search UI.
 */
interface CalendarAvailabilityProviderInterface
{
	/**
	 * Transient key for the full batch response.
	 */
	public function getBulkAvailabilityCacheKey(): string;

	/**
	 * One API call returning availability segments for all bookable units in the horizon.
	 *
	 * @return mixed Provider-specific batch payload (must match {@see normalizeBulkAvailability()})
	 */
	public function fetchBulkAvailability(): mixed;

	/**
	 * Normalize a bulk payload to provider-agnostic segments.
	 *
	 * @return list<array{remote_unit_id: string, date_from: string, date_to: string, available: bool, minimum_stay?: int|null}>
	 */
	public function normalizeBulkAvailability(mixed $bulk): array;
}
