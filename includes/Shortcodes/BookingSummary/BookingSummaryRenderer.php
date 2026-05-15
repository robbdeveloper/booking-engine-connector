<?php

declare(strict_types=1);

namespace BookingEngineConnector\Shortcodes\BookingSummary;

use BookingEngineConnector\Checkout\CheckoutCtaHtml;
use BookingEngineConnector\Checkout\CheckoutUrlService;
use BookingEngineConnector\Fallback\FallbackRenderer;
use BookingEngineConnector\Fallback\FallbackService;
use BookingEngineConnector\Fallback\FallbackSettings;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Search\SearchForm;
use BookingEngineConnector\Styling\StylingSettings;
use BookingEngineConnector\Sync\JsonExtensionFlags;
use BookingEngineConnector\Sync\SyncPayloadEncoder;

/**
 * Renders the booking summary / sidebar shortcode.
 */
final class BookingSummaryRenderer
{
	/**
	 * @param array<string, string> $a Shortcode atts
	 */
	public static function render( array $a ): string {
		$postId = (int) ( $a['unit_id'] ?? 0 );
		if ( $postId < 1 ) {
			$postId = (int) \get_the_ID();
		}
		if ( $postId < 1 || \get_post_type( $postId ) !== UnitPostType::getSlug() ) {
			return '';
		}

		$ctx     = SearchContext::fromRequest();
		$slug    = (string) \get_post_meta( $postId, 'bec_provider_slug', true );
		$slug    = $slug !== '' ? $slug : ProviderRegistry::getActiveSlug();

		$taxNote = \trim( (string) ( $a['tax_note'] ?? '' ) );
		if ( $taxNote === '' ) {
			$taxNote = (string) \apply_filters(
				'bec_booking_summary_default_tax_note',
				\__( 'City tax not included', 'booking-engine-connector' ),
				$postId,
				$ctx
			);
		}

		$syncJson = (string) \get_post_meta( $postId, 'bec_sync_payload', true );
		$syncPayload = $syncJson !== '' ? ( SyncPayloadEncoder::decodeStored( $syncJson ) ?? [] ) : [];

		$instanceId  = (string) ( $a['form_id'] ?? 'bec-booking-summary' ) . '-uid-' . (string) ( $postId );
		$layout      = self::resolveLayoutPreset( $postId, $ctx );
		$rootClasses = (string) \apply_filters(
			'bec_booking_summary_root_class',
			'bec-booking-summary',
			$postId,
			$ctx
		);
		if ( $layout === StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT ) {
			$rootClasses .= ' bec-booking-summary--preset-compact';
		}
		$ctxArg      = (string) ( $a['context'] ?? 'bec_booking_summary' );

		$showEnquiry    = \in_array( \strtolower( (string) ( $a['show_enquiry'] ?? '1' ) ), [ '1', 'true', 'yes' ], true );
		$enquiryDefault = (string) \get_option( FallbackSettings::OPTION_LINK_TEXT, \__( 'Contact us', 'booking-engine-connector' ) );
		$enquiryLabel   = (string) ( $a['enquiry_label'] ?? \__( 'Enquiry', 'booking-engine-connector' ) );
		if ( $enquiryLabel === '' ) {
			$enquiryLabel = $enquiryDefault;
		}

		\ob_start();

		if ( FallbackService::isAlwaysOn() ) {
			self::printRootOpen( $instanceId, $rootClasses, $postId, 'fallback' );
			self::renderFallbackOnly();
		} elseif ( ! $ctx->isComplete() ) {
			self::printRootOpen( $instanceId, $rootClasses, $postId, 'incomplete' );
			self::renderIncomplete(
				$postId,
				$ctx,
				$instanceId,
				$ctxArg,
				$showEnquiry,
				$enquiryLabel,
				$slug
			);
		} else {
			$quote = QuoteService::getQuote( $postId, $ctx );
			if ( $quote instanceof \WP_Error ) {
				self::printRootOpen( $instanceId, $rootClasses, $postId, 'error' );
				$vm = BookingSummaryViewModelBuilder::build( $postId, $ctx, $slug, $quote, $syncPayload, $taxNote );
				self::renderErrorOrUnavailable( $vm, $postId, $ctx, $instanceId, $ctxArg, $showEnquiry, $enquiryLabel );
			} elseif ( FallbackService::shouldDisplay( $quote ) ) {
				self::printRootOpen( $instanceId, $rootClasses, $postId, 'fallback' );
				self::renderFallbackOnly();
			} else {
				$vm = BookingSummaryViewModelBuilder::build( $postId, $ctx, $slug, $quote, $syncPayload, $taxNote );
				$st = (string) ( $vm['state'] ?? 'unavailable' );
				if ( $st === 'unavailable' || $st === 'error' ) {
					self::printRootOpen(
						$instanceId,
						$rootClasses,
						$postId,
						$st === 'error' ? 'error' : 'unavailable'
					);
					self::renderErrorOrUnavailable( $vm, $postId, $ctx, $instanceId, $ctxArg, $showEnquiry, $enquiryLabel );
				} else {
					self::printRootOpen( $instanceId, $rootClasses, $postId, 'available' );
					$rateStateMap = [];
					if ( \is_array( $quote )
						&& ! empty( $vm['show_rate_list'] )
						&& \is_array( $vm['rates'] ?? null )
						&& \count( (array) ( $vm['rates'] ?? [] ) ) > 1
					) {
						$rateStateMap = BookingSummaryRateStateBuilder::buildMap(
							$postId,
							$ctx,
							$slug,
							$quote,
							$syncPayload,
							$taxNote
						);
					}
					self::renderAvailable(
						$vm,
						$postId,
						$ctx,
						$instanceId,
						$ctxArg,
						$showEnquiry,
						$enquiryLabel,
						$quote,
						$rateStateMap,
						$layout
					);
				}
			}
		}

		echo '</div>';

		$html = (string) \ob_get_clean();

		return (string) \apply_filters( 'bec_booking_summary_html', $html, $postId, $ctx, $a );
	}

	/**
	 * @param array<string, mixed> $a
	 */
	public static function renderFromShortcode( $a ): string {
		$atts = \is_array( $a ) ? $a : [];
		$out  = \shortcode_atts(
			[
				'unit_id'        => '0',
				'form_id'        => 'bec-booking-summary',
				'context'        => 'bec_booking_summary',
				'tax_note'       => '',
				'show_enquiry'   => '1',
				'enquiry_label'  => \__( 'Enquiry', 'booking-engine-connector' ),
			],
			$atts,
			'bec_booking_summary'
		);
		/** @var array<string, string> $out */

		return self::render( $out );
	}

	/**
	 * @return string Layout preset slug.
	 */
	private static function resolveLayoutPreset( int $postId, SearchContext $ctx ): string {
		$raw = StylingSettings::getBookingSummaryPreset();
		/** @var string $preset */
		$preset = \apply_filters( 'bec_booking_summary_layout_preset', $raw, $postId, $ctx );
		$preset = \sanitize_key( (string) $preset );

		return $preset === StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT
			? StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT
			: StylingSettings::BOOKING_SUMMARY_PRESET_DEFAULT;
	}

	/**
	 * @param array<string, mixed> $vm May be empty (incomplete context — header shows title only).
	 */
	private static function printRootOpen( string $instanceId, string $rootClasses, int $postId, string $uiState ): void {
		$uiState = \sanitize_key( $uiState );
		// Root id must differ from the embedded search form id ($instanceId) or getElementById
		// would resolve the div and submission from the footer button would silently no-op.
		echo '<div id="' . \esc_attr( $instanceId . '-root' ) . '" class="' . \esc_attr( $rootClasses ) . '" data-bec-booking-summary data-bec-post-id="' . (int) $postId . '" data-bec-bsummary-ui-state="' . \esc_attr( $uiState ) . '">';
	}

	private static function printSummaryHead( array $vm ): void {
		echo '<header class="bec-booking-summary__head">';
		echo '<span class="bec-booking-summary__title">' . \esc_html__( 'Summary', 'booking-engine-connector' ) . '</span>';
		$headInner = self::getHeadPriceBlockInnerHtml( $vm );
		if ( $headInner !== '' ) {
			echo '<div class="bec-booking-summary__head-price" data-bec-bsummary-head data-bec-bsummary-head-price>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $headInner;
			echo '</div>';
		}
		echo '</header>';
	}

	private static function checkAvailabilityButtonHtml( int $postId, SearchContext $ctx, string $targetFormId, bool $disabled = false ): string {
		$label = (string) \apply_filters(
			'bec_booking_summary_check_availability_label',
			\__( 'Check availability', 'booking-engine-connector' ),
			$postId,
			$ctx
		);

		$attrs = 'type="button" data-bec-submit-search-form="' . \esc_attr( $targetFormId ) . '" class="bec-booking-summary__check-availability bec-checkout-cta"';
		if ( $disabled ) {
			$attrs .= ' disabled="disabled" aria-disabled="true"';
		}

		return '<button ' . $attrs . '>'
			. \esc_html( $label )
			. '</button>';
	}

	/**
	 * User-facing copy when the quote is available=false (not WP_Error).
	 */
	private static function getUnavailableMessageText( int $postId, SearchContext $ctx ): string {
		$msg = (string) \apply_filters(
			'bec_booking_summary_unavailable_message',
			\__( 'No solution available for the dates and guests indicated.', 'booking-engine-connector' ),
			$postId,
			$ctx
		);

		return $msg;
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	private static function renderErrorOrUnavailable(
		array $vm,
		int $postId,
		SearchContext $ctx,
		string $instanceId,
		string $ctxArg,
		bool $showEnquiry,
		string $enquiryLabel
	): void {
		$st = (string) ( $vm['state'] ?? 'unavailable' );
		echo '<div class="bec-booking-summary__inner">';
		self::printSummaryHead( $vm );
		self::printSearch( $ctxArg, $instanceId, $ctx );
		echo '<div class="bec-booking-summary__message bec-booking-summary__message--' . ( $st === 'error' ? 'error' : 'empty' ) . '">';
		if ( $st === 'error' && isset( $vm['error'] ) && $vm['error'] instanceof \WP_Error ) {
			echo '<p class="bec-booking-summary__message-text" role="alert">' . \esc_html( $vm['error']->get_error_message() ) . '</p>';
		} else {
			echo '<p class="bec-booking-summary__message-text">' . \esc_html( self::getUnavailableMessageText( $postId, $ctx ) ) . '</p>';
		}
		echo '</div>';
		self::renderActions( $postId, $ctx, $showEnquiry, $enquiryLabel, false, $instanceId, null, true, true );
		echo '</div>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::printMobileShell( $vm, $postId, $ctx, $ctxArg, $instanceId, $showEnquiry, $enquiryLabel, null, 'error-or-empty', true );
	}

	/**
	 * Fallback replaces the full summary UI (no embedded search, enquiry row, or mobile shell).
	 */
	private static function renderFallbackOnly(): void {
		echo '<div class="bec-booking-summary__inner bec-booking-summary__inner--fallback">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo FallbackRenderer::render();
		echo '</div>';
	}

	private static function renderIncomplete(
		int $postId,
		SearchContext $ctx,
		string $instanceId,
		string $ctxArg,
		bool $showEnquiry,
		string $enquiryLabel,
		string $providerSlug
	): void {
		echo '<div class="bec-booking-summary__inner bec-booking-summary__inner--incomplete">';
		self::printSummaryHead( [] );
		self::printSearch( $ctxArg, $instanceId, $ctx, 'bec-booking-summary__search--incomplete' );
		self::renderActions( $postId, $ctx, $showEnquiry, $enquiryLabel, false, $instanceId, null, true, true );
		echo '</div>';
	}

	/**
	 * @param array<string, mixed>         $vm
	 * @param array<string, mixed>         $quote
	 * @param array<string, array<string, mixed>> $rateStateMap
	 * @param string                              $layoutPreset
	 */
	private static function renderAvailable(
		array $vm,
		int $postId,
		SearchContext $ctx,
		string $instanceId,
		string $ctxArg,
		bool $showEnquiry,
		string $enquiryLabel,
		array $quote,
		array $rateStateMap = [],
		string $layoutPreset = ''
	): void {
		if ( $layoutPreset === '' ) {
			$layoutPreset = self::resolveLayoutPreset( $postId, $ctx );
		}
		$urlData = CheckoutUrlService::buildForPost( $postId, $ctx );
		if ( \is_array( $urlData ) && isset( $urlData['url'] ) ) {
			$urlData['label'] = (string) \apply_filters(
				'bec_booking_summary_continue_label',
				\__( 'Continue', 'booking-engine-connector' ),
				$postId,
				$ctx
			);
		}

		echo '<div class="bec-booking-summary__inner">';

		$isCompact = $layoutPreset === StylingSettings::BOOKING_SUMMARY_PRESET_COMPACT;

		echo '<div class="bec-booking-summary__desktop">';

		$cur = (string) ( $vm['currency'] ?? '' );

		self::printSummaryHead( $vm );

		$desktopSearchClass = 'bec-booking-summary__search--desktop';
		if ( $isCompact ) {
			$desktopSearchClass .= ' bec-booking-summary__search--preset-compact';
		}
		self::printSearch( $ctxArg, $instanceId, $ctx, $desktopSearchClass );

		echo '<div class="bec-booking-summary__quote-results" data-bec-bsummary-quote-results>';
		//self::printDatesGuestsBlock( $vm );
		self::printRateList( $vm, $postId, $ctx );
		self::printAccordions( $vm );
		self::printPriceBreakdown( $vm, $cur );
		echo '</div>';

		$hasCheckoutUrl = \is_array( $urlData ) && isset( $urlData['url'] ) && (string) $urlData['url'] !== '';
		$checkAvailDisabled = ! $hasCheckoutUrl;
		$retryFormId        = $hasCheckoutUrl ? $instanceId : null;
		self::renderActions( $postId, $ctx, $showEnquiry, $enquiryLabel, $hasCheckoutUrl, $hasCheckoutUrl ? null : $instanceId, $urlData, true, $checkAvailDisabled, $retryFormId );

		echo '</div>'; // desktop

		echo '</div>'; // inner

		self::printMobileShell( $vm, $postId, $ctx, $ctxArg, $instanceId, $showEnquiry, $enquiryLabel, $urlData, 'available', $checkAvailDisabled );

		if ( $rateStateMap !== [] ) {
			$defRate = (string) ( $vm['selected_rate_id'] ?? $ctx->getRateId() );
			$payload = [
				'paramRate'   => SearchContext::PARAM_RATE_ID,
				'defaultRate' => $defRate,
				'states'      => $rateStateMap,
			];
			$json    = (string) \wp_json_encode(
				$payload,
				JsonExtensionFlags::hexTag()
					| JsonExtensionFlags::hexAmp()
					| JsonExtensionFlags::hexApos()
					| JsonExtensionFlags::unescapedSlashes()
			);
			if ( $json !== 'null' && $json !== 'false' ) {
				echo '<script type="application/json" class="bec-booking-summary__state" data-bec-bsummary-state>' . $json . '</script>';
			}
		}
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	private static function printDatesGuestsBlock( array $vm ): void {
		$in  = (string) ( $vm['checkin'] ?? '' );
		$out = (string) ( $vm['checkout'] ?? '' );
		$gl  = (string) ( $vm['guests_line'] ?? '' );

		echo '<div class="bec-booking-summary__readouts" role="group" aria-label="' . \esc_attr__( 'Search selection', 'booking-engine-connector' ) . '">';
		echo '<div class="bec-booking-summary__readout-row bec-booking-summary__readout-row--dates">';
		echo '<span class="screen-reader-text">' . \esc_html__( 'Stay dates', 'booking-engine-connector' ) . '</span>';
		echo '<span class="bec-booking-summary__readout bec-booking-summary__readout--in">' . \esc_html( self::formatShortDate( $in ) ) . '</span>';
		echo '<span class="bec-booking-summary__readout-sep" aria-hidden="true">→</span>';
		echo '<span class="bec-booking-summary__readout bec-booking-summary__readout--out">' . \esc_html( self::formatShortDate( $out ) ) . '</span>';
		echo '</div>';
		if ( $gl !== '' ) {
			echo '<div class="bec-booking-summary__readout-row bec-booking-summary__readout-row--guests">';
			echo \esc_html( $gl );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	private static function printAccordions( array $vm ): void {
		$inner = self::getAccordionsInnerHtml( $vm );
		echo '<div class="bec-booking-summary__accordions" role="list" data-bec-bsummary-accordions' . ( $inner === '' ? ' hidden' : '' ) . '>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $inner;
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	private static function printPriceBreakdown( array $vm, string $cur ): void {
		echo '<div class="bec-booking-summary__breakdown" data-bec-bsummary-breakdown>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::getBreakdownInnerHtml( $vm, $cur );
		echo '</div>';
	}

	/**
	 * @param array<string, mixed>  $vm
	 * @param array<string, mixed>|\stdClass|mixed $rates
	 */
	private static function printRateList( array $vm, int $postId, SearchContext $ctx ): void {
		$show = ! empty( $vm['show_rate_list'] );
		$rts    = $vm['rates'] ?? null;
		if ( ! $show || ! \is_array( $rts ) || $rts === [] ) {
			return;
		}

		$curSel = (string) ( $vm['selected_rate_id'] ?? $ctx->getRateId() );
		echo '<div class="bec-booking-summary__rate-select">';
		echo '<div class="bec-booking-summary__rate-select-title">' . \esc_html__( 'Select rate', 'booking-engine-connector' ) . '</div>';
		echo '<ul class="bec-booking-summary__rate-list" role="radiogroup" aria-label="' . \esc_attr__( 'Rates', 'booking-engine-connector' ) . '">';

		$idx = 0;
		foreach ( $rts as $r ) {
			if ( ! \is_array( $r ) ) {
				continue;
			}
			$rid  = (string) ( $r['id'] ?? '' );
			$lbl  = (string) ( $r['label'] ?? $rid );
			$selected = ( $curSel !== '' && $rid === $curSel ) || ( $curSel === '' && $idx === 0 );
			++$idx;
			$url = self::buildRateSelectUrl( $postId, $ctx, $rid );
			$classes = 'bec-booking-summary__rate-item';
			if ( $selected ) {
				$classes .= ' is-selected';
			}
			$line = '';
			$am = $r['amount'] ?? null;
			$c2 = (string) ( $r['currency'] ?? ( $vm['currency'] ?? '' ) );
			if ( is_numeric( $am ) && $c2 !== '' ) {
				$line = BookingSummaryViewModelBuilder::formatMoney( (float) $am, $c2 );
			}
			$ac = $selected ? 'true' : 'false';
			echo '<li class="' . \esc_attr( $classes ) . '">';
			echo '<a class="bec-booking-summary__rate-link" role="radio" aria-checked="' . \esc_attr( $ac ) . '" data-bec-rate-id="' . \esc_attr( $rid ) . '" href="' . \esc_url( $url ) . '">';
			echo '<span class="bec-booking-summary__rate-dot" aria-hidden="true"></span>';
			echo '<span class="bec-booking-summary__rate-name">' . \esc_html( $lbl ) . '</span>';
			if ( $line !== '' ) {
				echo '<span class="bec-booking-summary__rate-price">' . \esc_html( $line ) . '</span>';
			}
			echo '</a></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * @param array<string, mixed>|\stdClass|mixed $urlData
	 */
	private static function renderActions(
		int $postId,
		SearchContext $ctx,
		bool $showEnquiry,
		string $enquiryLabel,
		bool $showCheckoutContinue,
		?string $checkAvailabilityFormId,
		$urlData,
		bool $isDesktopRow,
		bool $checkAvailabilityDisabled = false,
		?string $checkAvailabilityRetryFormId = null
	): void {
		$enq = self::enquiryButton( $postId, $ctx, $showEnquiry, $enquiryLabel );
		$hasCheckoutUrl = \is_array( $urlData ) && isset( $urlData['url'] ) && (string) $urlData['url'] !== '';

		$ct = '';
		if ( $showCheckoutContinue && $hasCheckoutUrl ) {
			$cta = CheckoutCtaHtml::renderCta( $urlData );
			$ct  = '<div class="bec-booking-summary__action bec-booking-summary__action--primary" data-bec-bsummary-continue data-bec-bsummary-checkout-actions>'
				. $cta
				. '</div>';
			if ( $checkAvailabilityRetryFormId !== null && $checkAvailabilityRetryFormId !== '' ) {
				$ct .= '<div class="bec-booking-summary__action bec-booking-summary__action--primary" hidden data-bec-bsummary-check-retry>'
					. self::checkAvailabilityButtonHtml( $postId, $ctx, $checkAvailabilityRetryFormId, true )
					. '</div>';
			}
		} elseif ( $checkAvailabilityFormId !== null && $checkAvailabilityFormId !== '' ) {
			$ct = '<div class="bec-booking-summary__action bec-booking-summary__action--primary" data-bec-bsummary-continue>'
				. self::checkAvailabilityButtonHtml( $postId, $ctx, $checkAvailabilityFormId, $checkAvailabilityDisabled )
				. '</div>';
		}

		$row = '<div class="bec-booking-summary__actions' . ( $isDesktopRow ? ' bec-booking-summary__actions--row' : '' ) . '">';
		if ( $enq !== '' ) {
			$row .= '<div class="bec-booking-summary__action bec-booking-summary__action--secondary">' . $enq . '</div>';
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$row .= $ct;
		$row .= '</div>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $row;
	}

	/**
	 * Hero strip inside mobile drawer (unit thumb + title + stay summary).
	 *
	 * @param array<string, mixed> $vm
	 */
	private static function printMobileDrawerHero( array $vm, int $postId ): void {
		$img = (string) ( $vm['unit_image_url'] ?? '' );
		$ttl = (string) ( $vm['unit_title'] ?? \get_the_title( $postId ) );
		if ( $img === '' && $ttl === '' ) {
			return;
		}
		echo '<div class="bec-booking-summary__hero">';
		if ( $img !== '' ) {
			echo '<img class="bec-booking-summary__hero-img" src="' . \esc_url( $img ) . '" alt="" loading="lazy" width="80" height="80" />';
		}
		echo '<div class="bec-booking-summary__hero-text">';
		echo '<div class="bec-booking-summary__hero-title">' . \esc_html( $ttl ) . '</div>';
		$in = (string) ( $vm['checkin'] ?? '' );
		$out = (string) ( $vm['checkout'] ?? '' );
		$n  = (int) ( $vm['nights'] ?? 0 );
		if ( $in !== '' && $out !== '' ) {
			echo '<div class="bec-booking-summary__hero-dates">— ' . \esc_html(
				\sprintf(
					/* translators: 1: from date, 2: to date */
					\__( 'from %1$s to %2$s', 'booking-engine-connector' ),
					self::formatShortDate( $in ),
					self::formatShortDate( $out )
				)
			) . '</div>';
		}
		if ( $n > 0 ) {
			echo '<div class="bec-booking-summary__hero-nights">— ' . \esc_html(
				\sprintf( /* translators: %d: nights */ \_n( '%d night', '%d nights', $n, 'booking-engine-connector' ), $n )
			) . '</div>';
		}
		echo '</div></div>';
	}

	/**
	 * @param array<string, mixed>          $vm
	 * @param array<string, mixed>|\stdClass|mixed $urlData
	 */
	private static function printMobileShell(
		array $vm,
		int $postId,
		SearchContext $ctx,
		string $ctxArg,
		string $instanceId,
		bool $showEnquiry,
		string $enquiryLabel,
		$urlData,
		string $mode,
		bool $checkAvailabilityDisabled = false
	): void {
		$cur = (string) ( $vm['currency'] ?? '' );
		$tot = isset( $vm['total'] ) && is_numeric( $vm['total'] ) ? (float) $vm['total'] : null;
		$st  = (string) ( $vm['state'] ?? '' );

		$bar = '';
		if ( $st === 'available' && $tot !== null && $cur !== '' ) {
			$bar = '<div data-bec-bsummary-bar-amount class="bec-booking-summary__bar-amount">'
				. self::getBarAmountSpanHtml( $vm )
				. '</div>';
		} elseif ( \in_array( $mode, [ 'error-or-empty', 'fallback' ], true ) || ( $st === 'unavailable' ) || ( $st === 'error' ) ) {
			$bar = '<span class="bec-booking-summary__bar-amount-total bec-booking-summary__bar-amount--muted">'
				. \esc_html__( 'View details', 'booking-engine-connector' ) . '</span>';
		}

		echo '<div class="bec-booking-summary__mobile" aria-label="' . \esc_attr__( 'Booking on mobile', 'booking-engine-connector' ) . '">';

		if ( $bar !== '' ) {
			echo '<div class="bec-booking-summary__bar" role="region" aria-label="' . \esc_attr__( 'Current total', 'booking-engine-connector' ) . '">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $bar;
			echo '<div class="bec-booking-summary__bar-actions">';
			echo '<button type="button" class="bec-booking-summary__open-panel" aria-controls="' . \esc_attr( $instanceId . '-panel' ) . '">'
				. \esc_html__( 'Continue', 'booking-engine-connector' ) . '</button>';
			echo '</div></div>';
		}

		echo '<div class="bec-booking-summary__backdrop" id="' . \esc_attr( $instanceId . '-backdrop' ) . '" hidden aria-hidden="true"></div>';
		echo '<div class="bec-booking-summary__drawer" id="' . \esc_attr( $instanceId . '-panel' ) . '" role="dialog" aria-modal="true" aria-label="' . \esc_attr__( 'Booking summary', 'booking-engine-connector' ) . '" aria-hidden="true" tabindex="-1" inert data-bec-bsummary-closed="1">';

		echo '<div class="bec-booking-summary__drawer-top">';
		echo '<button type="button" class="bec-booking-summary__back" aria-label="' . \esc_attr__( 'Back', 'booking-engine-connector' ) . '">← ' . \esc_html__( 'Summary', 'booking-engine-connector' ) . '</button>';
		echo '</div>';

		if ( $st === 'available' ) {
			self::printSearch( $ctxArg, $instanceId . '-m', $ctx, 'bec-booking-summary__search--drawer' );
			echo '<div class="bec-booking-summary__quote-results" data-bec-bsummary-quote-results>';
			self::printMobileDrawerHero( $vm, $postId );
			//self::printDatesGuestsBlock( $vm );
			self::printRateList( $vm, $postId, $ctx );
			self::printAccordions( $vm );
			self::printPriceBreakdown( $vm, $cur );
			echo '</div>';
			$hasCheckoutUrl = \is_array( $urlData ) && isset( $urlData['url'] ) && (string) $urlData['url'] !== '';
			$retryFormId    = $hasCheckoutUrl ? $instanceId . '-m' : null;
			self::renderActions(
				$postId,
				$ctx,
				$showEnquiry,
				$enquiryLabel,
				$hasCheckoutUrl,
				$hasCheckoutUrl ? null : $instanceId . '-m',
				$urlData,
				false,
				$checkAvailabilityDisabled,
				$retryFormId
			);
		} else {
			self::printMobileDrawerHero( $vm, $postId );
			self::printSearch( $ctxArg, $instanceId . '-m', $ctx, 'bec-booking-summary__search--drawer' );
			self::printFallbackMessageInPanel(
				$postId,
				$ctx,
				$showEnquiry,
				$enquiryLabel,
				$urlData,
				$mode,
				$vm,
				$instanceId . '-m',
				$checkAvailabilityDisabled
			);
		}

		echo '</div>'; // drawer
		echo '</div>'; // mobile
	}

	/**
	 * @param array<string, mixed>|\stdClass|mixed $urlData
	 */
	private static function printFallbackMessageInPanel(
		int $postId,
		SearchContext $ctx,
		bool $showEnquiry,
		string $enquiryLabel,
		$urlData,
		string $mode,
		array $vm,
		string $drawerSearchFormId,
		bool $checkAvailabilityDisabled = false
	): void {
		if ( $mode === 'fallback' ) {
			echo '<div class="bec-booking-summary__drawer-fallback">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo FallbackRenderer::render();
			echo '</div>';
		} elseif ( $mode === 'error-or-empty' ) {
			$st = (string) ( $vm['state'] ?? 'unavailable' );
			if ( $st === 'error' && isset( $vm['error'] ) && $vm['error'] instanceof \WP_Error ) {
				echo '<p class="bec-booking-summary__message-text" role="alert">' . \esc_html( $vm['error']->get_error_message() ) . '</p>';
			} else {
				echo '<p class="bec-booking-summary__message-text">' . \esc_html( self::getUnavailableMessageText( $postId, $ctx ) ) . '</p>';
			}
		}
		$hasCheckoutUrl = \is_array( $urlData ) && isset( $urlData['url'] ) && (string) $urlData['url'] !== '';
		self::renderActions(
			$postId,
			$ctx,
			$showEnquiry,
			$enquiryLabel,
			$hasCheckoutUrl,
			$hasCheckoutUrl ? null : $drawerSearchFormId,
			$urlData,
			false,
			$checkAvailabilityDisabled
		);
	}

	/**
	 * @param array<string, mixed>|\stdClass|mixed $urlData
	 */
	private static function enquiryButton( int $postId, SearchContext $ctx, bool $show, string $enquiryLabel ): string {
		if ( ! $show ) {
			return '';
		}
		$url = \trim( (string) \get_option( FallbackSettings::OPTION_LINK_URL, '' ) );
		$url = (string) \apply_filters( 'bec_booking_summary_enquiry_url', $url, $postId, $ctx );
		if ( $url === '' ) {
			$url = '#';
		}
		return '<a class="bec-booking-summary__enquiry" href="' . \esc_url( $url ) . '">' . \esc_html( $enquiryLabel ) . '</a>';
	}

	private static function buildRateSelectUrl( int $postId, SearchContext $ctx, string $rateId ): string {
		$base = \get_permalink( $postId );
		if ( $base === false ) {
			return '';
		}
		$args = $ctx->toQueryArgs();
		$args[ SearchContext::PARAM_RATE_ID ] = $rateId;

		return (string) \add_query_arg( $args, $base );
	}

	private static function printSearch( string $context, string $formId, SearchContext $ctx, string $extraClass = '' ): void {
		\ob_start();
		SearchForm::render(
			[
				'context'    => $context,
				'form_id'    => $formId,
				'html_class' => 'bec-search-form',
				'show_submit' => false,
			]
		);
		$form = (string) \ob_get_clean();
		$wrapClass = 'bec-booking-summary__search';
		if ( $extraClass !== '' ) {
			$wrapClass .= ' ' . $extraClass;
		}
		echo '<div class="' . \esc_attr( $wrapClass ) . '" data-bec-bsummary-search>' . $form . '</div>';
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	public static function getHeadPriceBlockInnerHtml( array $vm ): string {
		$cur = (string) ( $vm['currency'] ?? '' );
		$pn  = isset( $vm['per_night'] ) && is_numeric( $vm['per_night'] ) ? (float) $vm['per_night'] : null;
		$tot = isset( $vm['total'] ) && is_numeric( $vm['total'] ) ? (float) $vm['total'] : null;
		$out = '';
		if ( $pn !== null && $cur !== '' ) {
			$out .= '<span class="bec-booking-summary__head-amount" data-bec-bsummary-head-amount>' . \esc_html( BookingSummaryViewModelBuilder::formatMoney( $pn, $cur ) ) . '</span>';
			$out .= '<span class="bec-booking-summary__head-sub" data-bec-bsummary-head-per-night="1">' . \esc_html__( 'Per night', 'booking-engine-connector' ) . '</span>';
		} elseif ( $tot !== null && $cur !== '' ) {
			$out .= '<span class="bec-booking-summary__head-amount" data-bec-bsummary-head-amount>' . \esc_html( BookingSummaryViewModelBuilder::formatMoney( $tot, $cur ) ) . '</span>';
			$out .= '<span class="bec-booking-summary__head-sub" data-bec-bsummary-head-per-night="1" hidden="hidden"></span>';
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	public static function getBreakdownInnerHtml( array $vm, string $cur ): string {
		$tt = (string) ( $vm['tax_note'] ?? '' );
		\ob_start();
		$l = (string) ( $vm['subtotal_line_left'] ?? '' );
		$r = (string) ( $vm['subtotal_line_right'] ?? '' );
		if ( $l !== '' || $r !== '' ) {
			echo '<div class="bec-booking-summary__row bec-booking-summary__row--accommodation">';
			echo '<span class="bec-booking-summary__row-left">' . \esc_html( $l ) . '</span>';
			echo '<span class="bec-booking-summary__row-right">' . \esc_html( $r ) . '</span>';
			echo '</div>';
		}

		$extras = $vm['extra_lines'] ?? null;
		if ( \is_array( $extras ) && $extras !== [] ) {
			foreach ( $extras as $ex ) {
				if ( ! \is_array( $ex ) ) {
					continue;
				}
				$el = (string) ( $ex['label'] ?? '' );
				$am = $ex['amount'] ?? null;
				$ec = (string) ( $ex['currency'] ?? $cur );
				if ( $el === '' ) {
					continue;
				}
				echo '<div class="bec-booking-summary__row bec-booking-summary__row--service">';
				echo '<span class="bec-booking-summary__row-left">' . \esc_html( $el ) . '</span>';
				echo '<span class="bec-booking-summary__row-right">';
				if ( is_numeric( $am ) ) {
					echo \esc_html( BookingSummaryViewModelBuilder::formatMoney( (float) $am, $ec ) );
				} else {
					echo '—';
				}
				echo '</span>';
				echo '</div>';
			}
		}

		echo '<div class="bec-booking-summary__row bec-booking-summary__row--total">';
		echo '<span class="bec-booking-summary__row-left"><strong>' . \esc_html__( 'Total', 'booking-engine-connector' ) . '</strong></span>';
		$tot = isset( $vm['total'] ) && is_numeric( $vm['total'] ) ? (float) $vm['total'] : null;
		if ( $tot !== null && $cur !== '' ) {
			echo '<span class="bec-booking-summary__row-right"><strong>' . \esc_html( BookingSummaryViewModelBuilder::formatMoney( $tot, $cur ) ) . '</strong></span>';
		}
		echo '</div>';

		$advA = isset( $vm['advance_amount'] ) && is_numeric( $vm['advance_amount'] ) ? (float) $vm['advance_amount'] : null;
		$advL = (string) ( $vm['advance_label'] ?? \__( 'Prepayment needed', 'booking-engine-connector' ) );
		if ( $advA !== null && $cur !== '' ) {
			echo '<div class="bec-booking-summary__row">';
			echo '<span class="bec-booking-summary__row-left">' . \esc_html( $advL ) . '</span>';
			echo '<span class="bec-booking-summary__row-right">' . \esc_html( BookingSummaryViewModelBuilder::formatMoney( $advA, $cur ) ) . '</span>';
			echo '</div>';
		}

		$dd = isset( $vm['deposit_amount'] ) && is_numeric( $vm['deposit_amount'] ) ? (float) $vm['deposit_amount'] : null;
		$dl = (string) ( $vm['deposit_label'] ?? \__( 'Damage deposit', 'booking-engine-connector' ) );
		if ( $dd !== null && $cur !== '' ) {
			echo '<div class="bec-booking-summary__row">';
			echo '<span class="bec-booking-summary__row-left">' . \esc_html( $dl ) . '</span>';
			echo '<span class="bec-booking-summary__row-right">' . \esc_html( BookingSummaryViewModelBuilder::formatMoney( $dd, $cur ) ) . '</span>';
			echo '</div>';
		}

		if ( $tt !== '' ) {
			echo '<p class="bec-booking-summary__tax-note" role="status">';
			echo '<span class="bec-booking-summary__tax-ico" aria-hidden="true">i</span>';
			echo ' <span class="bec-booking-summary__tax-txt">' . \esc_html( $tt ) . '</span></p>';
		}

		return (string) \ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	public static function getAccordionsInnerHtml( array $vm ): string {
		$incl  = (string) ( $vm['inclusions'] ?? '' );
		$cond  = (string) ( $vm['conditions'] ?? '' );
		$tIncl = (string) ( $vm['inclusions_title'] ?? \__( 'This rate includes', 'booking-engine-connector' ) );
		$tCond = (string) ( $vm['conditions_title'] ?? \__( 'Cancellation and payments', 'booking-engine-connector' ) );
		\ob_start();
		if ( StylingSettings::isAccordionInclusionsEnabled() && $incl !== '' ) {
			echo '<details class="bec-booking-summary__accordion" role="listitem">';
			echo '<summary class="bec-booking-summary__accordion-title">' . \esc_html( $tIncl ) . '</summary>';
			echo '<div class="bec-booking-summary__accordion-body">' . self::formatMultiline( $incl ) . '</div>';
			echo '</details>';
		}
		if ( StylingSettings::isAccordionConditionsEnabled() && $cond !== '' ) {
			echo '<details class="bec-booking-summary__accordion" role="listitem">';
			echo '<summary class="bec-booking-summary__accordion-title">' . \esc_html( $tCond ) . '</summary>';
			echo '<div class="bec-booking-summary__accordion-body">' . self::formatMultiline( $cond ) . '</div>';
			echo '</details>';
		}

		return (string) \ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $vm
	 */
	public static function getBarAmountSpanHtml( array $vm ): string {
		$cur = (string) ( $vm['currency'] ?? '' );
		$tot = isset( $vm['total'] ) && is_numeric( $vm['total'] ) ? (float) $vm['total'] : null;
		$st  = (string) ( $vm['state'] ?? '' );
		if ( $st === 'available' && $tot !== null && $cur !== '' ) {
			return '<span class="bec-booking-summary__bar-amount-total">'
				. \esc_html( BookingSummaryViewModelBuilder::formatMoney( $tot, $cur ) ) . ' '
				. '<span class="bec-booking-summary__bar-total-lbl">' . \esc_html__( 'Total', 'booking-engine-connector' ) . '</span>'
				. '</span>';
		}

		return '';
	}

	private static function formatShortDate( string $ymd ): string {
		$t = \strtotime( $ymd );
		if ( $t === false ) {
			return $ymd;
		}
		// Short month + day, locale-aware
		$f = (string) \apply_filters( 'bec_booking_summary_short_date_format', 'd M' );

		return (string) \date_i18n( $f, (int) $t );
	}

	private static function formatMultiline( string $text ): string {
		$paras = \preg_split( "/\n\s*\n/", $text );
		$out   = '';
		if ( ! \is_array( $paras ) ) {
			$out = $text;
		} else {
			foreach ( $paras as $p ) {
				$p   = (string) $p;
				$out .= '<p>' . \nl2br( \esc_html( $p ) ) . '</p>';
			}
		}

		return $out;
	}
}
