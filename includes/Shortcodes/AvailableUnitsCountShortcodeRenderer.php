<?php

declare(strict_types=1);

namespace BookingEngineConnector\Shortcodes;

use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\UnitFilters\UnitResultCountService;

/**
 * Renders `[bec_available_units_count]` — count of units matching filters and availability.
 */
final class AvailableUnitsCountShortcodeRenderer
{
	/**
	 * @param array<string, string> $atts
	 */
	public static function render(array $atts): string
	{
		$a = \shortcode_atts(
			[
				'format'              => 'number',
				'zero_text'           => '',
				'singular'            => '',
				'plural'              => '',
				'hide_without_search' => '0',
				'class'               => '',
			],
			$atts,
			'bec_available_units_count'
		);

		$hideWithoutSearch = self::isTruthy((string) $a['hide_without_search']);
		$ctx               = SearchContext::fromRequest();
		if ($hideWithoutSearch && ! $ctx->isComplete()) {
			return '';
		}

		$loopQuery = null;
		global $wp_query;
		if ($wp_query instanceof \WP_Query) {
			$loopQuery = $wp_query;
		}

		$count = UnitResultCountService::getCount($loopQuery);

		$format = \sanitize_key(\strtolower(\trim((string) $a['format'])));
		if ($format !== 'text') {
			$format = 'number';
		}

		$text = self::formatOutput($count, $format, $a);
		if ($text === '') {
			return '';
		}

		$classes = 'bec-available-units-count';
		$extra   = \trim((string) $a['class']);
		if ($extra !== '') {
			foreach (\preg_split('/\s+/', $extra) ?: [] as $part) {
				$part = \sanitize_html_class($part);
				if ($part !== '') {
					$classes .= ' ' . $part;
				}
			}
		}

		$html = '<span class="' . \esc_attr($classes) . '">' . \esc_html($text) . '</span>';

		return (string) \apply_filters('bec_shortcode_available_units_count_html', $html, $count, $a, $ctx);
	}

	/**
	 * @param array<string, string> $a
	 */
	private static function formatOutput(int $count, string $format, array $a): string
	{
		if ($count === 0) {
			$zero = \trim((string) ($a['zero_text'] ?? ''));
			if ($zero !== '') {
				return $zero;
			}
			if ($format === 'number') {
				return '0';
			}

			return '';
		}

		if ($format === 'number') {
			return (string) $count;
		}

		$singular = \trim((string) ($a['singular'] ?? ''));
		$plural   = \trim((string) ($a['plural'] ?? ''));

		if ($singular === '' && $plural === '') {
			$singular = \__('%d available unit', 'booking-engine-connector');
			$plural   = \__('%d available units', 'booking-engine-connector');
		}

		$pattern = $count === 1 ? $singular : $plural;
		if ($pattern === '') {
			$pattern = $count === 1 ? '%d available unit' : '%d available units';
		}

		return (string) \sprintf($pattern, $count);
	}

	private static function isTruthy(string $value): bool
	{
		$v = \strtolower(\trim($value));

		return $v === '1' || $v === 'yes' || $v === 'true' || $v === 'on';
	}
}
