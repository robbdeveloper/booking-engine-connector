<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers;

use BookingEngineConnector\Providers\Contracts\ProviderInterface;
use BookingEngineConnector\Providers\Kross\KrossProvider;

/**
 * Resolves the active provider implementation from options / filters.
 */
final class ProviderRegistry
{
	public const OPTION_ACTIVE = 'bec_active_provider';

	public static function getActiveSlug(): string
	{
		$slug = (string) get_option(self::OPTION_ACTIVE, 'kross');

		return apply_filters('bec_active_provider_slug', $slug);
	}

	public static function getProvider(?string $slug = null): ProviderInterface
	{
		$slug = $slug ?? self::getActiveSlug();

		$provider = apply_filters('bec_provider_instance', null, $slug);
		if ($provider instanceof ProviderInterface) {
			return $provider;
		}

		return match ($slug) {
			'kross' => new KrossProvider(),
			default => new KrossProvider(),
		};
	}
}
