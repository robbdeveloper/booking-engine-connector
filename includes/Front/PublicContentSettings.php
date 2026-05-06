<?php

declare(strict_types=1);

namespace BookingEngineConnector\Front;

/**
 * Options for automatic booking UI injected with {@see PublicContentBlocks}.
 *
 * Admin UI: {@see \BookingEngineConnector\Admin\Settings\ConnectionPage}.
 */
final class PublicContentSettings
{
	public const OPTION_APPEND_BOOKING_BLOCKS_TO_CONTENT = 'bec_append_booking_blocks_to_content';

	public static function isAppendBookingBlocksToContentEnabled(): bool
	{
		return (int) \get_option(self::OPTION_APPEND_BOOKING_BLOCKS_TO_CONTENT, 0) === 1;
	}
}
