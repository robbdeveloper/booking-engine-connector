<?php

declare(strict_types=1);

namespace BookingEngineConnector\Front;

use BookingEngineConnector\Checkout\BookingCtaRenderer;
use BookingEngineConnector\Fallback\FallbackRenderer;
use BookingEngineConnector\Fallback\FallbackService;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Units\UnitBookingMode;

/**
 * Appends booking CTA or fallback after unit content when search GET params are complete.
 */
final class PublicContentBlocks
{
	public static function register(): void
	{
		\add_filter('the_content', [self::class, 'appendBlocks'], 20);
	}

	public static function appendBlocks(string $content): string
	{
		if (! \is_singular()) {
			return $content;
		}
		if (! \in_the_loop() || ! \is_main_query()) {
			return $content;
		}
		if (\get_post_type() !== UnitPostType::getSlug()) {
			return $content;
		}
		$allow = (bool) \apply_filters(
			'bec_append_public_booking_blocks',
			PublicContentSettings::isAppendBookingBlocksToContentEnabled()
		);
		if (! $allow) {
			return $content;
		}

		$postId = (int) \get_the_ID();
		$ctx    = SearchContext::fromRequest();
		if (! $ctx->isComplete()) {
			return $content;
		}

		if (UnitBookingMode::isOnlyRequest($postId)) {
			$block = FallbackRenderer::render();
			if ($block === '') {
				return $content;
			}

			return $content . $block;
		}

		$quote = QuoteService::getQuote($postId, $ctx);

		if (FallbackService::shouldDisplay($quote)) {
			$block = FallbackRenderer::render();
		} else {
			$block = BookingCtaRenderer::render($postId, $ctx, $quote);
		}

		if ($block === '') {
			return $content;
		}

		return $content . $block;
	}
}
