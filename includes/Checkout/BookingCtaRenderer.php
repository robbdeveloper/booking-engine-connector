<?php

declare(strict_types=1);

namespace BookingEngineConnector\Checkout;

use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;

/**
 * Renders availability summary + checkout link (TASK-CHK-002).
 */
final class BookingCtaRenderer
{
	/**
	 * @param mixed $quote Result from {@see QuoteService::getQuote()} (not WP_Error).
	 */
	public static function render(int $postId, SearchContext $ctx, $quote): string
	{
		if ($quote instanceof \WP_Error) {
			return (string) \apply_filters('bec_booking_error_notice_html', '', $quote, $postId, $ctx);
		}

		$urlData = CheckoutUrlService::buildForPost($postId, $ctx);

		\ob_start();
		echo '<div class="bec-booking-block">';

		if (\is_array($quote)) {
			$available = ! empty($quote['available']);
			if ($available) {
				echo '<p class="bec-booking-block__status bec-booking-block__status--ok">' . \esc_html__(
					'Available for your dates.',
					'booking-engine-connector'
				) . '</p>';
			} else {
				echo '<p class="bec-booking-block__status bec-booking-block__status--none">' . \esc_html__(
					'No availability for these dates.',
					'booking-engine-connector'
				) . '</p>';
			}
		}

		if ($urlData !== null && isset($urlData['url']) && (string) $urlData['url'] !== '') {
			$cta = CheckoutCtaHtml::renderCta($urlData);
			if ($cta !== '') {
				echo '<div class="bec-booking-block__cta">';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in CheckoutCtaHtml.
				echo $cta;
				echo '</div>';
			}
		}

		echo '</div>';

		$html = (string) \ob_get_clean();

		return (string) \apply_filters('bec_booking_cta_html', $html, $postId, $ctx, $quote, $urlData);
	}
}
