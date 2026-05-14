<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * Safe bitmask resolution for PHP ext-json encode/decode flags.
 *
 * Some runtimes omit named constants such as {@see JSON_HEX_QUOTE}; fallbacks match PHP 8.x values.
 *
 * @see https://www.php.net/manual/en/json.constants.php
 */
final class JsonExtensionFlags
{
	private static function bit(string $name, int $fallback): int
	{
		return \defined($name) ? (int) \constant($name) : $fallback;
	}

	public static function hexTag(): int
	{
		return self::bit('JSON_HEX_TAG', 1);
	}

	public static function hexAmp(): int
	{
		return self::bit('JSON_HEX_AMP', 2);
	}

	public static function hexApos(): int
	{
		return self::bit('JSON_HEX_APOS', 4);
	}

	public static function hexQuote(): int
	{
		return self::bit('JSON_HEX_QUOTE', 8);
	}

	public static function unescapedSlashes(): int
	{
		return self::bit('JSON_UNESCAPED_SLASHES', 64);
	}

	public static function prettyPrint(): int
	{
		return self::bit('JSON_PRETTY_PRINT', 128);
	}

	public static function unescapedUnicode(): int
	{
		return self::bit('JSON_UNESCAPED_UNICODE', 256);
	}

	public static function partialOutputOnError(): int
	{
		return self::bit('JSON_PARTIAL_OUTPUT_ON_ERROR', 0);
	}

	public static function invalidUtf8Substitute(): int
	{
		return self::bit('JSON_INVALID_UTF8_SUBSTITUTE', 0);
	}
}
