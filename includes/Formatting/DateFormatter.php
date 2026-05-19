<?php

declare(strict_types=1);

namespace BookingEngineConnector\Formatting;

/**
 * Formats booking dates (Y-m-d) for display with locale-aware month and weekday names.
 */
final class DateFormatter
{
	/** @var array<string, string> */
	private const DEFAULT_PRESETS = [
		'iso'    => 'Y-m-d',
		'short'  => 'd M',
		'medium' => 'j M Y',
		'long'   => 'j F Y',
		'full'   => 'l, j F Y',
	];

	/**
	 * @param array<string, mixed> $options
	 *   - date_format: string|null — PHP date format for date_i18n(); wins over preset
	 *   - preset: string — iso|short|medium|long|full (default iso)
	 *   - label_style: string — arrow|from_to|from_to_lower or custom via bec_date_range_label_styles
	 *   - label: string|null — literal sprintf pattern; overrides label_style when non-empty
	 */
	public static function format(string $ymd, array $options = []): string
	{
		$ymd = \trim($ymd);
		if ($ymd === '') {
			return '';
		}

		$dt = \DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
		if ($dt === false) {
			return $ymd;
		}

		$format = self::resolveDateFormat($options);
		$formatted = (string) \date_i18n($format, (int) $dt->getTimestamp());

		/** @var string $formatted */
		$formatted = (string) \apply_filters('bec_format_date', $formatted, $ymd, $options);

		return $formatted;
	}

	/**
	 * @param array<string, mixed> $options Same keys as {@see format()}.
	 */
	public static function formatRange(string $checkin, string $checkout, array $options = []): string
	{
		$in  = self::format($checkin, $options);
		$out = self::format($checkout, $options);

		$label = self::resolveLabelTemplate($options);
		$text  = \sprintf($label, $in, $out);

		/** @var string $text */
		$text = (string) \apply_filters('bec_format_date_range', $text, $checkin, $checkout, $options);

		return $text;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private static function resolveDateFormat(array $options): string
	{
		$explicit = \trim((string) ($options['date_format'] ?? ''));
		if ($explicit !== '') {
			return $explicit;
		}

		$preset = \sanitize_key(\strtolower(\trim((string) ($options['preset'] ?? 'iso'))));
		if ($preset === '') {
			$preset = 'iso';
		}

		/** @var array<string, string> $presets */
		$presets = (array) \apply_filters('bec_date_format_presets', self::DEFAULT_PRESETS);

		if (isset($presets[ $preset ]) && $presets[ $preset ] !== '') {
			return (string) $presets[ $preset ];
		}

		return self::DEFAULT_PRESETS['iso'];
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private static function resolveLabelTemplate(array $options): string
	{
		$literal = \trim((string) ($options['label'] ?? ''));
		if ($literal !== '') {
			return $literal;
		}

		$style = \sanitize_key(\strtolower(\trim((string) ($options['label_style'] ?? 'arrow'))));
		if ($style === '') {
			$style = 'arrow';
		}

		$styles = self::defaultLabelStyles();

		if (isset($styles[ $style ])) {
			return (string) $styles[ $style ];
		}

		return (string) ($styles['arrow'] ?? '%1$s → %2$s');
	}

	/**
	 * @return array<string, string>
	 */
	private static function defaultLabelStyles(): array
	{
		$builtin = [
			'arrow' => \__(
				'%1$s → %2$s',
				'booking-engine-connector'
			),
			'from_to' => \__(
				'From %1$s to %2$s',
				'booking-engine-connector'
			),
			'from_to_lower' => \__(
				'from %1$s to %2$s',
				'booking-engine-connector'
			),
		];

		/** @var array<string, string> $styles */
		$styles = (array) \apply_filters('bec_date_range_label_styles', $builtin);

		return $styles;
	}
}
