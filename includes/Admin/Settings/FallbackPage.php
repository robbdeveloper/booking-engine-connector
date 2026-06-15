<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Admin\AdminPageLayout;
use BookingEngineConnector\Fallback\FallbackSettings;
use BookingEngineConnector\Integrations\Multilingual;
use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;

/**
 * Checkout base URL (Kross) + fallback behaviour (TASK-FB-003).
 */
final class FallbackPage
{
	public const PAGE_SLUG = 'bec-fallback';

	private const NONCE_ACTION = 'bec_fallback_save';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'handlePost']);
	}

	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$checkoutBase   = (string) \get_option(FallbackSettings::OPTION_CHECKOUT_BASE_URL, '');
		$checkoutMethod = (string) \get_option(FallbackSettings::OPTION_CHECKOUT_HTTP_METHOD, 'get');
		$enabled      = (bool) \get_option(FallbackSettings::OPTION_ENABLED, true);
		$mode         = (string) \get_option(FallbackSettings::OPTION_MODE, 'inline');
		$force        = (bool) \get_option(FallbackSettings::OPTION_FORCE, false);
		$emptyQuote   = (bool) \get_option(FallbackSettings::OPTION_EMPTY_QUOTE, false);
		$activeLang   = self::resolveActiveContentLanguage();
		$contentFields = FallbackSettings::getFieldsForLanguage($activeLang);

		$triggers = \BookingEngineConnector\Fallback\FallbackService::getTriggerCategories();

		AdminPageLayout::wrapOpen(
			\__('Checkout & Fallback', 'booking-engine-connector'),
			\__(
				'Configure the external booking URL pattern and when to show contact fallback instead of online booking.',
				'booking-engine-connector'
			)
		);

		AdminPageLayout::renderSavedNotice();

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_fallback_nonce');
		echo '<input type="hidden" name="bec_fallback_lang" value="' . \esc_attr($activeLang) . '" />';

		AdminPageLayout::cardOpen(
			\__('Checkout (Kross)', 'booking-engine-connector'),
			\__(
				'Full URL to your Kross booking engine entry point. Parameters (hotel, room type, dates, guests) are sent as query string (GET) or POST fields.',
				'booking-engine-connector'
			)
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bec_kross_checkout_base_url">' . \esc_html__('Booking engine base URL', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="url" class="large-text" name="bec_kross_checkout_base_url" id="bec_kross_checkout_base_url" value="' . \esc_attr($checkoutBase) . '" placeholder="https://" />';
		echo '<p class="description">' . \esc_html__(
			'Full URL to your Kross booking engine entry point. Leave empty to hide checkout links until configured. Parameters (hotel, room type, dates, guests) are sent as query string (GET) or as POST fields, depending on the option below.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bec_kross_checkout_http_method">' . \esc_html__('How to open the booking engine', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_kross_checkout_http_method" id="bec_kross_checkout_http_method">';
		echo '<option value="get" ' . \selected($checkoutMethod, 'get', false) . '>' . \esc_html__(
			'GET — append parameters to the URL',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="post" ' . \selected($checkoutMethod, 'post', false) . '>' . \esc_html__(
			'POST — submit parameters in the request body to this URL',
			'booking-engine-connector'
		) . '</option>';
		echo '</select>';
		echo '<p class="description">' . \esc_html__(
			'Use POST when your engine expects a form submission and then redirects the browser (e.g. middleware that builds the final booking URL from posted fields).',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr></table>';
		AdminPageLayout::cardClose();

		AdminPageLayout::cardOpen(
			\__('Fallback behavior', 'booking-engine-connector'),
			\__(
				'When rules match, show contact fallback instead of online booking or API error notices.',
				'booking-engine-connector'
			)
		);
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . \esc_html__('Enable fallback', 'booking-engine-connector') . '</th><td>';
		echo '<label><input type="checkbox" name="bec_fallback_enabled" value="1" ' . \checked($enabled, true, false) . ' /> ';
		echo \esc_html__('Allow fallback blocks when rules match.', 'booking-engine-connector') . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . \esc_html__('Always use fallback', 'booking-engine-connector') . '</th><td>';
		echo '<label><input type="checkbox" name="bec_fallback_force" value="1" ' . \checked($force, true, false) . ' /> ';
		echo \esc_html__('Ignore API availability and always show fallback (e.g. contact-only mode).', 'booking-engine-connector') . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . \esc_html__('Presentation', 'booking-engine-connector') . '</th><td>';
		echo '<select name="bec_fallback_mode" id="bec_fallback_mode">';
		echo '<option value="inline" ' . \selected($mode, 'inline', false) . '>' . \esc_html__('Inline content', 'booking-engine-connector') . '</option>';
		echo '<option value="link" ' . \selected($mode, 'link', false) . '>' . \esc_html__('Link only', 'booking-engine-connector') . '</option>';
		echo '</select>';
		echo '</td></tr>';
		echo '</table>';

		if (FallbackSettings::hasMultilingualContentTabs()) {
			AdminPageLayout::inlineNotice(
				\__(
					'Fallback URL, link text, and inline content can be translated per language below. Leave a translation empty to fall back to the default language value (or WPML/Polylang string translations when configured).',
					'booking-engine-connector'
				)
			);

			AdminPageLayout::tabsNavOpen(
				\__('Fallback content languages', 'booking-engine-connector')
			);
			foreach (FallbackSettings::getContentLanguages() as $lang) {
				$label = FallbackSettings::getLanguageLabel($lang);
				if (FallbackSettings::isDefaultContentLanguage($lang)) {
					$label .= ' (' . \__('default', 'booking-engine-connector') . ')';
				}
				AdminPageLayout::tabLink(
					self::adminPageUrl($lang),
					$label,
					$lang === $activeLang
				);
			}
			AdminPageLayout::tabsNavClose();
		}

		echo '<table class="form-table" role="presentation">';
		self::renderContentFields($contentFields);

		echo '<tr><th scope="row">' . \esc_html__('Trigger fallback on empty availability', 'booking-engine-connector') . '</th><td>';
		echo '<label><input type="checkbox" name="bec_fallback_empty_quote" value="1" ' . \checked($emptyQuote, true, false) . ' /> ';
		echo \esc_html__('When the provider reports no rooms available for the search.', 'booking-engine-connector') . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . \esc_html__('Trigger on provider errors', 'booking-engine-connector') . '</th><td>';
		foreach (ProviderErrorCategory::all() as $cat) {
			$checked = \in_array($cat, $triggers, true);
			echo '<label style="display:block;margin:0.25rem 0;"><input type="checkbox" name="bec_fallback_cat[]" value="' . \esc_attr($cat) . '" ' . \checked($checked, true, false) . ' /> ';
			echo \esc_html($cat) . '</label>';
		}
		echo '<p class="description">' . \esc_html__(
			'When the quote request fails with one of these categories, show fallback instead of an error notice.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		AdminPageLayout::cardClose();

		echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__('Save changes', 'booking-engine-connector') . '</button></p>';
		echo '</form>';

		AdminPageLayout::wrapClose();
	}

	public static function handlePost(): void
	{
		if (! isset($_POST['page'], $_POST['bec_fallback_nonce']) || (string) \sanitize_key(\wp_unslash((string) $_POST['page'])) !== self::PAGE_SLUG) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\check_admin_referer(self::NONCE_ACTION, 'bec_fallback_nonce');

		$base = isset($_POST['bec_kross_checkout_base_url']) ? \esc_url_raw(\wp_unslash((string) $_POST['bec_kross_checkout_base_url'])) : '';
		\update_option(FallbackSettings::OPTION_CHECKOUT_BASE_URL, $base, false);

		$m = isset($_POST['bec_kross_checkout_http_method']) ? \sanitize_key(\wp_unslash((string) $_POST['bec_kross_checkout_http_method'])) : 'get';
		if (! \in_array($m, ['get', 'post'], true)) {
			$m = 'get';
		}
		\update_option(FallbackSettings::OPTION_CHECKOUT_HTTP_METHOD, $m, false);

		\update_option(FallbackSettings::OPTION_ENABLED, isset($_POST['bec_fallback_enabled']), false);
		\update_option(FallbackSettings::OPTION_FORCE, isset($_POST['bec_fallback_force']), false);
		\update_option(FallbackSettings::OPTION_EMPTY_QUOTE, isset($_POST['bec_fallback_empty_quote']), false);

		$mode = isset($_POST['bec_fallback_mode']) ? \sanitize_key(\wp_unslash((string) $_POST['bec_fallback_mode'])) : 'inline';
		if (! \in_array($mode, ['inline', 'link'], true)) {
			$mode = 'inline';
		}
		\update_option(FallbackSettings::OPTION_MODE, $mode, false);

		$lang = isset($_POST['bec_fallback_lang']) ? \sanitize_key(\wp_unslash((string) $_POST['bec_fallback_lang'])) : '';
		if ($lang === '') {
			$lang = MultilingualBridge::getDefaultLanguage();
		}

		$linkUrl = isset($_POST['bec_fallback_link_url'])
			? FallbackSettings::sanitizeLinkTarget(\wp_unslash((string) $_POST['bec_fallback_link_url']))
			: '';
		$linkText = isset($_POST['bec_fallback_link_text']) ? \sanitize_text_field(\wp_unslash((string) $_POST['bec_fallback_link_text'])) : '';
		$inline = isset($_POST['bec_fallback_inline_content']) ? \wp_kses_post(\wp_unslash((string) $_POST['bec_fallback_inline_content'])) : '';

		if (FallbackSettings::isDefaultContentLanguage($lang)) {
			\update_option(FallbackSettings::OPTION_LINK_URL, $linkUrl, false);
			\update_option(FallbackSettings::OPTION_LINK_TEXT, $linkText, false);
			\update_option(FallbackSettings::OPTION_INLINE_CONTENT, $inline, false);
		} else {
			FallbackSettings::saveTranslationForLanguage(
				$lang,
				[
					FallbackSettings::FIELD_LINK_URL       => $linkUrl,
					FallbackSettings::FIELD_LINK_TEXT      => $linkText,
					FallbackSettings::FIELD_INLINE_CONTENT => $inline,
				]
			);
		}

		$cats = [];
		if (isset($_POST['bec_fallback_cat']) && \is_array($_POST['bec_fallback_cat'])) {
			$allowed = ProviderErrorCategory::all();
			foreach ($_POST['bec_fallback_cat'] as $c) {
				$c = \sanitize_text_field(\wp_unslash((string) $c));
				if (\in_array($c, $allowed, true)) {
					$cats[] = $c;
				}
			}
		}
		\update_option(FallbackSettings::OPTION_TRIGGER_CATEGORIES, \wp_json_encode($cats), false);

		Multilingual::syncAfterFallbackSave();

		\wp_safe_redirect(
			\add_query_arg(
				[
					'page'      => self::PAGE_SLUG,
					'bec_saved' => '1',
					'bec_lang'  => $lang,
				],
				\admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * @param array{link_url: string, link_text: string, inline_content: string} $fields
	 */
	private static function renderContentFields(array $fields): void
	{
		$linkUrl  = $fields[ FallbackSettings::FIELD_LINK_URL ];
		$linkText = $fields[ FallbackSettings::FIELD_LINK_TEXT ];
		$inline   = $fields[ FallbackSettings::FIELD_INLINE_CONTENT ];

		echo '<tr><th scope="row"><label for="bec_fallback_link_url">' . \esc_html__('Fallback link URL', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="text" class="large-text" name="bec_fallback_link_url" id="bec_fallback_link_url" value="' . \esc_attr($linkUrl) . '" placeholder="/contact, ?subject=enquiry, or #popup-hash" />';
		echo '<p class="description">' . \esc_html__(
			'Used when “Link only” is selected. Enter a full URL, a site path (e.g. /contact), query parameters (e.g. ?subject=enquiry), or an encoded hash link (e.g. Elementor popup triggers).',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bec_fallback_link_text">' . \esc_html__('Fallback link text', 'booking-engine-connector') . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="bec_fallback_link_text" id="bec_fallback_link_text" value="' . \esc_attr($linkText) . '" />';
		echo '<p class="description">' . \esc_html__(
			'Leave empty to use the default contact label, which follows the active site language.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bec_fallback_inline_content">' . \esc_html__('Inline content', 'booking-engine-connector') . '</label></th><td>';
		\wp_editor(
			$inline,
			'bec_fallback_inline_content',
			[
				'textarea_name' => 'bec_fallback_inline_content',
				'textarea_rows' => 6,
				'media_buttons' => false,
			]
		);
		echo '<p class="description">' . \esc_html__(
			'Shown for “Inline content” mode. Shortcodes are supported.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * @param array<string, scalar|null> $extra
	 */
	private static function adminPageUrl(string $lang = '', array $extra = []): string
	{
		$args = \array_merge(['page' => self::PAGE_SLUG], $extra);
		if ($lang !== '') {
			$args['bec_lang'] = $lang;
		}

		return \add_query_arg($args, \admin_url('admin.php'));
	}

	private static function resolveActiveContentLanguage(): string
	{
		$languages = FallbackSettings::getContentLanguages();
		$default   = MultilingualBridge::getDefaultLanguage();

		if ($languages === []) {
			return $default !== '' ? $default : '';
		}

		if (isset($_GET['bec_lang'])) {
			$lang = \sanitize_key(\wp_unslash((string) $_GET['bec_lang']));
			if (\in_array($lang, $languages, true)) {
				return $lang;
			}
		}

		return $languages[0];
	}
}
