<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

/**
 * Resolves scalar fields from the Kross `raw` room-type object for `[bec_unit_field]`.
 *
 * Paths are dot-separated under `raw` (e.g. `cin`, `custom_fields.custom_1.it`, `images.0.url`).
 * The path must end on a scalar; locale maps require an explicit locale segment.
 *
 * Filters: {@see bec_kross_unit_field_value}.
 */
final class KrossUnitFieldResolver
{
	public const TYPE_STRING = 'string';

	public const TYPE_NUMBER = 'number';

	/**
	 * @param array<string, mixed> $syncPayload Decoded `bec_sync_payload` (normalised row with `raw`).
	 * @param array<string, string> $atts        Shortcode attributes (field, type, …).
	 * @param array{provider: string, locale: string, type: string} $context
	 */
	public static function resolve(array $syncPayload, string $field, array $atts, array $context): string|int|float|null
	{
		if (! self::isValidFieldPath($field)) {
			return null;
		}

		$raw = $syncPayload['raw'] ?? null;
		if (! \is_array($raw)) {
			return null;
		}

		$resolved = self::getRawValueAtPath($raw, $field);

		$type  = self::normalizeType($context['type'] ?? self::TYPE_STRING);
		$value = self::coerceScalar($resolved, $type);

		if ($value === null) {
			return null;
		}

		/** @var string|int|float|null $filtered */
		$filtered = \apply_filters(
			'bec_kross_unit_field_value',
			$value,
			$field,
			$syncPayload,
			$atts,
			$context
		);

		return self::coerceScalar($filtered, $type);
	}

	/**
	 * @deprecated Use {@see isValidFieldPath()}.
	 */
	public static function isValidFieldName(string $field): bool
	{
		return self::isValidFieldPath($field);
	}

	public static function isValidFieldPath(string $field): bool
	{
		$segments = self::parseFieldPath($field);

		return $segments !== null;
	}

	public static function normalizeType(string $type): string
	{
		$t = \sanitize_key(\strtolower(\trim($type)));

		return $t === self::TYPE_NUMBER ? self::TYPE_NUMBER : self::TYPE_STRING;
	}

	/**
	 * @param array<string|int, mixed> $raw
	 */
	private static function getRawValueAtPath(array $raw, string $path): mixed
	{
		$segments = self::parseFieldPath($path);
		if ($segments === null) {
			return null;
		}

		$node = $raw;
		foreach ($segments as $segment) {
			if (! \is_array($node)) {
				return null;
			}

			$key = self::segmentToArrayKey($segment);
			if (! \array_key_exists($key, $node)) {
				return null;
			}

			$node = $node[ $key ];
		}

		return $node;
	}

	/**
	 * @return list<string>|null
	 */
	private static function parseFieldPath(string $path): ?array
	{
		$path = \trim($path);
		if ($path === '') {
			return null;
		}

		$parts = \explode('.', $path);
		$out   = [];

		foreach ($parts as $part) {
			$part = \trim($part);
			if ($part === '' || ! self::isValidPathSegment($part)) {
				return null;
			}
			$out[] = $part;
		}

		return $out === [] ? null : $out;
	}

	private static function isValidPathSegment(string $segment): bool
	{
		if (\preg_match('/^[0-9]+$/', $segment) === 1) {
			return true;
		}

		return (bool) \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $segment);
	}

	private static function segmentToArrayKey(string $segment): string|int
	{
		if (\preg_match('/^[0-9]+$/', $segment) === 1) {
			return (int) $segment;
		}

		return $segment;
	}

	/**
	 * @param mixed $value
	 */
	public static function coerceScalar($value, string $type): string|int|float|null
	{
		if ($value === null) {
			return null;
		}

		if (\is_bool($value) || \is_array($value) || \is_object($value) || \is_resource($value)) {
			return null;
		}

		if ($type === self::TYPE_NUMBER) {
			return self::coerceNumber($value);
		}

		return self::coerceString($value);
	}

	/**
	 * @param mixed $value
	 */
	private static function coerceNumber($value): int|float|string|null
	{
		if (\is_int($value)) {
			return $value;
		}

		if (\is_float($value)) {
			return \is_finite($value) ? $value : null;
		}

		if (\is_string($value)) {
			$trim = \trim($value);
			if ($trim === '' || ! \is_numeric($trim)) {
				return null;
			}

			return \str_contains($trim, '.') || \stripos($trim, 'e') !== false
				? (float) $trim
				: (int) $trim;
		}

		return null;
	}

	/**
	 * @param mixed $value
	 */
	private static function coerceString($value): ?string
	{
		if (! \is_string($value)) {
			return null;
		}

		return $value;
	}
}
