<?php

declare(strict_types=1);

namespace BookingEngineConnector\Formatting;

/**
 * Formats monetary amounts for display (number separators, currency code/symbol, position).
 */
final class MoneyFormatter
{
	/** @var array<string, string> */
	private const DEFAULT_SYMBOLS = [
		'EUR' => '€',
		'USD' => '$',
		'GBP' => '£',
		'CHF' => 'CHF',
		'JPY' => '¥',
		'CNY' => '¥',
		'AUD' => 'A$',
		'CAD' => 'C$',
		'NZD' => 'NZ$',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'PLN' => 'zł',
		'CZK' => 'Kč',
	];

	/**
	 * @param array<string, mixed> $options
	 *   - currency_display: code|symbol (default code)
	 *   - currency_position: after|before (default after)
	 *   - decimals: int 0–4 (default 2)
	 *   - decimal_sep: string|null — when set with thousands_sep, uses number_format()
	 *   - thousands_sep: string|null
	 *   - number_style: locale|eu|us (default locale)
	 */
	public static function format(float $amount, string $currency, array $options = []): string
	{
		$opts     = self::normalizeOptions($options);
		$decimals = (int) ($opts['decimals'] ?? 2);
		if ($decimals < 0) {
			$decimals = 0;
		}
		if ($decimals > 4) {
			$decimals = 4;
		}

		$decimalSep   = $opts['decimal_sep'] ?? null;
		$thousandsSep = $opts['thousands_sep'] ?? null;

		if ($decimalSep !== null && $decimalSep !== '' && $thousandsSep !== null && $thousandsSep !== '') {
			$num = \number_format($amount, $decimals, (string) $decimalSep, (string) $thousandsSep);
		} else {
			$num = \number_format_i18n($amount, $decimals);
		}

		$currency = \trim($currency);
		if ($currency === '') {
			$formatted = $num;
		} else {
			$display = self::resolveCurrencyDisplay($currency, (string) ($opts['currency_display'] ?? 'code'));
			$pos     = \strtolower((string) ($opts['currency_position'] ?? 'after')) === 'before' ? 'before' : 'after';
			$formatted = $pos === 'before'
				? $display . ' ' . $num
				: $num . ' ' . $display;
		}

		/** @var string $formatted */
		$formatted = (string) \apply_filters('bec_format_money', $formatted, $amount, $currency, $opts);

		return $formatted;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private static function normalizeOptions(array $options): array
	{
		$style = \strtolower(\trim((string) ($options['number_style'] ?? 'locale')));
		if ($style === 'eu') {
			$options['decimal_sep']   = $options['decimal_sep'] ?? ',';
			$options['thousands_sep'] = $options['thousands_sep'] ?? '.';
		} elseif ($style === 'us') {
			$options['decimal_sep']   = $options['decimal_sep'] ?? '.';
			$options['thousands_sep'] = $options['thousands_sep'] ?? ',';
		}

		$dec = $options['decimal_sep'] ?? null;
		$tho = $options['thousands_sep'] ?? null;
		if ($dec === '') {
			$options['decimal_sep'] = null;
		}
		if ($tho === '') {
			$options['thousands_sep'] = null;
		}

		// Only use explicit separators when both are non-empty.
		if (($options['decimal_sep'] ?? null) === null || ($options['thousands_sep'] ?? null) === null) {
			$options['decimal_sep']   = null;
			$options['thousands_sep'] = null;
		}

		return $options;
	}

	private static function resolveCurrencyDisplay(string $currency, string $mode): string
	{
		$code = \strtoupper(\trim($currency));
		if ($code === '') {
			return '';
		}

		$mode = \strtolower(\trim($mode));
		if ($mode !== 'symbol') {
			return $code;
		}

		if ($code === '€' || $code === 'EUR') {
			return '€';
		}

		/** @var array<string, string> $symbols */
		$symbols = (array) \apply_filters('bec_currency_symbols', self::DEFAULT_SYMBOLS);
		if (isset($symbols[ $code ]) && $symbols[ $code ] !== '') {
			return (string) $symbols[ $code ];
		}

		return $code;
	}
}
