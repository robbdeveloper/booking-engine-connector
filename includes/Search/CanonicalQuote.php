<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

/**
 * Normalises provider-specific quote payloads into a shared shape so UI layers
 * can show rates and the price for the current {@see SearchContext} without
 * branching on the active provider.
 *
 * Canonical keys (when applicable):
 * - `rates` — list of `array{id: string, label: string, amount: ?float, currency: ?string, raw?: mixed}` (Kross: one entry per
 *   `calendar/book` row for the room type; multiple rows differ by `id_rate` / price)
 * - `selected_rate` — one entry from `rates` (from `bec_rate_id`, else lowest `amount` when present)
 * - `price` — `array{amount: ?float, currency: ?string, label: ?string}` shortcut for the selected rate
 */
final class CanonicalQuote
{
	/**
	 * @param mixed $quote Provider quote or cached payload
	 * @return mixed
	 */
	public static function enrich($quote, string $providerSlug, SearchContext $ctx)
	{
		if (! \is_array($quote)) {
			return $quote;
		}

		if ($providerSlug === 'kross') {
			$quote = self::enrichKross($quote);
		}

		if (isset($quote['rates']) && \is_array($quote['rates']) && $quote['rates'] !== []) {
			$quote['rates'] = self::sortRatesByAmount(self::dedupeRatesById($quote['rates']));

			return self::applySelection($quote, $ctx);
		}

		return $quote;
	}

	/**
	 * @param array<string, mixed> $quote
	 * @return array<string, mixed>
	 */
	private static function enrichKross(array $quote): array
	{
		if (isset($quote['rates']) && \is_array($quote['rates']) && $quote['rates'] !== []) {
			return $quote;
		}

		$rows = $quote['rows'] ?? [];
		if (! \is_array($rows) || $rows === []) {
			return $quote;
		}

		$rates = [];
		foreach ($rows as $row) {
			if (! \is_array($row)) {
				continue;
			}
			$id = (string) ($row['id_rate'] ?? '');
			if ($id === '') {
				continue;
			}
			$amount = $row['amount'] ?? null;
			$amount = \is_numeric($amount) ? (float) $amount : null;
			$currency = isset($row['currency']) ? \trim((string) $row['currency']) : '';
			$label = self::pickLocalizedLabel($row['name_rate'] ?? null);
			if ($label === '') {
				$label = $id;
			}

			$rates[] = [
				'id'       => $id,
				'label'    => $label,
				'amount'   => $amount,
				'currency' => $currency !== '' ? $currency : null,
				'raw'      => $row,
			];
		}

		$quote['rates'] = $rates;

		return $quote;
	}

	/**
	 * Stable order: lowest amount first (typical “from” price); ties broken by id.
	 *
	 * @param list<array<string, mixed>> $rates
	 * @return list<array<string, mixed>>
	 */
	private static function sortRatesByAmount(array $rates): array
	{
		\usort(
			$rates,
			static function (array $a, array $b): int {
				$aa = $a['amount'] ?? null;
				$bb = $b['amount'] ?? null;
				if (! \is_numeric($aa) && ! \is_numeric($bb)) {
					return \strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
				}
				if (! \is_numeric($aa)) {
					return 1;
				}
				if (! \is_numeric($bb)) {
					return -1;
				}
				$cmp = ((float) $aa) <=> ((float) $bb);
				if ($cmp !== 0) {
					return $cmp;
				}

				return \strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
			}
		);

		return $rates;
	}

	/**
	 * @param list<array<string, mixed>> $rates
	 * @return list<array<string, mixed>>
	 */
	private static function dedupeRatesById(array $rates): array
	{
		$seen = [];
		$out  = [];

		foreach ($rates as $r) {
			$id = isset($r['id']) ? (string) $r['id'] : '';
			if ($id === '' || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$out[]     = $r;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $quote
	 * @return array<string, mixed>
	 */
	private static function applySelection(array $quote, SearchContext $ctx): array
	{
		$rates = $quote['rates'] ?? [];
		if (! \is_array($rates) || $rates === []) {
			$quote['selected_rate'] = null;
			$quote['price']         = [
				'amount'   => null,
				'currency' => null,
				'label'    => null,
			];

			return $quote;
		}

		$wanted   = $ctx->getRateId();
		$selected = null;
		if ($wanted !== '') {
			foreach ($rates as $r) {
				if (! \is_array($r)) {
					continue;
				}
				if (isset($r['id']) && (string) $r['id'] === $wanted) {
					$selected = $r;
					break;
				}
			}
		}

		/**
		 * No `bec_rate_id`: use first rate (after Kross sort, lowest price when amounts exist).
		 */
		if ($selected === null) {
			$first = $rates[0];
			$selected = \is_array($first) ? $first : null;
		}

		$quote['selected_rate'] = $selected;

		if ($selected !== null && \is_array($selected)) {
			$amt = $selected['amount'] ?? null;
			$quote['price'] = [
				'amount'   => \is_numeric($amt) ? (float) $amt : null,
				'currency' => isset($selected['currency']) && $selected['currency'] !== null
					? (string) $selected['currency']
					: null,
				'label'    => isset($selected['label']) ? (string) $selected['label'] : null,
			];
		} else {
			$quote['price'] = [
				'amount'   => null,
				'currency' => null,
				'label'    => null,
			];
		}

		return $quote;
	}

	private static function pickLocalizedLabel($value): string
	{
		if (\is_string($value)) {
			return \trim($value);
		}
		if (! \is_array($value)) {
			return '';
		}

		if (isset($value['main']) && \is_string($value['main']) && $value['main'] !== '') {
			return \trim($value['main']);
		}

		$lang = \substr(\get_locale(), 0, 2);
		if (isset($value[$lang]) && \is_string($value[$lang])) {
			return \trim($value[$lang]);
		}

		$det = \function_exists('determine_locale') ? \determine_locale() : '';
		if ($det !== '') {
			$short = \substr($det, 0, 2);
			if (isset($value[$short]) && \is_string($value[$short])) {
				return \trim($value[$short]);
			}
		}

		foreach ($value as $v) {
			if (\is_string($v) && $v !== '') {
				return \trim($v);
			}
		}

		return '';
	}
}
