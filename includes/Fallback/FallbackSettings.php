<?php

declare(strict_types=1);

namespace BookingEngineConnector\Fallback;

use BookingEngineConnector\Integrations\Multilingual;
use BookingEngineConnector\Integrations\MultilingualBridge;

/**
 * Option keys for fallback + checkout base URL (Wave 6).
 */
final class FallbackSettings
{
	public const OPTION_ENABLED = 'bec_fallback_enabled';

	public const OPTION_MODE = 'bec_fallback_mode';

	public const OPTION_FORCE = 'bec_fallback_force';

	public const OPTION_TRIGGER_CATEGORIES = 'bec_fallback_trigger_categories';

	public const OPTION_EMPTY_QUOTE = 'bec_fallback_empty_quote';

	public const OPTION_LINK_URL = 'bec_fallback_link_url';

	public const OPTION_LINK_TEXT = 'bec_fallback_link_text';

	public const OPTION_INLINE_CONTENT = 'bec_fallback_inline_content';

	/** Per-language map of fallback URL, link text, and inline content (non-default languages). */
	public const OPTION_TRANSLATIONS = 'bec_fallback_translations';

	public const FIELD_LINK_URL = 'link_url';

	public const FIELD_LINK_TEXT = 'link_text';

	public const FIELD_INLINE_CONTENT = 'inline_content';

	public const OPTION_CHECKOUT_BASE_URL = 'bec_kross_checkout_base_url';

	/** `get` or `post` — how the browser reaches the Kross booking engine entry URL. */
	public const OPTION_CHECKOUT_HTTP_METHOD = 'bec_kross_checkout_http_method';

	/**
	 * Whether the fallback admin UI should show per-language content tabs.
	 */
	public static function hasMultilingualContentTabs(): bool
	{
		if (! MultilingualBridge::isActive()) {
			return false;
		}

		return \count(self::getContentLanguages()) > 1;
	}

	/**
	 * @return list<string> Active language codes for fallback content tabs.
	 */
	public static function getContentLanguages(): array
	{
		if (! MultilingualBridge::isActive()) {
			return [];
		}

		$languages = MultilingualBridge::getActiveLanguages();
		$default   = MultilingualBridge::getDefaultLanguage();

		if ($default !== '' && ! \in_array($default, $languages, true)) {
			\array_unshift($languages, $default);
		} elseif ($default !== '') {
			$languages = \array_values(
				\array_unique(
					\array_merge([ $default ], $languages)
				)
			);
		}

		return $languages;
	}

	public static function isDefaultContentLanguage(string $lang): bool
	{
		$default = MultilingualBridge::getDefaultLanguage();

		return $default === '' || $lang === $default;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public static function getTranslations(): array
	{
		$stored = \get_option(self::OPTION_TRANSLATIONS, []);
		if (! \is_array($stored)) {
			return [];
		}

		$out = [];
		foreach ($stored as $lang => $fields) {
			if (! \is_string($lang) || $lang === '' || ! \is_array($fields)) {
				continue;
			}

			$out[ $lang ] = self::normalizeTranslationFields($fields);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $fields
	 *
	 * @return array<string, string>
	 */
	public static function normalizeTranslationFields(array $fields): array
	{
		return [
			self::FIELD_LINK_URL       => isset($fields[ self::FIELD_LINK_URL ]) ? (string) $fields[ self::FIELD_LINK_URL ] : '',
			self::FIELD_LINK_TEXT      => isset($fields[ self::FIELD_LINK_TEXT ]) ? (string) $fields[ self::FIELD_LINK_TEXT ] : '',
			self::FIELD_INLINE_CONTENT => isset($fields[ self::FIELD_INLINE_CONTENT ]) ? (string) $fields[ self::FIELD_INLINE_CONTENT ] : '',
		];
	}

	/**
	 * @param array<string, mixed> $fields
	 *
	 * @return array<string, string>
	 */
	public static function sanitizeTranslationFields(array $fields): array
	{
		$linkUrl = isset($fields[ self::FIELD_LINK_URL ])
			? self::sanitizeLinkTarget((string) $fields[ self::FIELD_LINK_URL ])
			: '';
		$linkText = isset($fields[ self::FIELD_LINK_TEXT ])
			? \sanitize_text_field((string) $fields[ self::FIELD_LINK_TEXT ])
			: '';
		$inline = isset($fields[ self::FIELD_INLINE_CONTENT ])
			? \wp_kses_post((string) $fields[ self::FIELD_INLINE_CONTENT ])
			: '';

		return [
			self::FIELD_LINK_URL       => $linkUrl,
			self::FIELD_LINK_TEXT      => $linkText,
			self::FIELD_INLINE_CONTENT => $inline,
		];
	}

	/**
	 * @param array<string, string> $fields
	 */
	public static function saveTranslationForLanguage(string $lang, array $fields): void
	{
		$lang = \sanitize_key($lang);
		if ($lang === '' || self::isDefaultContentLanguage($lang)) {
			return;
		}

		$translations = self::getTranslations();
		$sanitized    = self::sanitizeTranslationFields($fields);

		if (
			$sanitized[ self::FIELD_LINK_URL ] === ''
			&& $sanitized[ self::FIELD_LINK_TEXT ] === ''
			&& $sanitized[ self::FIELD_INLINE_CONTENT ] === ''
		) {
			unset($translations[ $lang ]);
		} else {
			$translations[ $lang ] = $sanitized;
		}

		\update_option(self::OPTION_TRANSLATIONS, $translations, false);
	}

	/**
	 * @return array{link_url: string, link_text: string, inline_content: string}
	 */
	public static function getFieldsForLanguage(string $lang): array
	{
		if (self::isDefaultContentLanguage($lang)) {
			return [
				self::FIELD_LINK_URL       => (string) \get_option(self::OPTION_LINK_URL, ''),
				self::FIELD_LINK_TEXT      => (string) \get_option(self::OPTION_LINK_TEXT, ''),
				self::FIELD_INLINE_CONTENT => (string) \get_option(self::OPTION_INLINE_CONTENT, ''),
			];
		}

		$translations = self::getTranslations();
		$fields       = $translations[ $lang ] ?? self::normalizeTranslationFields([]);

		return $fields;
	}

	public static function getLocalizedLinkUrl(?string $lang = null): string
	{
		$lang = self::resolveContentLanguage($lang);

		if (self::isDefaultContentLanguage($lang)) {
			return \trim((string) \get_option(self::OPTION_LINK_URL, ''));
		}

		$explicit = self::getExplicitTranslationField($lang, self::FIELD_LINK_URL);
		if ($explicit !== null) {
			return $explicit;
		}

		return \trim((string) \get_option(self::OPTION_LINK_URL, ''));
	}

	public static function getLocalizedLinkText(?string $lang = null): string
	{
		$lang = self::resolveContentLanguage($lang);

		if (self::isDefaultContentLanguage($lang)) {
			return (string) \get_option(self::OPTION_LINK_TEXT, '');
		}

		$explicit = self::getExplicitTranslationField($lang, self::FIELD_LINK_TEXT);
		if ($explicit !== null) {
			return $explicit;
		}

		$stored = (string) \get_option(self::OPTION_LINK_TEXT, '');
		if ($stored === '') {
			return '';
		}

		return Multilingual::translateFallbackLinkText($stored);
	}

	public static function getLocalizedInlineContent(?string $lang = null): string
	{
		$lang = self::resolveContentLanguage($lang);

		if (self::isDefaultContentLanguage($lang)) {
			return (string) \get_option(self::OPTION_INLINE_CONTENT, '');
		}

		$explicit = self::getExplicitTranslationField($lang, self::FIELD_INLINE_CONTENT);
		if ($explicit !== null) {
			return $explicit;
		}

		$stored = (string) \get_option(self::OPTION_INLINE_CONTENT, '');
		if ($stored === '') {
			return '';
		}

		return Multilingual::translateFallbackInlineContent($stored);
	}

	public static function getLanguageLabel(string $lang): string
	{
		$lang = \sanitize_key($lang);
		if ($lang === '') {
			return '';
		}

		if (\defined('ICL_SITEPRESS_VERSION')) {
			/** @var array<string, array<string, mixed>>|null $languages */
			$languages = \apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
			if (\is_array($languages) && isset($languages[ $lang ])) {
				$entry = $languages[ $lang ];
				if (isset($entry['native_name']) && \is_string($entry['native_name']) && $entry['native_name'] !== '') {
					return $entry['native_name'];
				}
				if (isset($entry['display_name']) && \is_string($entry['display_name']) && $entry['display_name'] !== '') {
					return $entry['display_name'];
				}
			}
		}

		if (\function_exists('PLL') && \function_exists('pll_languages_list')) {
			$pll = \PLL();
			if (\is_object($pll) && isset($pll->model) && \method_exists($pll->model, 'get_language')) {
				$language = $pll->model->get_language($lang);
				if (\is_object($language) && isset($language->name) && \is_string($language->name) && $language->name !== '') {
					return $language->name;
				}
			}
		}

		return \strtoupper($lang);
	}

	public static function sanitizeLinkTarget(string $raw): string
	{
		$raw = \trim($raw);
		if ($raw === '') {
			return '';
		}

		// Preserve URL-encoded sequences (%3A, %26, etc.); only strip markup and control chars.
		$raw = \wp_strip_all_tags($raw);
		$raw = (string) \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\r\n\t]/', '', $raw);

		if ($raw === '' || \preg_match('#^\s*(javascript|data|vbscript):#i', $raw) === 1) {
			return '';
		}

		return $raw;
	}

	public static function escapeLinkHref(string $target): string
	{
		$target = \trim($target);
		if ($target === '' || ! self::isAllowedLinkTarget($target)) {
			return '';
		}

		if (
			$target[0] !== '/'
			&& $target[0] !== '?'
			&& $target[0] !== '#'
			&& ! \preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)
			&& \str_contains($target, '=')
		) {
			$target = '?' . \ltrim($target, '?&');
		}

		// esc_url() decodes or rewrites percent-encoded fragments (e.g. Elementor popup triggers).
		if (
			\preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)
			&& ! \preg_match('/%[0-9A-Fa-f]{2}/', $target)
		) {
			return \esc_url($target);
		}

		return \esc_attr($target);
	}

	private static function resolveContentLanguage(?string $lang): string
	{
		if (\is_string($lang) && $lang !== '') {
			return \sanitize_key($lang);
		}

		$current = MultilingualBridge::getCurrentLanguage();
		if ($current !== '') {
			return $current;
		}

		$default = MultilingualBridge::getDefaultLanguage();

		return $default !== '' ? $default : '';
	}

	private static function getExplicitTranslationField(string $lang, string $field): ?string
	{
		$translations = self::getTranslations();
		if (! isset($translations[ $lang ][ $field ])) {
			return null;
		}

		$value = (string) $translations[ $lang ][ $field ];

		return $value !== '' ? $value : null;
	}

	private static function isAllowedLinkTarget(string $target): bool
	{
		if ($target === '' || \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $target) === 1) {
			return false;
		}

		return ! \preg_match('#^\s*(javascript|data|vbscript):#i', $target);
	}
}
