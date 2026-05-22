<?php

declare(strict_types=1);

namespace BookingEngineConnector\Formatting;

/**
 * Maps PHP {@see date_i18n()} format tokens to Moment.js display tokens for the date range picker footer.
 */
final class MomentFormatMapper
{
	/** @var array<string, string> */
	private const PRESET_MOMENT_FORMATS = [
		'iso'    => 'YYYY-MM-DD',
		'short'  => 'DD MMM',
		'medium' => 'D MMM YYYY',
		'long'   => 'D MMMM YYYY',
		'full'   => 'dddd, D MMMM YYYY',
	];

	/** @var array<string, string> */
	private const PHP_TOKEN_TO_MOMENT = [
		'd' => 'DD',
		'D' => 'ddd',
		'j' => 'D',
		'l' => 'dddd',
		'N' => 'E',
		'S' => 'o',
		'w' => 'e',
		'z' => 'DDD',
		'W' => 'W',
		'F' => 'MMMM',
		'm' => 'MM',
		'M' => 'MMM',
		'n' => 'M',
		'o' => 'GGGG',
		'Y' => 'YYYY',
		'y' => 'YY',
		'a' => 'a',
		'A' => 'A',
		'g' => 'h',
		'G' => 'H',
		'h' => 'hh',
		'H' => 'HH',
		'i' => 'mm',
		's' => 'ss',
		'e' => 'zz',
		'I' => 'ZZ',
		'O' => 'ZZ',
		'P' => 'Z',
		'T' => 'z',
		'Z' => 'ZZ',
	];

	public static function fromPreset(string $preset): string
	{
		$key = \sanitize_key(\strtolower(\trim($preset)));
		if ($key === '') {
			$key = 'medium';
		}

		/** @var array<string, string> $presets */
		$presets = (array) \apply_filters('bec_daterange_moment_format_presets', self::PRESET_MOMENT_FORMATS);

		if (isset($presets[ $key ]) && $presets[ $key ] !== '') {
			return (string) $presets[ $key ];
		}

		return self::PRESET_MOMENT_FORMATS['medium'];
	}

	public static function fromPhpFormat(string $phpFormat): string
	{
		$phpFormat = \trim($phpFormat);
		if ($phpFormat === '') {
			return self::PRESET_MOMENT_FORMATS['medium'];
		}

		$len    = \strlen($phpFormat);
		$moment = '';
		$i      = 0;

		while ($i < $len) {
			$char = $phpFormat[ $i ];

			if ($char === '\\' && $i + 1 < $len) {
				$moment .= '[' . $phpFormat[ $i + 1 ] . ']';
				$i += 2;
				continue;
			}

			if (isset(self::PHP_TOKEN_TO_MOMENT[ $char ])) {
				$moment .= self::PHP_TOKEN_TO_MOMENT[ $char ];
				++$i;
				continue;
			}

			if (\ctype_alnum($char)) {
				$moment .= '[' . $char . ']';
			} else {
				$moment .= $char;
			}
			++$i;
		}

		/** @var string $moment */
		$moment = (string) \apply_filters('bec_php_date_format_to_moment', $moment, $phpFormat);

		return $moment;
	}

	/**
	 * @param array<string, mixed> $options
	 *   - daterange_format: string|null — PHP date format for date_i18n(); wins over preset
	 *   - daterange_preset: string — iso|short|medium|long|full (default medium)
	 */
	public static function resolveDisplayFormat(array $options): string
	{
		$explicit = \trim((string) ($options['daterange_format'] ?? ''));
		if ($explicit !== '') {
			return self::fromPhpFormat($explicit);
		}

		$preset = \sanitize_key(\strtolower(\trim((string) ($options['daterange_preset'] ?? 'medium'))));
		if ($preset === '') {
			$preset = 'medium';
		}

		return self::fromPreset($preset);
	}
}
