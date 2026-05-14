<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\Providers\Kross\KrossBookingEngineSyncSettings;

/**
 * Ensures the synced remote row can be stored as post meta JSON (Kross payloads can include
 * non-finite floats or edge cases that make {@see wp_json_encode()} return false).
 *
 * Uses {@see JSON_HEX_QUOTE} / {@see JSON_HEX_APOS} so literal quotes/apostrophes inside strings
 * do not rely on `\"` sequences that interact badly with WordPress meta {@see wp_slash()} /
 * {@see wp_unslash()} round-trips on some installs.
 */
final class SyncPayloadEncoder
{
	private const MAX_DEPTH = 1000;

	/**
	 * Flags used when persisting sync payloads and when meta sanitisation re-encodes JSON.
	 *
	 * @param bool $allowPartialOutput When false, omits {@see JSON_PARTIAL_OUTPUT_ON_ERROR} (safer with {@see JSON_PRETTY_PRINT}).
	 */
	public static function metaEncodeFlags(bool $allowPartialOutput = true): int
	{
		$flags = JsonExtensionFlags::unescapedUnicode()
			| JsonExtensionFlags::unescapedSlashes()
			| JsonExtensionFlags::hexQuote()
			| JsonExtensionFlags::hexApos();

		$subst = JsonExtensionFlags::invalidUtf8Substitute();

		if ($subst !== 0) {
			$flags |= $subst;
		}

		if ($allowPartialOutput) {
			$partial = JsonExtensionFlags::partialOutputOnError();

			if ($partial !== 0) {
				$flags |= $partial;
			}
		}

		return $flags;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function encode(array $row): string
	{
		$clean = self::sanitizeForJson($row, false, 0);
		$flags = self::metaEncodeFlags();

		$json = \wp_json_encode($clean, $flags);

		if ($json !== false) {
			return $json;
		}

		$clean = self::sanitizeForJson($row, true, 0);
		$flags = self::metaEncodeFlags(false);

		$json = \wp_json_encode($clean, $flags);

		if ($json !== false) {
			return $json;
		}

		$fallback = \wp_json_encode(
			[
				'_bec_sync_payload_error'    => 'json_encode_failed',
				'_bec_sync_payload_json_msg' => \function_exists('json_last_error_msg') ? \json_last_error_msg() : 'unknown',
			],
			self::metaEncodeFlags(false)
		);

		return $fallback !== false ? $fallback : '{}';
	}

	/**
	 * Decode payload read from post meta. Retries once after {@see stripslashes()} when JSON is
	 * otherwise invalid (slash-handling edge cases).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decodeStored(string $payload): ?array
	{
		$payload = \trim($payload);

		if ($payload === '') {
			return null;
		}

		$decodeFlags = JsonExtensionFlags::invalidUtf8Substitute();

		$candidates = [ $payload ];

		$stripped = \stripslashes($payload);

		if ($stripped !== $payload) {
			$candidates[] = $stripped;
		}

		foreach ($candidates as $candidate) {
			$decoded = \json_decode($candidate, true, 8192, $decodeFlags);

			if (\json_last_error() === \JSON_ERROR_NONE && \is_array($decoded)) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Reads `be_enabled` booking-engine slugs from stored meta JSON even when full-document decode fails.
	 *
	 * @return list<string>
	 */
	public static function readBeEnabledSlugsFromStoredPayload(string $payload): array
	{
		$decoded = self::decodeStored($payload);

		if (\is_array($decoded)) {
			$raw = isset($decoded['raw']) && \is_array($decoded['raw']) ? $decoded['raw'] : $decoded;

			return \is_array($raw)
				? KrossBookingEngineSyncSettings::extractBeEnabledSlugsFromRaw($raw)
				: [];
		}

		$strings = self::scanBeEnabledArrayStrings($payload);

		return KrossBookingEngineSyncSettings::extractBeEnabledSlugsFromRaw(['be_enabled' => $strings]);
	}

	/**
	 * Parses string literals inside the first `"be_enabled": [...]` array without decoding the whole JSON.
	 *
	 * Used when malformed quoting elsewhere in the payload breaks {@see json_decode()}.
	 *
	 * @return list<string>
	 */
	private static function scanBeEnabledArrayStrings(string $payload): array
	{
		$keyNeedle = '"be_enabled"';
		$pos       = \strpos($payload, $keyNeedle);

		if ($pos === false) {
			return [];
		}

		$bracketOpen = \strpos($payload, '[', $pos + \strlen($keyNeedle));

		if ($bracketOpen === false) {
			return [];
		}

		$len       = \strlen($payload);
		$depth     = 0;
		$inString  = false;
		$escape    = false;
		$buf       = '';
		/** @var list<string> $out */
		$out = [];

		for ($i = $bracketOpen; $i < $len; ++$i) {
			$c = $payload[ $i ];

			if ($inString) {
				if ($escape) {
					$buf .= $c;
					$escape = false;

					continue;
				}

				if ($c === '\\') {
					$escape = true;

					continue;
				}

				if ($c === '"') {
					$out[] = $buf;
					$inString = false;
					$buf      = '';

					continue;
				}

				$buf .= $c;

				continue;
			}

			if ($c === '[') {
				++$depth;

				continue;
			}

			if ($c === ']') {
				--$depth;

				if ($depth === 0) {
					break;
				}

				continue;
			}

			if ($c === '"' && $depth === 1) {
				$inString = true;
				$buf      = '';
				$escape   = false;

				continue;
			}
		}

		return $out;
	}

	/**
	 * @param mixed $v
	 * @return mixed
	 */
	private static function sanitizeForJson($v, bool $aggressive, int $depth)
	{
		if ($depth > self::MAX_DEPTH) {
			return null;
		}

		if (\is_float($v) && ! \is_finite($v)) {
			return null;
		}

		if (\is_resource($v)) {
			return null;
		}

		if (\is_object($v)) {
			if ($aggressive) {
				return null;
			}

			return self::sanitizeForJson((array) $v, false, $depth + 1);
		}

		if (! \is_array($v)) {
			return $v;
		}

		$out = [];

		foreach ($v as $k => $item) {
			$out[ $k ] = self::sanitizeForJson($item, $aggressive, $depth + 1);
		}

		return $out;
	}
}
