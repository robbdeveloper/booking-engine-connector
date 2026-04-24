<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Contracts;

/**
 * How the public search form collects occupancy.
 */
final class SearchGuestFieldMode
{
	/** Adults + children steppers; optional per-child ages when {@see ProviderInterface::requiresChildrenAges()} is true. */
	public const BREAKDOWN = 'breakdown';

	/** Single guest / pax count (stored as {@see \BookingEngineConnector\Search\SearchContext::PARAM_TOTAL_GUESTS}). */
	public const TOTAL = 'total';
}
