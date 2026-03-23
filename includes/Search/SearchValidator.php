<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

use BookingEngineConnector\Providers\ProviderRegistry;
use DateTimeImmutable;

/**
 * Validates search dates and guest counts (CA-SEA-1).
 */
final class SearchValidator
{
	/**
	 * When context is complete, returns null if valid or a WP_Error with a user-facing message.
	 * When incomplete, returns null (no validation — partial form state).
	 */
	public static function validate(SearchContext $ctx): ?\WP_Error
	{
		if (! $ctx->isComplete()) {
			return null;
		}

		$checkin  = self::parseDate($ctx->getCheckin());
		$checkout = self::parseDate($ctx->getCheckout());

		if ($checkin === null) {
			return new \WP_Error(
				'bec_search_invalid_checkin',
				\__('Please enter a valid check-in date.', 'booking-engine-connector')
			);
		}

		if ($checkout === null) {
			return new \WP_Error(
				'bec_search_invalid_checkout',
				\__('Please enter a valid check-out date.', 'booking-engine-connector')
			);
		}

		if ($checkout <= $checkin) {
			return new \WP_Error(
				'bec_search_checkout_before_checkin',
				\__('Check-out must be after check-in.', 'booking-engine-connector')
			);
		}

		$nights = self::nightCount($checkin, $checkout);
		$min    = (int) \apply_filters('bec_search_min_nights', 1, $ctx);
		$max    = (int) \apply_filters('bec_search_max_nights', 365, $ctx);

		if ($nights < $min) {
			return new \WP_Error(
				'bec_search_min_nights',
				\sprintf(
					/* translators: %d minimum nights */
					\_n(
						'Minimum stay is %d night.',
						'Minimum stay is %d nights.',
						$min,
						'booking-engine-connector'
					),
					$min
				)
			);
		}

		if ($nights > $max) {
			return new \WP_Error(
				'bec_search_max_nights',
				\sprintf(
					/* translators: %d maximum nights */
					\__('The selected range is too long (maximum %d nights).', 'booking-engine-connector'),
					$max
				)
			);
		}

		$needsChildAges = (bool) \apply_filters(
			'bec_provider_requires_children_ages',
			ProviderRegistry::getProvider()->requiresChildrenAges(),
			$ctx
		);
		if ($needsChildAges && $ctx->getChildren() > 0) {
			if (\count($ctx->getChildrenAges()) !== $ctx->getChildren()) {
				return new \WP_Error(
					'bec_search_children_ages_required',
					\__('Please enter an age for each child.', 'booking-engine-connector')
				);
			}
		}

		/** @var mixed $extra */
		$extra = \apply_filters('bec_search_validate', null, $ctx, $checkin, $checkout);

		return $extra instanceof \WP_Error ? $extra : null;
	}

	private static function parseDate(string $raw): ?DateTimeImmutable
	{
		$raw = \trim($raw);
		if ($raw === '') {
			return null;
		}

		$d = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
		if ($d === false) {
			return null;
		}

		$errors = DateTimeImmutable::getLastErrors();
		if (\is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
			return null;
		}

		return $d->setTime(0, 0, 0);
	}

	private static function nightCount(DateTimeImmutable $checkin, DateTimeImmutable $checkout): int
	{
		$start = $checkin->getTimestamp();
		$end   = $checkout->getTimestamp();
		if ($end <= $start) {
			return 0;
		}

		return (int) \floor(($end - $start) / \DAY_IN_SECONDS);
	}
}
