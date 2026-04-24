<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

/**
 * Search / quote context parsed from URL query parameters.
 *
 * All public query keys use the `bec_` prefix to avoid collisions with WordPress
 * and other plugins. Supported keys:
 *
 * - `bec_checkin` — check-in date (Y-m-d or site-accepted string)
 * - `bec_checkout` — check-out date
 * - `bec_adults` — adult count (positive integer; used with `bec_children` in “breakdown” guest mode)
 * - `bec_children` — child count (non-negative integer)
 * - `bec_total_guests` — single guest / pax count when the active provider uses “total” guest mode (no adult/child split in the URL)
 * - `bec_child_age` — repeated query key (`bec_child_age[]=…`) for each child’s age when the active provider requires it
 * - `bec_rate_id` — optional rate plan id (provider-specific; e.g. Kross `id_rate`) to pick price from a multi-rate quote
 */
final class SearchContext
{
	public const PARAM_CHECKIN = 'bec_checkin';

	public const PARAM_CHECKOUT = 'bec_checkout';

	public const PARAM_ADULTS = 'bec_adults';

	public const PARAM_CHILDREN = 'bec_children';

	/** Set when the request encodes occupancy as a single number (“total” guest field mode; see `SearchGuestFieldMode::TOTAL`). */
	public const PARAM_TOTAL_GUESTS = 'bec_total_guests';

	/** Repeated in GET as `bec_child_age[]`. */
	public const PARAM_CHILD_AGE = 'bec_child_age';

	public const PARAM_RATE_ID = 'bec_rate_id';

	private string $checkin = '';

	private string $checkout = '';

	private int $adults = 0;

	private int $children = 0;

	/** @var list<int> Ages aligned with child index (0–17 typical). */
	private array $childrenAges = [];

	private string $rateId = '';

	/** When true, {@see toQueryArgs()} uses {@see PARAM_TOTAL_GUESTS} instead of {@see PARAM_ADULTS} / {@see PARAM_CHILDREN}. */
	private bool $storedAsTotalGuestQuery = false;

	private function __construct()
	{
	}

	public static function fromRequest(): self
	{
		$ctx       = new self();
		$ctx->checkin  = isset($_GET[self::PARAM_CHECKIN])
			? sanitize_text_field(wp_unslash((string) $_GET[self::PARAM_CHECKIN]))
			: '';
		$ctx->checkout = isset($_GET[self::PARAM_CHECKOUT])
			? sanitize_text_field(wp_unslash((string) $_GET[self::PARAM_CHECKOUT]))
			: '';

		if (isset($_GET[self::PARAM_ADULTS])) {
			$ctx->adults   = \max(0, \absint($_GET[self::PARAM_ADULTS]));
			$ctx->children = isset($_GET[self::PARAM_CHILDREN])
				? \max(0, \absint($_GET[self::PARAM_CHILDREN]))
				: 0;
			$ctx->storedAsTotalGuestQuery = false;
		} elseif (isset($_GET[self::PARAM_TOTAL_GUESTS])) {
			$total                      = \max(0, \absint($_GET[self::PARAM_TOTAL_GUESTS]));
			$ctx->adults                = $total;
			$ctx->children              = 0;
			$ctx->storedAsTotalGuestQuery = true;
		} else {
			$ctx->adults   = 0;
			$ctx->children = 0;
			$ctx->storedAsTotalGuestQuery = false;
		}

		$ctx->rateId = isset($_GET[self::PARAM_RATE_ID])
			? sanitize_text_field(wp_unslash((string) $_GET[self::PARAM_RATE_ID]))
			: '';

		$ctx->childrenAges = self::parseChildrenAgesFromRequest();

		return $ctx;
	}

	/**
	 * @return list<int>
	 */
	private static function parseChildrenAgesFromRequest(): array
	{
		if (! isset($_GET[self::PARAM_CHILD_AGE])) {
			return [];
		}

		$raw = $_GET[self::PARAM_CHILD_AGE];
		$out = [];

		if (\is_array($raw)) {
			foreach ($raw as $v) {
				$out[] = \max(0, \absint($v));
			}
		} else {
			$out[] = \max(0, \absint($raw));
		}

		return $out;
	}

	/**
	 * @return array<string, string|int> Non-empty values suitable for URL query building.
	 */
	public function toQueryArgs(): array
	{
		$args = [];

		if ($this->checkin !== '') {
			$args[self::PARAM_CHECKIN] = $this->checkin;
		}
		if ($this->checkout !== '') {
			$args[self::PARAM_CHECKOUT] = $this->checkout;
		}
		$pax = $this->adults + $this->children;
		if ($this->storedAsTotalGuestQuery) {
			if ($pax > 0) {
				$args[self::PARAM_TOTAL_GUESTS] = $pax;
			}
		} else {
			if ($this->adults > 0) {
				$args[self::PARAM_ADULTS] = $this->adults;
			}
			if ($this->children > 0) {
				$args[self::PARAM_CHILDREN] = $this->children;
			}
		}
		if ($this->rateId !== '') {
			$args[self::PARAM_RATE_ID] = $this->rateId;
		}
		$normAges = $this->normalizedChildrenAgesForProvider();
		if ($normAges !== []) {
			$args[self::PARAM_CHILD_AGE] = \array_map(
				static fn (int $a): string => (string) $a,
				$normAges
			);
		}

		return $args;
	}

	/**
	 * Appends {@see toQueryArgs()} to a URL (e.g. unit permalink) so navigation keeps search context.
	 */
	public function appendToUrl(string $url): string
	{
		$args = $this->toQueryArgs();
		if ($args === []) {
			return $url;
		}

		return (string) \add_query_arg($args, $url);
	}

	/**
	 * Whether the minimum fields needed to request a quote are present.
	 *
	 * Requires non-empty check-in and check-out and at least one adult.
	 */
	public function isComplete(): bool
	{
		return $this->checkin !== ''
			&& $this->checkout !== ''
			&& $this->adults > 0;
	}

	public function getCheckin(): string
	{
		return $this->checkin;
	}

	public function getCheckout(): string
	{
		return $this->checkout;
	}

	public function getAdults(): int
	{
		return $this->adults;
	}

	public function getChildren(): int
	{
		return $this->children;
	}

	/**
	 * Per-child ages from `bec_child_age[]` (same order as children count when validated).
	 *
	 * @return list<int>
	 */
	public function getChildrenAges(): array
	{
		return $this->childrenAges;
	}

	/**
	 * One age per child, padded with 0 when fewer values were submitted.
	 *
	 * @return list<int>
	 */
	public function normalizedChildrenAgesForProvider(): array
	{
		if ($this->children < 1) {
			return [];
		}

		$ages = $this->childrenAges;
		while (\count($ages) < $this->children) {
			$ages[] = 0;
		}

		return \array_values(\array_slice($ages, 0, $this->children));
	}

	/**
	 * Selected rate id from the request (`bec_rate_id`), or empty for provider default.
	 */
	public function getRateId(): string
	{
		return $this->rateId;
	}

	public function isStoredAsTotalGuestQuery(): bool
	{
		return $this->storedAsTotalGuestQuery;
	}

	/**
	 * Payload for {@see ProviderInterface::getQuoteForUnit()}.
	 *
	 * @return array{checkin: string, checkout: string, adults: int, children: int, guests?: int, children_ages?: list<int>, rate_id?: string}
	 */
	public function toProviderSearchContext(): array
	{
		$out = [
			'checkin'  => $this->checkin,
			'checkout' => $this->checkout,
			'adults'   => $this->adults,
			'children' => $this->children,
		];
		$pax = $this->adults + $this->children;
		if ($pax > 0) {
			$out['guests'] = $pax;
		}
		if ($this->children > 0) {
			$out['children_ages'] = $this->normalizedChildrenAgesForProvider();
		}
		if ($this->rateId !== '') {
			$out['rate_id'] = $this->rateId;
		}

		return $out;
	}
}
