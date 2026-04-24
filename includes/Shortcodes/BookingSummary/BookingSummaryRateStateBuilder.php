<?php

declare(strict_types=1);

namespace BookingEngineConnector\Shortcodes\BookingSummary;

use BookingEngineConnector\Checkout\CheckoutCtaHtml;
use BookingEngineConnector\Checkout\CheckoutUrlService;
use BookingEngineConnector\Search\CanonicalQuote;
use BookingEngineConnector\Search\SearchContext;

/**
 * Per-rate view snapshots for client-side rate switching (no extra provider calls).
 */
final class BookingSummaryRateStateBuilder
{
	/**
	 * @param array<string, mixed> $quote Enriched quote (same as initial page).
	 * @return array<string, array<string, mixed>> keyed by rate id.
	 */
	public static function buildMap(
		int $postId,
		SearchContext $ctx,
		string $providerSlug,
		array $quote,
		array $syncPayload,
		string $taxNoteDefault
	): array {
		$rates = $quote['rates'] ?? null;
		if ( ! \is_array( $rates ) || $rates === [] ) {
			return [];
		}

		$out = [];
		foreach ( $rates as $r ) {
			if ( ! \is_array( $r ) ) {
				continue;
			}
			$rid = (string) ( $r['id'] ?? '' );
			if ( $rid === '' ) {
				continue;
			}
			$ctxR  = $ctx->withRateId( $rid );
			$qR    = CanonicalQuote::applySelectionForContext( $quote, $ctxR );
			$vm    = BookingSummaryViewModelBuilder::build( $postId, $ctxR, $providerSlug, $qR, $syncPayload, $taxNoteDefault );
			$st    = (string) ( $vm['state'] ?? '' );
			if ( $st !== 'available' ) {
				continue;
			}
			$cur = (string) ( $vm['currency'] ?? '' );

			$urlData = CheckoutUrlService::buildForPost( $postId, $ctxR );
			if ( \is_array( $urlData ) && isset( $urlData['url'] ) ) {
				$urlData['label'] = (string) \apply_filters(
					'bec_booking_summary_continue_label',
					\__( 'Continue', 'booking-engine-connector' ),
					$postId,
					$ctxR
				);
			}
			$continueHtml = ( \is_array( $urlData ) && isset( $urlData['url'] ) && (string) $urlData['url'] !== '' )
				? CheckoutCtaHtml::renderCta( $urlData )
				: '';
			$checkout     = \is_array( $urlData ) ? $urlData : null;
			$snap         = [
				'selected_rate_id' => (string) ( $vm['selected_rate_id'] ?? $rid ),
				'head_html'        => BookingSummaryRenderer::getHeadPriceBlockInnerHtml( $vm ),
				'breakdown_html'   => BookingSummaryRenderer::getBreakdownInnerHtml( $vm, $cur ),
				'accordions_html'  => BookingSummaryRenderer::getAccordionsInnerHtml( $vm ),
				'bar_html'         => BookingSummaryRenderer::getBarAmountSpanHtml( $vm ),
				'continue_html'    => $continueHtml,
				'checkout'         => $checkout,
			];
			/** @var array<string, mixed> $snap */
			$snap         = (array) \apply_filters( 'bec_booking_summary_rate_state', $snap, $rid, $postId, $ctxR, $vm, $qR );
			$out[ $rid ] = $snap;
		}

		return $out;
	}
}
