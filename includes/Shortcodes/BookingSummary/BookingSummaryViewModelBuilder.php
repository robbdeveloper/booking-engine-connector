<?php

declare(strict_types=1);

namespace BookingEngineConnector\Shortcodes\BookingSummary;

use BookingEngineConnector\Search\SearchContext;

/**
 * Builds a view-model array for {@see BookingSummaryRenderer} from quote + unit payload.
 *
 * @phpstan-type SummaryRate array{id: string, label: string, amount: ?float, currency: ?string, raw: mixed}
 * @phpstan-type LineItem array{key: string, label: string, amount: ?float, currency: ?string, note: string}
 * @phpstan-type ViewModel array{
 *   state: string,
 *   provider: string,
 *   locale: string,
 *   unit_id: int,
 *   unit_title: string,
 *   unit_image_url: string,
 *   checkin: string,
 *   checkout: string,
 *   nights: int,
 *   adults: int,
 *   children: int,
 *   guests_line: string,
 *   currency: ?string,
 *   total: ?float,
 *   per_night: ?float,
 *   subtotal_line_left: string,
 *   subtotal_line_right: string,
 *   advance_label: string,
 *   advance_amount: ?float,
 *   deposit_label: string,
 *   deposit_amount: ?float,
 *   tax_note: string,
 *   inclusions: string,
 *   conditions: string,
 *   extra_lines: list<LineItem>,
 *   accommodation_subtotal: ?float,
 *   accommodation_per_night: ?float,
 *   rates: list<SummaryRate>,
 *   selected_rate_id: string,
 *   show_rate_list: bool,
 *   inclusions_title: string,
 *   conditions_title: string
 * }
 */
final class BookingSummaryViewModelBuilder
{
	/**
	 * @param array<string, mixed> $syncPayload
	 * @return array<string, mixed> View model (extensible, filtered).
	 */
	public static function build(
		int $postId,
		SearchContext $ctx,
		string $providerSlug,
		$quote,
		array $syncPayload,
		string $taxNoteDefault
	): array {
		$locale2 = self::siteLocale2();

		$unitTitle = (string) \get_post_meta($postId, 'bec_core_name', true);
		if ($unitTitle === '') {
			$unitTitle = (string) \get_the_title($postId);
		}
		$imageUrl = self::unitFeaturedImageUrl($postId);

		$titleInclusions = (string) \apply_filters(
			'bec_booking_summary_title_inclusions',
			\__('This rate includes', 'booking-engine-connector'),
			$postId,
			$ctx,
			$providerSlug
		);
		$titleConditions = (string) \apply_filters(
			'bec_booking_summary_title_conditions',
			\__('Cancellation and payments', 'booking-engine-connector'),
			$postId,
			$ctx,
			$providerSlug
		);

		$vm = [
			'state'             => 'unavailable',
			'provider'          => $providerSlug,
			'locale'            => $locale2,
			'unit_id'           => $postId,
			'unit_title'        => $unitTitle,
			'unit_image_url'    => $imageUrl,
			'currency'          => null,
			'checkin'           => $ctx->getCheckin(),
			'checkout'          => $ctx->getCheckout(),
			'nights'            => self::nightsBetween($ctx->getCheckin(), $ctx->getCheckout()),
			'adults'            => $ctx->getAdults(),
			'children'          => $ctx->getChildren(),
			'guests_line'       => self::formatGuestsLine($ctx),
			'total'             => null,
			'per_night'         => null,
			'subtotal_line_left'  => '',
			'subtotal_line_right' => '',
			'advance_label'     => (string) \apply_filters(
				'bec_booking_summary_advance_label',
				\__('Prepayment needed', 'booking-engine-connector'),
				$postId,
				$ctx,
				$providerSlug
			),
			'advance_amount'    => null,
			'deposit_label'     => (string) \apply_filters(
				'bec_booking_summary_deposit_label',
				\__('Damage deposit', 'booking-engine-connector'),
				$postId,
				$ctx,
				$providerSlug
			),
			'deposit_amount'    => null,
			'tax_note'          => $taxNoteDefault,
			'inclusions'        => '',
			'conditions'        => '',
			'extra_lines'       => [],
			'rates'             => [],
			'selected_rate_id'  => (string) $ctx->getRateId(),
			'show_rate_list'    => false,
			'inclusions_title'      => $titleInclusions,
			'conditions_title'    => $titleConditions,
			'accommodation_subtotal' => null,
			'accommodation_per_night' => null,
		];

		if ($quote instanceof \WP_Error) {
			$vm['state'] = 'error';
			$vm['error'] = $quote;

			/** @var array<string, mixed> $vm */
			return (array) \apply_filters('bec_booking_summary_view_model', $vm, $postId, $ctx, $quote, $syncPayload, $providerSlug);
		}

		if (! \is_array($quote)) {
			$vm['state'] = 'unavailable';

			/** @var array<string, mixed> $vm */
			return (array) \apply_filters('bec_booking_summary_view_model', $vm, $postId, $ctx, $quote, $syncPayload, $providerSlug);
		}

		$available = ! empty($quote['available']);
		if (! $available) {
			$vm['state'] = 'unavailable';

			/** @var array<string, mixed> $vm */
			return (array) \apply_filters('bec_booking_summary_view_model', $vm, $postId, $ctx, $quote, $syncPayload, $providerSlug);
		}

		$vm['state'] = 'available';
		$vm          = self::enrichByProvider(
			$vm,
			$providerSlug,
			$quote,
			$syncPayload,
			$ctx,
			$postId
		);

		/** @var array<string, mixed> $vm */
		return (array) \apply_filters('bec_booking_summary_view_model', $vm, $postId, $ctx, $quote, $syncPayload, $providerSlug);
	}

	/**
	 * @param array<string, mixed> $vm
	 * @param array<string, mixed> $quote
	 * @param array<string, mixed> $syncPayload
	 * @return array<string, mixed>
	 */
	private static function enrichByProvider(
		array $vm,
		string $providerSlug,
		array $quote,
		array $syncPayload,
		SearchContext $ctx,
		int $postId
	): array {
		if ($providerSlug === 'kross') {
			return self::enrichKross($vm, $quote, $syncPayload, $ctx, $postId);
		}

		return self::enrichGeneric($vm, $quote, $syncPayload, $ctx, $postId);
	}

	/**
	 * @param array<string, mixed> $vm
	 * @param array<string, mixed> $quote
	 * @param array<string, mixed> $syncPayload
	 * @return array<string, mixed>
	 */
	private static function enrichKross(
		array $vm,
		array $quote,
		array $syncPayload,
		SearchContext $ctx,
		int $postId
	): array {
		$rates   = isset($quote['rates']) && \is_array($quote['rates']) ? $quote['rates'] : [];
		$vm['rates'] = $rates;
		$n = \count($rates);
		$vm['show_rate_list'] = $n > 1;

		$sel = $quote['selected_rate'] ?? null;
		if ( ! \is_array( $sel ) ) {
			$price = $quote['price'] ?? null;
			if ( \is_array( $price ) && isset( $price['amount'] ) && is_numeric( $price['amount'] ) ) {
				$vm['total']     = (float) $price['amount'];
				$vm['currency']  = isset( $price['currency'] ) ? (string) $price['currency'] : null;
				$nights          = (int) ( $vm['nights'] ?? 0 );
				$vm['per_night'] = ( $nights > 0 ) ? ( (float) $price['amount'] / $nights ) : (float) $price['amount'];
			}
			$vm = self::buildSubtotalDisplayLines( $vm, (string) ( $vm['currency'] ?? '' ) );

			return (array) \apply_filters( 'bec_kross_booking_summary_view_model', $vm, $quote, $syncPayload, $ctx, $postId );
		}
		$raw = $sel['raw'] ?? null;
		$rawArr = \is_array($raw) ? $raw : [];
		$cur = $sel['currency'] ?? null;
		$vm['currency'] = $cur !== null && (string) $cur !== '' ? (string) $cur : ( isset( $rawArr['currency'] ) ? (string) $rawArr['currency'] : null );

		$total = $sel['amount'] ?? null;
		$totalF = \is_numeric($total) ? (float) $total : null;
		// `amount` is typically the total for the stay; set filter to false if your API returns a nightly `amount`.
		/** @var float|null $totalF */
		$isTotalForStay = (bool) \apply_filters('bec_kross_booking_summary_amount_is_total', true, $rawArr, $postId, $ctx);

		$nights = (int) ( $vm['nights'] ?? 0 );
		if ( $isTotalForStay && $totalF !== null && $nights > 0 ) {
			$vm['total']     = $totalF;
			$vm['per_night'] = $totalF / $nights;
		} elseif ( $totalF !== null && $nights > 0 && ! $isTotalForStay ) {
			$vm['per_night'] = $totalF;
			$vm['total']     = $totalF * $nights;
		} else {
			$vm['total']     = $totalF;
			$vm['per_night'] = ( $nights > 0 && $totalF !== null ) ? $totalF / $nights : $totalF;
		}

		$incl = self::mapLocalizedText( $rawArr['be_small_text'] ?? null, (string) $vm['locale'] );
		$cond = self::mapLocalizedText( $rawArr['be_conditions'] ?? null, (string) $vm['locale'] );
		$vm['inclusions'] = self::normalizeTextBlock( $incl );
		$vm['conditions'] = self::normalizeTextBlock( $cond );

		$dd = self::krossExtractDamageDeposit( $rawArr, $syncPayload );
		if ( $dd !== null ) {
			$vm['deposit_amount'] = $dd;
		}

		$mand = self::krossExtractServiceExtraLines(
			$rawArr,
			$syncPayload,
			(string) $vm['currency'],
			(string) $vm['locale'],
			$nights,
			$ctx->getAdults(),
			$ctx->getChildren(),
			$postId,
			$ctx
		);
		$vm['extra_lines'] = $mand;

		$vm = self::applyKrossAccommodationAndGrandTotal( $vm );

		$extras = (array) ( $vm['extra_lines'] ?? [] );
		$sumForPrepay = (float) \apply_filters(
			'bec_kross_booking_summary_prepay_extra_amount',
			self::sumExtraLineAmounts( $extras ),
			$extras,
			$rawArr,
			$ctx,
			$postId,
			$vm
		);
		$adv = $rawArr['adv_tot_price'] ?? null;
		$percRaw = $rawArr['adv_perc'] ?? null;
		// Kross can send both; `adv_tot_price` may mirror a % of the room only — use `adv_perc` on grand total when set.
		if ( isset( $vm['total'] ) && is_numeric( $vm['total'] ) && is_numeric( $percRaw ) ) {
			$perc = (float) $percRaw;
			$vm['advance_amount'] = \round( (float) $vm['total'] * ( $perc / 100.0 ), 2 );
		} elseif ( \is_numeric( $adv ) && $sumForPrepay > 0.0001 ) {
			$vm['advance_amount'] = \round( (float) $adv + $sumForPrepay, 2 );
		} elseif ( \is_numeric( $adv ) ) {
			$vm['advance_amount'] = (float) $adv;
		}

		$vm = self::buildSubtotalDisplayLines( $vm, (string) $vm['currency'] );
		$vm['selected_rate_id'] = (string) ( $sel['id'] ?? $ctx->getRateId() );

		/** @var array<string, mixed> $vm */
		$vm = (array) \apply_filters( 'bec_kross_booking_summary_view_model', $vm, $quote, $syncPayload, $ctx, $postId );

		return $vm;
	}

	/**
	 * @param array<string, mixed> $vm
	 * @param array<string, mixed> $quote
	 * @param array<string, mixed> $syncPayload
	 * @return array<string, mixed>
	 */
	private static function enrichGeneric(
		array $vm,
		array $quote,
		array $syncPayload,
		SearchContext $ctx,
		int $postId
	): array {
		$rates = isset( $quote['rates'] ) && \is_array( $quote['rates'] ) ? $quote['rates'] : [];
		$vm['rates'] = $rates;
		$vm['show_rate_list'] = \count( $rates ) > 1;

		$sel = $quote['selected_rate'] ?? null;
		if ( ! \is_array( $sel ) ) {
			$price = $quote['price'] ?? null;
			if ( \is_array( $price ) && isset( $price['amount'] ) && is_numeric( $price['amount'] ) ) {
				$vm['total']     = (float) $price['amount'];
				$vm['currency']  = isset( $price['currency'] ) ? (string) $price['currency'] : null;
				$vm['per_night'] = ( (int) $vm['nights'] > 0 )
					? (float) $price['amount'] / (int) $vm['nights']
					: (float) $price['amount'];
			}
			$vm = self::buildSubtotalDisplayLines( $vm, (string) ( $vm['currency'] ?? '' ) );

			return (array) \apply_filters( 'bec_generic_booking_summary_view_model', $vm, $quote, $syncPayload, $ctx, $postId );
		}

		$total = $sel['amount'] ?? null;
		$totalF = is_numeric( $total ) ? (float) $total : null;
		$cur = $sel['currency'] ?? null;
		$vm['currency']  = $cur !== null && (string) $cur !== '' ? (string) $cur : $vm['currency'];
		$vm['total']     = $totalF;
		$nights = (int) ( $vm['nights'] ?? 0 );
		$vm['per_night'] = ( $nights > 0 && $totalF !== null ) ? $totalF / $nights : $totalF;

		$raw = $sel['raw'] ?? null;
		if ( \is_array( $raw ) ) {
			$dd = self::krossExtractDamageDeposit( $raw, $syncPayload );
			if ( $dd !== null ) {
				$vm['deposit_amount'] = $dd;
			}
		}

		$vm = self::buildSubtotalDisplayLines( $vm, (string) ( $vm['currency'] ?? '' ) );
		$vm['selected_rate_id'] = (string) ( $sel['id'] ?? $ctx->getRateId() );

		return (array) \apply_filters( 'bec_generic_booking_summary_view_model', $vm, $quote, $syncPayload, $ctx, $postId );
	}

	/**
	 * @param array<string, mixed> $vm
	 * @return array<string, mixed>
	 */
	private static function buildSubtotalDisplayLines( array $vm, string $currency ): array {
		$nights = (int) ( $vm['nights'] ?? 0 );
		$cur    = $currency !== '' ? $currency : (string) ( $vm['currency'] ?? '' );

		if ( isset( $vm['accommodation_subtotal'] ) && is_numeric( $vm['accommodation_subtotal'] )
			&& isset( $vm['accommodation_per_night'] ) && is_numeric( $vm['accommodation_per_night'] )
		) {
			$accNight = (float) $vm['accommodation_per_night'];
			$accSub   = (float) $vm['accommodation_subtotal'];
			if ( $nights > 0 ) {
				$left = \sprintf(
					/* translators: 1: formatted money, 2: number of nights */
					\__( '%1$s × %2$d nights', 'booking-engine-connector' ),
					self::formatMoney( $accNight, $cur ),
					$nights
				);
			} else {
				$left = self::formatMoney( $accSub, $cur );
			}
			$right = self::formatMoney( $accSub, $cur );
		} else {
			/** @var float|null $perNight */
			$perNight = isset( $vm['per_night'] ) && is_numeric( $vm['per_night'] ) ? (float) $vm['per_night'] : null;
			$total    = isset( $vm['total'] ) && is_numeric( $vm['total'] ) ? (float) $vm['total'] : null;
			if ( $perNight !== null && $nights > 0 ) {
				$left = \sprintf(
					/* translators: 1: formatted money, 2: number of nights */
					\__( '%1$s × %2$d nights', 'booking-engine-connector' ),
					self::formatMoney( $perNight, $cur ),
					$nights
				);
			} else {
				$left = $total !== null ? self::formatMoney( $total, $cur ) : '';
			}
			$right = $total !== null ? self::formatMoney( $total, $cur ) : '';
		}

		$vm['subtotal_line_left']  = $left;
		$vm['subtotal_line_right'] = $right;

		return $vm;
	}

	/**
	 * Quoted `amount` is the stay (room) only. Mandatory and other service lines are never part of
	 * that rate: grand total = stay + sum(priced extra_lines). List order: mandatory, then any others.
	 *
	 * @param array<string, mixed> $vm
	 * @return array<string, mixed>
	 */
	private static function applyKrossAccommodationAndGrandTotal( array $vm ): array {
		$stay = isset( $vm['total'] ) && is_numeric( $vm['total'] ) ? (float) $vm['total'] : null;
		if ( $stay === null ) {
			return $vm;
		}
		$extras = $vm['extra_lines'] ?? null;
		if ( ! \is_array( $extras ) || $extras === [] ) {
			return $vm;
		}
		$sumSrv = self::sumExtraLineAmounts( $extras );
		if ( $sumSrv < 0.0001 ) {
			return $vm;
		}
		$nights = (int) ( $vm['nights'] ?? 0 );
		$vm['accommodation_subtotal']  = \round( $stay, 2 );
		$vm['accommodation_per_night'] = ( $nights > 0 ) ? \round( $stay / $nights, 4 ) : $stay;
		$vm['per_night']               = ( $nights > 0 ) ? ( $stay / $nights ) : $stay;
		$vm['total']                   = \round( $stay + $sumSrv, 2 );
		return $vm;
	}

	/**
	 * @param list<array<string, mixed>> $lines
	 */
	private static function sumExtraLineAmounts( array $lines ): float {
		$s = 0.0;
		foreach ( $lines as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}
			$a = $row['amount'] ?? null;
			if ( is_numeric( $a ) ) {
				$s += (float) $a;
			}
		}
		return \round( $s, 2 );
	}

	/**
	 * @param array<string, mixed> $ra
	 * @param array<string, mixed> $syncPayload
	 */
	private static function krossExtractDamageDeposit( array $ra, array $syncPayload ): ?float {
		$keys = [ 'damage_deposit', 'caution', 'cauzione', 'importo_cauzione' ];
		foreach ( $keys as $k ) {
			if ( ! isset( $ra[ $k ] ) ) {
				continue;
			}
			$v = $ra[ $k ];
			if ( is_numeric( $v ) ) {
				return (float) $v;
			}
			if ( \is_array( $v ) && isset( $v['amount'] ) && is_numeric( $v['amount'] ) ) {
				return (float) $v['amount'];
			}
		}

		$raw = $syncPayload['raw'] ?? null;
		if ( \is_array( $raw ) ) {
			return self::krossExtractDamageDeposit( $raw, [] );
		}

		return null;
	}

	/**
	 * Calendar `calendar/book` v5+ rows use `services_mandatory` and `services_included` with
	 * `price_service`, `price_for_night`, `price_for_person`, `srv_nights` (v4–v5). Older payloads
	 * may use `mandatory_services` with `amount` / `total`.
	 *
	 * @param array<string, mixed>  $ra
	 * @param array<string, mixed>  $syncPayload
	 * @return list<array{key: string, label: string, amount: ?float, currency: ?string, note: string}>
	 */
	private static function krossExtractServiceExtraLines(
		array $ra,
		array $syncPayload,
		string $currency,
		string $locale2,
		int $stayNights,
		int $adults,
		int $children,
		int $postId,
		SearchContext $ctx
	): array {
		$out    = [];
		$addIdx = 0;

		$showIncluded = (bool) \apply_filters( 'bec_kross_booking_summary_show_included_service_lines', true, $ra, $postId, $ctx );

		$foundMandatory = self::krossPluckServiceArray( $ra, $syncPayload, 'services_mandatory' );
		if ( $foundMandatory === [] ) {
			$foundMandatory = self::krossPluckServiceArray( $ra, $syncPayload, 'mandatory_services' );
		}
		$incl = self::krossPluckServiceArray( $ra, $syncPayload, 'services_included' );

		foreach ( $foundMandatory as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}
			$label = self::krossV5ServiceLabel( $row, $locale2 );
			if ( $label === '' ) {
				$label = (string) \apply_filters(
					'bec_booking_summary_mandatory_generic_label',
					\__( 'Mandatory service', 'booking-engine-connector' ),
					$addIdx,
					$row
				);
			}
			$amt = self::krossComputeServiceLineAmount( $row, $stayNights, $adults, $children, $postId, $ctx );
			$out[] = [
				'key'      => 'm-' . (string) $addIdx,
				'label'    => $label,
				'amount'   => $amt,
				'currency' => $currency !== '' ? $currency : null,
				'note'     => '',
			];
			++$addIdx;
		}

		if ( $showIncluded ) {
			foreach ( $incl as $row ) {
				if ( ! \is_array( $row ) ) {
					continue;
				}
				$label = self::krossV5ServiceLabel( $row, $locale2 );
				if ( $label === '' ) {
					$label = (string) \apply_filters(
						'bec_booking_summary_included_generic_label',
						\__( 'Service', 'booking-engine-connector' ),
						$addIdx,
						$row
					);
				}
				$label = (string) \apply_filters(
					'bec_kross_included_service_label',
					$label . ' (' . \__( 'in rate', 'booking-engine-connector' ) . ')',
					$row,
					$locale2,
					$postId,
					$ctx
				);
				$amt = self::krossComputeServiceLineAmount( $row, $stayNights, $adults, $children, $postId, $ctx );
				$out[] = [
					'key'      => 'i-' . (string) $addIdx,
					'label'    => $label,
					'amount'   => $amt,
					'currency' => $currency !== '' ? $currency : null,
					'note'     => '',
				];
				++$addIdx;
			}
		}

		/** @var list<array{key: string, label: string, amount: ?float, currency: ?string, note: string}> $out */
		return (array) \apply_filters( 'bec_kross_booking_summary_extra_lines', $out, $ra, $syncPayload, $ctx, $postId );
	}

	/**
	 * @param array<string, mixed> $ra
	 * @param array<string, mixed> $syncPayload
	 * @return list<array>
	 */
	private static function krossPluckServiceArray( array $ra, array $syncPayload, string $key ): array {
		if ( isset( $ra[ $key ] ) && \is_array( $ra[ $key ] ) && $ra[ $key ] !== [] ) {
			/** @var list<array> $a */
			$a = $ra[ $key ];
			return $a;
		}
		$raw = $syncPayload['raw'] ?? null;
		if ( \is_array( $raw ) && isset( $raw[ $key ] ) && \is_array( $raw[ $key ] ) ) {
			/** @var list<array> $a */
			$a = $raw[ $key ];
			return $a;
		}
		return [];
	}

	/**
	 * v5+ service row: `price_service` (unit) × nights / persons per flags.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function krossComputeServiceLineAmount(
		array $row,
		int $stayNights,
		int $adults,
		int $children,
		int $postId,
		SearchContext $ctx
	): ?float {
		$v5    = false;
		$line  = 0.0;
		$ok    = false;

		if ( isset( $row['price_service'] ) && is_numeric( $row['price_service'] ) ) {
			$ok  = true;
			$v5  = true;
			$line = (float) $row['price_service'];
		} elseif ( isset( $row['amount'] ) && is_numeric( $row['amount'] ) ) {
			$ok   = true;
			$line = (float) $row['amount'];
		} elseif ( isset( $row['total'] ) && is_numeric( $row['total'] ) ) {
			$ok   = true;
			$line = (float) $row['total'];
		}
		if ( ! $ok ) {
			return null;
		}
		// v5+ rows use `price_service` and flags; legacy `amount` / `total` is already a final figure.
		if ( ! $v5 ) {
			return (float) \round( $line, 2 );
		}

		$forNight   = ! empty( $row['price_for_night'] );
		$forPerson  = ! empty( $row['price_for_person'] );

		$nightsLine = 1;
		if ( $forNight ) {
			$srvN = $row['srv_nights'] ?? null;
			if ( is_numeric( $srvN ) && (int) $srvN > 0 ) {
				$nightsLine = (int) $srvN;
			} else {
				$nightsLine = max( 1, $stayNights );
			}
			if ( $stayNights > 0 && $nightsLine > $stayNights ) {
				$nightsLine = $stayNights;
			}
			$line *= $nightsLine;
		}

		if ( $forPerson ) {
			$appliesAdults  = ( isset( $row['adults'] ) && ( $row['adults'] === true || $row['adults'] === 1 || $row['adults'] === '1' ) );
			$childIds = $row['id_child'] ?? null;
			$hasChild  = \is_array( $childIds ) && $childIds !== [] && $children > 0;

			$persons = 0;
			if ( $appliesAdults && $adults > 0 ) {
				$persons += $adults;
			}
			if ( $hasChild ) {
				$persons += $children;
			}
			if ( $persons < 1 ) {
				$persons = max( 1, $adults + $children );
			}
			$line *= $persons;
		}

		$out = (float) \round( $line, 2 );

		return (float) \apply_filters( 'bec_kross_service_line_amount', $out, $row, $stayNights, $adults, $children, $postId, $ctx );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function krossV5ServiceLabel( array $row, string $locale2 ): string {
		$tr = $row['name_service_t'] ?? $row['name_translations'] ?? $row['name_service_translations'] ?? $row['labels'] ?? null;
		if ( \is_array( $tr ) ) {
			if ( isset( $tr['main'] ) && \is_string( $tr['main'] ) && $tr['main'] !== '' ) {
				return \trim( $tr['main'] );
			}
			$k = $locale2;
			if ( isset( $tr[ $k ] ) && \is_string( $tr[ $k ] ) && $tr[ $k ] !== '' ) {
				return \trim( $tr[ $k ] );
			}
			if ( isset( $tr['en'] ) && \is_string( $tr['en'] ) ) {
				return \trim( $tr['en'] );
			}
			foreach ( $tr as $v ) {
				if ( \is_string( $v ) && $v !== '' ) {
					return \trim( $v );
				}
			}
		}
		if ( isset( $row['name_service'] ) && \is_string( $row['name_service'] ) && $row['name_service'] !== '' ) {
			return \trim( $row['name_service'] );
		}
		if ( isset( $row['name'] ) && \is_string( $row['name'] ) ) {
			return \trim( $row['name'] );
		}
		return '';
	}

	/**
	 * @param mixed $val
	 */
	private static function mapLocalizedText( $val, string $locale2 ): string {
		if ( \is_string( $val ) ) {
			return \trim( $val );
		}
		if ( ! \is_array( $val ) ) {
			return '';
		}
		$k = $locale2;
		if ( isset( $val[ $k ] ) && \is_string( $val[ $k ] ) ) {
			return \trim( $val[ $k ] );
		}
		if ( isset( $val['en'] ) && \is_string( $val['en'] ) ) {
			return \trim( $val['en'] );
		}
		foreach ( $val as $v ) {
			if ( \is_string( $v ) && $v !== '' ) {
				return \trim( $v );
			}
		}

		return '';
	}

	private static function normalizeTextBlock( string $text ): string {
		$text = \str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = \trim( $text );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		return \wp_strip_all_tags( $text, true );
	}

	public static function formatMoney( float $amount, string $currency ): string {
		$num = \number_format_i18n( $amount, 2 );
		$cc  = \strtoupper( $currency );
		if ( $cc === 'EUR' || $cc === '€' ) {
			/* translators: %s: amount with i18n decimal formatting (European style). */
			return \sprintf( \__( '%s €', 'booking-engine-connector' ), $num );
		}
		if ( $cc !== '' ) {
			/* translators: 1: amount, 2: currency. */
			return \sprintf( \__( '%1$s %2$s', 'booking-engine-connector' ), $num, $cc );
		}

		return $num;
	}

	private static function siteLocale2(): string {
		$loc = \function_exists( 'determine_locale' ) ? \determine_locale() : \get_locale();
		$loc = \str_replace( '-', '_', (string) $loc );
		$primary = \explode( '_', $loc, 2 )[0];

		return \strtolower( \substr( $primary, 0, 2 ) ) ?: 'en';
	}

	public static function unitFeaturedImageUrl( int $postId ): string {
		$id = (int) \get_post_thumbnail_id( $postId );
		if ( $id < 1 ) {
			$g = (string) \get_post_meta( $postId, 'bec_core_gallery', true );
			if ( $g !== '' ) {
				$ids = \json_decode( $g, true );
				if ( \is_array( $ids ) && $ids !== [] ) {
					$first = $ids[0] ?? 0;
					$id = (int) $first;
				}
			}
		}
		if ( $id < 1 ) {
			return '';
		}
		$u = \wp_get_attachment_image_url( $id, 'medium' );

		return \is_string( $u ) ? $u : '';
	}

	private static function formatGuestsLine( SearchContext $ctx ): string {
		$a = $ctx->getAdults();
		$c = $ctx->getChildren();
		$guests = $a + $c;
		/* translators: %d: total number of guests */
		return (string) \sprintf( \__( '%d guests', 'booking-engine-connector' ), $guests );
	}

	/**
	 * @return int >= 0
	 */
	public static function nightsBetween( string $checkin, string $checkout ): int {
		$in  = \date_create( $checkin );
		$out = \date_create( $checkout );
		if ( ! ( $in instanceof \DateTimeInterface ) || ! ( $out instanceof \DateTimeInterface ) ) {
			return 0;
		}
		$diff = $in->diff( $out, false );
		$d    = (int) $diff->format( '%r%a' );
		$n    = ( $d < 0 ) ? 0 : $d;

		return $n;
	}
}
