<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Per-provider test mode for Kross inventory sync (placeholder room types and categories).
 *
 * Future providers should use the convention {@see OPTION_PREFIX}{provider_slug}_test_mode.
 */
final class KrossTestMode
{
	public const OPTION = 'bec_kross_test_mode';

	public static function isEnabled(): bool
	{
		return (bool) \get_option(self::OPTION, false);
	}

	public static function setEnabled(bool $enabled): void
	{
		\update_option(self::OPTION, $enabled ? '1' : '0', false);
	}
}
