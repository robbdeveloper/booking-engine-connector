<?php

declare(strict_types=1);

use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Search\SearchForm;

/**
 * Renders the public search form (TASK-SEA-002).
 *
 * @param array<string, mixed> $args Passed to {@see SearchForm::render()}.
 */
function bec_render_search_form(array $args = []): void
{
	SearchForm::render($args);
}

/**
 * Returns a quote for a unit post or WP_Error (TASK-SEA-003).
 *
 * @return mixed|\WP_Error
 */
function bec_get_unit_quote(int $postId, ?SearchContext $context = null)
{
	return QuoteService::getQuote($postId, $context);
}
