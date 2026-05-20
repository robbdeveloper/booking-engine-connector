<?php

declare(strict_types=1);

namespace BookingEngineConnector\UnitFilters;

/**
 * Parses and sanitizes unit filter GET parameters.
 */
final class UnitFilterRequest
{
	public const PARAM_ORDER = 'bec_filter_order';

	public const PARAM_ROOMS_MIN = 'bec_filter_rooms_min';

	public const PARAM_BATHROOMS_MIN = 'bec_filter_bathrooms_min';

	public const PARAM_AMENITIES = 'bec_filter_amenities';

	private string $order = '';

	private int $roomsMin = 0;

	private float $bathroomsMin = 0.0;

	/** @var list<string> */
	private array $amenityKeys = [];

	private function __construct()
	{
	}

	public static function fromRequest(): self
	{
		$req = new self();

		if (isset($_GET[self::PARAM_ORDER])) {
			$raw = \strtoupper(\sanitize_key(\wp_unslash((string) $_GET[self::PARAM_ORDER])));
			if ($raw === 'ASC' || $raw === 'DESC') {
				$req->order = $raw;
			}
		}

		if (isset($_GET[self::PARAM_ROOMS_MIN])) {
			$req->roomsMin = \max(0, \absint($_GET[self::PARAM_ROOMS_MIN]));
		}

		if (isset($_GET[self::PARAM_BATHROOMS_MIN])) {
			$raw = \wp_unslash((string) $_GET[self::PARAM_BATHROOMS_MIN]);
			$raw = \str_replace(',', '.', $raw);
			if (\is_numeric($raw)) {
				$req->bathroomsMin = \max(0.0, (float) $raw);
			}
		}

		$req->amenityKeys = self::parseAmenityKeysFromRequest();

		return $req;
	}

	/**
	 * @return list<string>
	 */
	private static function parseAmenityKeysFromRequest(): array
	{
		if (! isset($_GET[self::PARAM_AMENITIES])) {
			return [];
		}

		$raw = $_GET[self::PARAM_AMENITIES];
		$keys = [];
		if (\is_array($raw)) {
			foreach ($raw as $v) {
				$key = \sanitize_key(\wp_unslash((string) $v));
				if ($key !== '') {
					$keys[] = $key;
				}
			}
		} else {
			$key = \sanitize_key(\wp_unslash((string) $raw));
			if ($key !== '') {
				$keys[] = $key;
			}
		}

		return \array_values(\array_unique($keys));
	}

	public function getOrder(): string
	{
		return $this->order;
	}

	public function getRoomsMin(): int
	{
		return $this->roomsMin;
	}

	public function getBathroomsMin(): float
	{
		return $this->bathroomsMin;
	}

	/**
	 * @return list<string>
	 */
	public function getAmenityKeys(): array
	{
		return $this->amenityKeys;
	}

	public function hasActiveFilters(): bool
	{
		return $this->order !== ''
			|| $this->roomsMin > 0
			|| $this->bathroomsMin > 0.0
			|| $this->amenityKeys !== [];
	}

	/**
	 * @return array<string, string|list<string>>
	 */
	public function toQueryArgs(): array
	{
		$args = [];

		if ($this->order !== '') {
			$args[self::PARAM_ORDER] = $this->order;
		}
		if ($this->roomsMin > 0) {
			$args[self::PARAM_ROOMS_MIN] = (string) $this->roomsMin;
		}
		if ($this->bathroomsMin > 0.0) {
			$args[self::PARAM_BATHROOMS_MIN] = self::formatBathroomsMin($this->bathroomsMin);
		}
		if ($this->amenityKeys !== []) {
			$args[self::PARAM_AMENITIES] = $this->amenityKeys;
		}

		return $args;
	}

	public static function formatBathroomsMin(float $value): string
	{
		if ($value === (float) (int) $value) {
			return (string) (int) $value;
		}

		return \rtrim(\rtrim(\sprintf('%.2f', $value), '0'), '.');
	}

	/**
	 * Query arg names owned by unit filters (excluded from preserved hidden fields).
	 *
	 * @return list<string>
	 */
	public static function filterParamNames(): array
	{
		return [
			self::PARAM_ORDER,
			self::PARAM_ROOMS_MIN,
			self::PARAM_BATHROOMS_MIN,
			self::PARAM_AMENITIES,
		];
	}
}
