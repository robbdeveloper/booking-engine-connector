<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Parses typical Kross v4 JSON: optional {@see result}, nested {@see data}, JSON strings inside values.
 */
final class KrossResponseParser
{
	/**
	 * @return array<string, mixed>
	 */
	public static function decodeBody(string $json): array
	{
		$decoded = \json_decode($json, true);

		return \is_array($decoded) ? self::decodeJsonStringsRecursive($decoded) : [];
	}

	/**
	 * @param array<string, mixed> $decoded
	 * @return array<string, mixed>
	 */
	public static function decodeJsonStringsRecursive(array $decoded): array
	{
		foreach ($decoded as $k => $v) {
			if (\is_string($v)) {
				$t = \trim($v);
				if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
					$inner = \json_decode($t, true);
					if (\json_last_error() === JSON_ERROR_NONE) {
						$decoded[ $k ] = \is_array($inner) ? self::decodeJsonStringsRecursive($inner) : $inner;
					}
				}
			} elseif (\is_array($v)) {
				$decoded[ $k ] = self::decodeJsonStringsRecursive($v);
			}
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $decoded
	 */
	public static function isSuccess(array $decoded): bool
	{
		if (! isset($decoded['result'])) {
			return true;
		}

		return $decoded['result'] === true || $decoded['result'] === 1 || $decoded['result'] === '1';
	}

	/**
	 * @param array<string, mixed> $decoded
	 * @return array<int|string, mixed>
	 */
	public static function getDataPayload(array $decoded): array
	{
		if (! isset($decoded['data'])) {
			return [];
		}

		$data = $decoded['data'];

		return \is_array($data) ? $data : [];
	}
}
