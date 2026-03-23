<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Contracts;

/**
 * Normalized provider failure kinds for logging, UI, and fallback decisions.
 */
final class ProviderErrorCategory
{
	public const RATE_LIMIT   = 'rate_limit';
	public const AUTH         = 'auth';
	public const QUOTA        = 'quota';
	public const SERVER_ERROR = 'server_error';
	public const VALIDATION   = 'validation';
	public const UNKNOWN      = 'unknown';

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return [
			self::RATE_LIMIT,
			self::AUTH,
			self::QUOTA,
			self::SERVER_ERROR,
			self::VALIDATION,
			self::UNKNOWN,
		];
	}
}
