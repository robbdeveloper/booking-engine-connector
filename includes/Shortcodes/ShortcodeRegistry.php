<?php

declare(strict_types=1);

namespace BookingEngineConnector\Shortcodes;

use BookingEngineConnector\Checkout\CheckoutCtaHtml;
use BookingEngineConnector\Checkout\CheckoutUrlService;
use BookingEngineConnector\Fallback\FallbackRenderer;
use BookingEngineConnector\Fallback\FallbackService;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Search\SearchForm;
use BookingEngineConnector\Shortcodes\BookingSummary\BookingSummaryRenderer;

/**
 * Public shortcodes (TASK-SHO-001).
 */
final class ShortcodeRegistry
{
	public static function register(): void
	{
		\add_shortcode('bec_version', [self::class, 'renderVersion']);
		\add_shortcode('bec_search', [self::class, 'renderSearch']);
		\add_shortcode('bec_dates', [self::class, 'renderDates']);
		\add_shortcode('bec_checkout', [self::class, 'renderCheckout']);
		\add_shortcode('bec_quote', [self::class, 'renderQuote']);
		\add_shortcode('bec_fallback', [self::class, 'renderFallback']);
		\add_shortcode('bec_unit_url', [self::class, 'renderUnitUrl']);
		\add_shortcode('bec_unit_info', [self::class, 'renderUnitInfo']);
		\add_shortcode('bec_booking_summary', [BookingSummaryRenderer::class, 'renderFromShortcode']);
	}

	public static function renderVersion(): string
	{
		return \esc_html((string) \BEC_VERSION);
	}

	public static function renderSearch($atts = []): string
	{
		$a = \shortcode_atts(
			[
				'context' => 'shortcode',
				'form_id' => 'bec-search-form-sc',
			],
			\is_array($atts) ? $atts : [],
			'bec_search'
		);

		if (FallbackService::isAlwaysOn()) {
			return FallbackRenderer::render();
		}

		\ob_start();
		SearchForm::render(
			[
				'context' => (string) $a['context'],
				'form_id' => (string) $a['form_id'],
			]
		);

		return (string) \ob_get_clean();
	}

	public static function renderDates(): string
	{
		$ctx = SearchContext::fromRequest();
		if (! $ctx->isComplete()) {
			return '';
		}

		$fmt = (string) \apply_filters('bec_shortcode_dates_format', '', $ctx);
		if ($fmt !== '') {
			return '<p class="bec-shortcode-dates">' . \esc_html($fmt) . '</p>';
		}

		$out = \sprintf(
			/* translators: 1: check-in date, 2: check-out date */
			\esc_html__('%1$s → %2$s', 'booking-engine-connector'),
			\esc_html($ctx->getCheckin()),
			\esc_html($ctx->getCheckout())
		);

		return '<p class="bec-shortcode-dates">' . $out . '</p>';
	}

	public static function renderCheckout($atts = []): string
	{
		$a = \shortcode_atts(
			['unit_id' => '0'],
			\is_array($atts) ? $atts : [],
			'bec_checkout'
		);

		$postId = (int) $a['unit_id'];
		if ($postId < 1) {
			$postId = (int) \get_the_ID();
		}
		if ($postId < 1 || \get_post_type($postId) !== UnitPostType::getSlug()) {
			return '';
		}

		if (FallbackService::isAlwaysOn()) {
			return '';
		}

		$ctx = SearchContext::fromRequest();
		if (! $ctx->isComplete()) {
			return '';
		}

		$urlData = CheckoutUrlService::buildForPost($postId, $ctx);
		if ($urlData === null || ! isset($urlData['url']) || (string) $urlData['url'] === '') {
			return '';
		}

		$cta = CheckoutCtaHtml::renderCta($urlData);
		if ($cta === '') {
			return '';
		}

		return '<div class="bec-shortcode-checkout">' . $cta . '</div>';
	}

	public static function renderQuote($atts = []): string
	{
		$a = \shortcode_atts(
			[
				'unit_id'    => '0',
				'show_rates' => 'auto',
			],
			\is_array($atts) ? $atts : [],
			'bec_quote'
		);

		$postId = (int) $a['unit_id'];
		if ($postId < 1) {
			$postId = (int) \get_the_ID();
		}
		if ($postId < 1 || \get_post_type($postId) !== UnitPostType::getSlug()) {
			return '';
		}

		if (FallbackService::isAlwaysOn()) {
			return '';
		}

		$ctx = SearchContext::fromRequest();
		if (! $ctx->isComplete()) {
			return '';
		}

		$quote = QuoteService::getQuote($postId, $ctx);
		if (FallbackService::shouldDisplay($quote)) {
			return FallbackRenderer::render();
		}
		if ($quote instanceof \WP_Error) {
			return '';
		}
		if (! \is_array($quote)) {
			return '';
		}

		$available = ! empty($quote['available']);
		$ratesArr  = isset($quote['rates']) && \is_array($quote['rates']) ? $quote['rates'] : [];
		$rateCount = \count($ratesArr);

		$showRatesMode = \strtolower(\trim((string) $a['show_rates']));
		$appendRatesList = false;
		if ($available && $rateCount > 0) {
			if ($showRatesMode === '1' || $showRatesMode === 'always' || $showRatesMode === 'yes' || $showRatesMode === 'true') {
				$appendRatesList = true;
			} elseif ($showRatesMode === '0' || $showRatesMode === 'never' || $showRatesMode === 'no' || $showRatesMode === 'false') {
				$appendRatesList = false;
			} else {
				// auto (default): show all options when the API returned more than one rate
				$appendRatesList = $rateCount > 1;
			}
		}

		$text = self::defaultQuoteShortcodeText($quote, $available, $appendRatesList);
		$text = (string) \apply_filters('bec_shortcode_quote_text', $text, $quote, $postId, $ctx);

		$html = '<p class="bec-shortcode-quote">' . \esc_html($text) . '</p>';

		if ($appendRatesList) {
			$html .= self::renderQuoteRatesListHtml($quote);
		}

		return (string) \apply_filters('bec_shortcode_quote_html', $html, $quote, $postId, $ctx);
	}

	/**
	 * @param array<string, mixed> $quote
	 */
	private static function defaultQuoteShortcodeText(array $quote, bool $available, bool $ratesListShown): string
	{
		if (! $available) {
			return \__('No availability for these dates.', 'booking-engine-connector');
		}

		$priceAmount = $quote['price']['amount'] ?? null;
		$text        = '';
		if (\is_numeric($priceAmount)) {
			$formatted = \number_format_i18n((float) $priceAmount, 2);
			$currency  = (string) ($quote['price']['currency'] ?? '');
			$label     = (string) ($quote['price']['label'] ?? '');
			if ($label !== '') {
				$text = \sprintf(
					/* translators: 1: rate name, 2: formatted amount, 3: currency code */
					\__('%1$s — %2$s %3$s. Available for your dates.', 'booking-engine-connector'),
					$label,
					$formatted,
					$currency
				);
			} else {
				$text = \sprintf(
					/* translators: 1: formatted amount, 2: currency code */
					\__('%1$s %2$s — available for your dates.', 'booking-engine-connector'),
					$formatted,
					$currency
				);
			}
		} else {
			$text = \__('Available for your dates.', 'booking-engine-connector');
		}

		$rates = $quote['rates'] ?? [];
		$n     = \is_array($rates) ? \count($rates) : 0;
		if ($n > 1 && ! $ratesListShown) {
			$text .= ' ' . \sprintf(
				/* translators: %d: number of distinct rate plans */
				\__('(%d rates available.)', 'booking-engine-connector'),
				$n
			);
		}

		return $text;
	}

	/**
	 * @param array<string, mixed> $quote
	 */
	private static function renderQuoteRatesListHtml(array $quote): string
	{
		$rates = $quote['rates'] ?? [];
		if (! \is_array($rates) || $rates === []) {
			return '';
		}

		$selId = '';
		if (isset($quote['selected_rate']) && \is_array($quote['selected_rate']) && isset($quote['selected_rate']['id'])) {
			$selId = (string) $quote['selected_rate']['id'];
		}

		\ob_start();
		echo '<ul class="bec-shortcode-quote__rates">';
		foreach ($rates as $r) {
			if (! \is_array($r)) {
				continue;
			}
			$id  = isset($r['id']) ? (string) $r['id'] : '';
			$lbl = isset($r['label']) ? (string) $r['label'] : $id;
			$amt = $r['amount'] ?? null;
			$cur = isset($r['currency']) && $r['currency'] !== null ? (string) $r['currency'] : '';
			$line = '';
			if (\is_numeric($amt)) {
				$line = \sprintf(
					'%1$s %2$s',
					\number_format_i18n((float) $amt, 2),
					$cur
				);
			}
			$classes = 'bec-shortcode-quote__rate';
			if ($selId !== '' && $id === $selId) {
				$classes .= ' bec-shortcode-quote__rate--selected';
			}
			echo '<li class="' . \esc_attr($classes) . '">';
			echo '<span class="bec-shortcode-quote__rate-name">' . \esc_html($lbl) . '</span>';
			if ($line !== '') {
				echo ' <span class="bec-shortcode-quote__rate-price">' . \esc_html($line) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';

		return (string) \ob_get_clean();
	}

	public static function renderFallback(): string
	{
		return FallbackRenderer::render();
	}

	/**
	 * Unit permalink with current request search query args (dates, occupancy, rate, child ages).
	 *
	 * Use in archive/card templates: href="[bec_unit_url]" or [bec_unit_url unit_id="123"] outside the loop.
	 */
	public static function renderUnitUrl($atts = []): string
	{
		$a = \shortcode_atts(
			['unit_id' => '0'],
			\is_array($atts) ? $atts : [],
			'bec_unit_url'
		);

		$postId = (int) $a['unit_id'];
		if ($postId < 1) {
			$postId = (int) \get_the_ID();
		}
		if ($postId < 1 || \get_post_type($postId) !== UnitPostType::getSlug()) {
			return '';
		}

		$permalink = \get_permalink($postId);
		if ($permalink === false) {
			return '';
		}

		$ctx = SearchContext::fromRequest();
		$url = $ctx->appendToUrl((string) $permalink);
		$url = (string) \apply_filters('bec_shortcode_unit_url', $url, $postId, $ctx);

		return \esc_url($url);
	}

	/**
	 * Provider-specific unit data from synced payload (`[bec_unit_info key="…"]`).
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function renderUnitInfo($atts = []): string
	{
		$raw = \is_array($atts) ? $atts : [];
		$a   = \shortcode_atts(
			[
				'key'     => '',
				'unit_id' => '0',
				'default' => '',
			],
			$raw,
			'bec_unit_info'
		);

		$key = \trim((string) $a['key']);
		$def = (string) $a['default'];
		$defOut = $def === '' ? '' : \esc_html($def);

		if ($key === '') {
			return $defOut;
		}

		$postId = (int) $a['unit_id'];
		if ($postId < 1) {
			$postId = (int) \get_the_ID();
		}
		if ($postId < 1 || \get_post_type($postId) !== UnitPostType::getSlug()) {
			return $defOut;
		}

		$providerSlug = (string) \get_post_meta($postId, 'bec_provider_slug', true);
		if ($providerSlug === '') {
			$providerSlug = ProviderRegistry::getActiveSlug();
		}

		$provider = ProviderRegistry::getProvider($providerSlug);
		$renderers = $provider->getUnitInfoRenderers();

		$renderers = (array) \apply_filters('bec_unit_info_renderers', $renderers, $providerSlug, $key, $postId);

		if (! isset($renderers[ $key ]) || ! \is_callable($renderers[ $key ])) {
			return $defOut;
		}

		$json = (string) \get_post_meta($postId, 'bec_sync_payload', true);
		if ($json === '') {
			return $defOut;
		}

		$decoded = \json_decode($json, true);
		if (! \is_array($decoded)) {
			return $defOut;
		}

		$passThrough = [];
		$reserved      = [ 'key' => true, 'unit_id' => true, 'default' => true ];
		foreach ($raw as $k => $v) {
			if (isset($reserved[ $k ])) {
				continue;
			}
			$passThrough[ (string) $k ] = $v;
		}

		$locale = \function_exists('determine_locale') ? \determine_locale() : \get_locale();
		$locale = \str_replace('-', '_', (string) $locale);
		$primary = \explode('_', $locale, 2)[0];
		$locale2 = \strtolower(\substr($primary, 0, 2));
		if ($locale2 === '' || ! \preg_match('/^[a-z]{2}$/', $locale2)) {
			$locale2 = 'en';
		}

		$context = [
			'provider' => $providerSlug,
			'locale'   => $locale2,
		];

		try {
			$callback   = $renderers[ $key ];
			$rawOut = $callback($decoded, $postId, $passThrough, $context);
			$html = \is_string($rawOut) ? $rawOut : '';
		} catch (\Throwable $e) {
			return $defOut;
		}

		$html = (string) \apply_filters('bec_unit_info_output', $html, $key, $postId, $decoded, $context);

		return $html;
	}
}
