<?php

declare(strict_types=1);

namespace BookingEngineConnector\Shortcodes;

use BookingEngineConnector\Checkout\CheckoutCtaHtml;
use BookingEngineConnector\Checkout\CheckoutUrlService;
use BookingEngineConnector\Formatting\DateFormatter;
use BookingEngineConnector\Formatting\MoneyFormatter;
use BookingEngineConnector\Fallback\FallbackRenderer;
use BookingEngineConnector\Fallback\FallbackService;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Search\SearchForm;
use BookingEngineConnector\Shortcodes\BookingSummary\BookingSummaryRenderer;
use BookingEngineConnector\Sync\SyncPayloadEncoder;

/**
 * Public shortcodes (TASK-SHO-001).
 *
 * User-visible labels use gettext on this text domain. Numbers, currency codes, and rate titles from
 * the booking provider are treated as dynamic content and are not wrapped for translation here.
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
				'context'      => 'shortcode',
				'form_id'      => 'bec-search-form-sc',
				'redirect_url' => '',
			],
			\is_array($atts) ? $atts : [],
			'bec_search'
		);

		if (FallbackService::isAlwaysOn()) {
			return FallbackRenderer::render();
		}

		$action = self::resolveSearchFormActionForShortcode((string) $a['redirect_url']);

		\ob_start();
		SearchForm::render(
			[
				'context' => (string) $a['context'],
				'form_id' => (string) $a['form_id'],
				'action'  => $action,
			]
		);

		return (string) \ob_get_clean();
	}

	/**
	 * Destination URL for `[bec_search]` GET submissions (`bec_*` query args).
	 *
	 * @param string $redirectUrl Optional absolute URL, `https://…`, or root-relative path from shortcode `redirect_url`.
	 */
	private static function resolveSearchFormActionForShortcode(string $redirectUrl): string
	{
		$fallbackArchive = static function (): string {
			$link = \get_post_type_archive_link(UnitPostType::getSlug());

			return $link !== false ? (string) $link : \home_url('/');
		};

		$raw = \trim($redirectUrl);
		if ($raw !== '') {
			// Uses default allowed protocols; keeps root-relative paths (e.g. `/results/`).
			$clean = \esc_url($raw);
			if ($clean !== '') {
				return $clean;
			}
		}

		return $fallbackArchive();
	}

	/**
	 * Dates shortcode: check-in / check-out from the current search context.
	 *
	 * Attributes: date_format (PHP date_i18n format), preset (iso|short|medium|long|full),
	 * label_style (arrow|from_to|from_to_lower), label (literal sprintf pattern overriding label_style).
	 *
	 * Filters: bec_date_format_defaults, bec_shortcode_dates_format, bec_shortcode_dates_text,
	 * bec_shortcode_dates_html.
	 */
	public static function renderDates($atts = []): string
	{
		$a = \shortcode_atts(
			[
				'date_format' => '',
				'preset'      => '',
				'label_style' => '',
				'label'       => '',
			],
			\is_array($atts) ? $atts : [],
			'bec_dates'
		);

		$ctx = SearchContext::fromRequest();
		if (! $ctx->isComplete()) {
			return '';
		}

		$fmt = (string) \apply_filters('bec_shortcode_dates_format', '', $ctx);
		if ($fmt !== '') {
			return '<p class="bec-shortcode-dates">' . \esc_html($fmt) . '</p>';
		}

		$formatOptions = self::datesFormatOptionsFromAtts($a);
		$text          = DateFormatter::formatRange(
			$ctx->getCheckin(),
			$ctx->getCheckout(),
			$formatOptions
		);
		$text = (string) \apply_filters('bec_shortcode_dates_text', $text, $ctx, $formatOptions);

		$html = '<p class="bec-shortcode-dates">' . \esc_html($text) . '</p>';

		return (string) \apply_filters('bec_shortcode_dates_html', $html, $ctx, $formatOptions);
	}

	/**
	 * @param array<string, string> $a Shortcode attributes from renderDates().
	 * @return array<string, mixed>
	 */
	private static function datesFormatOptionsFromAtts(array $a): array
	{
		$builtin = [
			'preset'      => 'iso',
			'label_style' => 'arrow',
		];

		/** @var array<string, mixed> $filtered */
		$filtered = (array) \apply_filters('bec_date_format_defaults', [], 'bec_dates');

		$fromAtts = [];

		$dateFormat = \trim((string) ($a['date_format'] ?? ''));
		if ($dateFormat !== '') {
			$fromAtts['date_format'] = $dateFormat;
		}

		$presetRaw = \sanitize_key(\strtolower(\trim((string) ($a['preset'] ?? ''))));
		if ($presetRaw !== '') {
			$fromAtts['preset'] = $presetRaw;
		}

		$labelStyleRaw = \sanitize_key(\strtolower(\trim((string) ($a['label_style'] ?? ''))));
		if ($labelStyleRaw !== '') {
			$fromAtts['label_style'] = $labelStyleRaw;
		}

		$label = \trim((string) ($a['label'] ?? ''));
		if ($label !== '') {
			$fromAtts['label'] = $label;
		}

		return \array_merge($builtin, $filtered, $fromAtts);
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

	/**
	 * Quote shortcode: price for the current search context on a unit.
	 *
	 * Attributes: unit_id, show_rates (auto|always|never),
	 * currency_display (code|symbol), currency_position (before|after),
	 * decimals (0–4), decimal_sep, thousands_sep, number_style (locale|eu|us).
	 */
	public static function renderQuote($atts = []): string
	{
		$a = \shortcode_atts(
			[
				'unit_id'           => '0',
				'show_rates'        => 'auto',
				'currency_display'  => '',
				'currency_position' => '',
				'decimals'          => '',
				'decimal_sep'       => '',
				'thousands_sep'     => '',
				'number_style'      => '',
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

		$formatOptions = self::quoteMoneyFormatOptionsFromAtts($a);

		$text = self::defaultQuoteShortcodeText($quote, $available, $ctx, $formatOptions);
		$text = (string) \apply_filters('bec_shortcode_quote_text', $text, $quote, $postId, $ctx);

		$html = '<p class="bec-shortcode-quote">' . \esc_html($text) . '</p>';

		if ($appendRatesList) {
			$html .= self::renderQuoteRatesListHtml($quote, $formatOptions);
		}

		return (string) \apply_filters('bec_shortcode_quote_html', $html, $quote, $postId, $ctx);
	}

	/**
	 * @param array<string, string> $a Shortcode attributes from renderQuote().
	 * @return array<string, mixed>
	 */
	private static function quoteMoneyFormatOptionsFromAtts(array $a): array
	{
		$builtin = [
			'currency_display'  => 'code',
			'currency_position' => 'after',
			'decimals'          => 2,
			'number_style'      => 'locale',
		];

		/** @var array<string, mixed> $filtered */
		$filtered = (array) \apply_filters('bec_money_format_defaults', [], 'bec_quote');

		$fromAtts = [];

		$displayRaw = \strtolower(\trim((string) ($a['currency_display'] ?? '')));
		if ($displayRaw !== '') {
			$fromAtts['currency_display'] = $displayRaw === 'symbol' ? 'symbol' : 'code';
		}

		$positionRaw = \strtolower(\trim((string) ($a['currency_position'] ?? '')));
		if ($positionRaw !== '') {
			$fromAtts['currency_position'] = $positionRaw === 'before' ? 'before' : 'after';
		}

		$decimalsRaw = \trim((string) ($a['decimals'] ?? ''));
		if ($decimalsRaw !== '' && \is_numeric($decimalsRaw)) {
			$decimals = (int) $decimalsRaw;
			if ($decimals < 0) {
				$decimals = 0;
			}
			if ($decimals > 4) {
				$decimals = 4;
			}
			$fromAtts['decimals'] = $decimals;
		}

		$styleRaw = \strtolower(\trim((string) ($a['number_style'] ?? '')));
		if ($styleRaw === 'eu' || $styleRaw === 'us' || $styleRaw === 'locale') {
			$fromAtts['number_style'] = $styleRaw;
		}

		$dec = \trim((string) ($a['decimal_sep'] ?? ''));
		$tho = \trim((string) ($a['thousands_sep'] ?? ''));
		if ($dec !== '' && $tho !== '') {
			$fromAtts['decimal_sep']   = $dec;
			$fromAtts['thousands_sep'] = $tho;
		}

		return \array_merge($builtin, $filtered, $fromAtts);
	}

	/**
	 * @param array<string, mixed> $quote
	 * @param array<string, mixed> $formatOptions
	 */
	private static function defaultQuoteShortcodeText(
		array $quote,
		bool $available,
		SearchContext $ctx,
		array $formatOptions
	): string {
		if (! $available) {
			return \__('No availability for these dates.', 'booking-engine-connector');
		}

		$priceAmount = $quote['price']['amount'] ?? null;
		if (! \is_numeric($priceAmount)) {
			return \__('Available for your dates.', 'booking-engine-connector');
		}

		$currency = \trim((string) ($quote['price']['currency'] ?? ''));
		$money    = MoneyFormatter::format((float) $priceAmount, $currency, $formatOptions);

		$rates = $quote['rates'] ?? [];
		$n     = \is_array($rates) ? \count($rates) : 0;
		if ($n > 1 && $ctx->getRateId() === '') {
			return \trim(
				(string) \sprintf(
					/* translators: %s: formatted price including currency when applicable */
					\__('From %s', 'booking-engine-connector'),
					$money
				)
			);
		}

		return $money;
	}

	/**
	 * @param array<string, mixed> $quote
	 * @param array<string, mixed> $formatOptions
	 */
	private static function renderQuoteRatesListHtml(array $quote, array $formatOptions): string
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
				$line = MoneyFormatter::format((float) $amt, $cur, $formatOptions);
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

		$decoded = SyncPayloadEncoder::decodeStored($json);
		if ($decoded === null) {
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
