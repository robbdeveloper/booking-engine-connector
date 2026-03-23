<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * Ensures the synced remote row can be stored as post meta JSON (Kross payloads can include
 * non-finite floats or edge cases that make {@see wp_json_encode()} return false).
 */
final class SyncPayloadEncoder
{
	private const MAX_DEPTH = 1000;

	/**
	 * @param array<string, mixed> $row
	 */
	public static function encode(array $row): string
	{
		$clean = self::sanitizeForJson($row, false, 0);
		$flags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;
		if (\defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
			$flags |= \JSON_INVALID_UTF8_SUBSTITUTE;
		}
		if (\defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
			$flags |= \JSON_PARTIAL_OUTPUT_ON_ERROR;
		}

		$json = \wp_json_encode($clean, $flags);
		if ($json !== false) {
			return $json;
		}

		$clean = self::sanitizeForJson($row, true, 0);
		$flags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;
		if (\defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
			$flags |= \JSON_INVALID_UTF8_SUBSTITUTE;
		}
		$json = \wp_json_encode($clean, $flags);
		if ($json !== false) {
			return $json;
		}

		$fallback = \wp_json_encode(
			[
				'_bec_sync_payload_error'   => 'json_encode_failed',
				'_bec_sync_payload_json_msg' => \function_exists('json_last_error_msg') ? \json_last_error_msg() : 'unknown',
			],
			\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
		);

		return $fallback !== false ? $fallback : '{}';
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

			return self::sanitizeForJson( (array) $v, false, $depth + 1 );
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
