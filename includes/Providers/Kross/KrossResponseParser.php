<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Parses Kross v5 JSON: {@see data} / {@see ruid} on success; {@see error_code} / {@see error_message} on failure;
 * optional legacy {@see result}; JSON strings inside values.
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
		if (isset($decoded['error_code'])) {
			return false;
		}

		if (! isset($decoded['result'])) {
			return true;
		}

		return $decoded['result'] === true || $decoded['result'] === 1 || $decoded['result'] === '1';
	}

	/**
	 * v5 error bodies include {@see error_message} and optional {@see ruid} for support.
	 *
	 * @param array<string, mixed> $decoded
	 */
	public static function getApiErrorMessage(array $decoded): string
	{
		$msg = isset($decoded['error_message']) && \is_string($decoded['error_message'])
			? \trim($decoded['error_message'])
			: '';

		$ruid = isset($decoded['ruid']) && \is_string($decoded['ruid']) ? \trim($decoded['ruid']) : '';

		if ($msg !== '' && $ruid !== '') {
			return $msg . ' ' . \sprintf(
				/* translators: %s Kross request unique id (RUID) */
				\__('(RUID: %s)', 'booking-engine-connector'),
				$ruid
			);
		}

		if ($msg !== '') {
			return $msg;
		}

		if ($ruid !== '') {
			return \sprintf(
				/* translators: %s Kross request unique id (RUID) */
				\__('Kross error (RUID: %s).', 'booking-engine-connector'),
				$ruid
			);
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $decoded
	 */
	public static function getErrorCode(array $decoded): ?int
	{
		if (! isset($decoded['error_code'])) {
			return null;
		}

		return \is_int($decoded['error_code']) ? $decoded['error_code'] : (int) $decoded['error_code'];
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
