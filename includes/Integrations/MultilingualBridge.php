<?php

declare(strict_types=1);

namespace BookingEngineConnector\Integrations;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Thin WPML / Polylang adapter for post translation linking and URL localization.
 */
final class MultilingualBridge
{
	public const OPTION_SYNC_TRANSLATIONS_ENABLED = 'bec_sync_translations_enabled';

	public const META_TRANSLATION_OF = 'bec_translation_of';

	public const META_TRANSLATION_LANG = 'bec_translation_lang';

	public const META_TRANSLATION_POST_IDS = 'bec_translation_post_ids';

	public static function register(): void
	{
		// Intentionally empty — stateless helpers; {@see UnitTranslationSync} owns hooks.
	}

	public static function isActive(): bool
	{
		return \defined('ICL_SITEPRESS_VERSION') || \function_exists('pll_languages_list');
	}

	public static function isFeatureEnabled(): bool
	{
		if (! self::isActive()) {
			return false;
		}

		$stored = \get_option(self::OPTION_SYNC_TRANSLATIONS_ENABLED, null);
		if ($stored === null || $stored === false || $stored === '') {
			$enabled = true;
		} else {
			$enabled = (bool) (int) $stored;
		}

		return (bool) \apply_filters('bec_sync_translations_enabled', $enabled);
	}

	/**
	 * @return list<string> Active language codes (WPML/Polylang slugs).
	 */
	public static function getActiveLanguages(): array
	{
		if (\defined('ICL_SITEPRESS_VERSION')) {
			/** @var array<string, array<string, mixed>>|null $languages */
			$languages = \apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
			if (! \is_array($languages) || $languages === []) {
				return [];
			}

			return \array_values(\array_map('strval', \array_keys($languages)));
		}

		if (\function_exists('pll_languages_list')) {
			$list = \pll_languages_list(['fields' => 'slug']);
			if (! \is_array($list)) {
				return [];
			}

			return \array_values(\array_filter(\array_map('strval', $list)));
		}

		return [];
	}

	public static function getDefaultLanguage(): string
	{
		if (\defined('ICL_SITEPRESS_VERSION')) {
			$lang = \apply_filters('wpml_default_language', null);

			return \is_string($lang) && $lang !== '' ? $lang : '';
		}

		if (\function_exists('pll_default_language')) {
			$lang = \pll_default_language('slug');

			return \is_string($lang) && $lang !== '' ? $lang : '';
		}

		return '';
	}

	public static function getPostLanguage(int $postId): string
	{
		if ($postId < 1) {
			return '';
		}

		if (\defined('ICL_SITEPRESS_VERSION')) {
			$lang = \apply_filters(
				'wpml_element_language_code',
				null,
				[
					'element_id'   => $postId,
					'element_type' => self::wpmlElementType(),
				]
			);

			return \is_string($lang) && $lang !== '' ? $lang : '';
		}

		if (\function_exists('pll_get_post_language')) {
			$lang = \pll_get_post_language($postId, 'slug');

			return \is_string($lang) && $lang !== '' ? $lang : '';
		}

		return '';
	}

	public static function getTermLanguage(int $termId): string
	{
		if ($termId < 1) {
			return '';
		}

		if (\defined('ICL_SITEPRESS_VERSION')) {
			$lang = \apply_filters(
				'wpml_element_language_code',
				null,
				[
					'element_id'   => $termId,
					'element_type' => 'tax_bec_unit_category',
				]
			);

			return \is_string($lang) && $lang !== '' ? $lang : '';
		}

		if (\function_exists('pll_get_term_language')) {
			$lang = \pll_get_term_language($termId, 'slug');

			return \is_string($lang) && $lang !== '' ? $lang : '';
		}

		return '';
	}

	public static function setPostLanguage(int $postId, string $lang): void
	{
		if ($postId < 1 || $lang === '') {
			return;
		}

		if (\defined('ICL_SITEPRESS_VERSION')) {
			\do_action(
				'wpml_set_element_language_details',
				[
					'element_id'           => $postId,
					'element_type'         => self::wpmlElementType(),
					'language_code'        => $lang,
					'source_language_code' => null,
				]
			);

			return;
		}

		if (\function_exists('pll_set_post_language')) {
			\pll_set_post_language($postId, $lang);
		}
	}

	public static function linkTranslation(int $sourceId, string $sourceLang, int $translatedId, string $lang): void
	{
		if ($sourceId < 1 || $translatedId < 1 || $lang === '') {
			return;
		}

		if (\defined('ICL_SITEPRESS_VERSION')) {
			$trid = \apply_filters('wpml_element_trid', null, $sourceId, self::wpmlElementType());
			$trid = \is_numeric($trid) ? (int) $trid : 0;

			\do_action(
				'wpml_set_element_language_details',
				[
					'element_id'           => $translatedId,
					'element_type'         => self::wpmlElementType(),
					'trid'                 => $trid > 0 ? $trid : null,
					'language_code'        => $lang,
					'source_language_code' => $sourceLang,
				]
			);

			return;
		}

		if (\function_exists('pll_save_post_translations') && \function_exists('pll_get_post_translations')) {
			$map = \pll_get_post_translations($sourceId);
			if (! \is_array($map)) {
				$map = [];
			}
			$map[ $sourceLang ] = $sourceId;
			$map[ $lang ]       = $translatedId;
			\pll_save_post_translations($map);
		}
	}

	public static function getTranslatedPostId(int $sourceId, string $lang): ?int
	{
		if ($sourceId < 1 || $lang === '') {
			return null;
		}

		if (\defined('ICL_SITEPRESS_VERSION')) {
			$translated = \apply_filters('wpml_object_id', $sourceId, UnitPostType::getSlug(), false, $lang);

			return \is_numeric($translated) && (int) $translated > 0 ? (int) $translated : null;
		}

		if (\function_exists('pll_get_post')) {
			$translated = \pll_get_post($sourceId, $lang);

			return \is_numeric($translated) && (int) $translated > 0 ? (int) $translated : null;
		}

		return null;
	}

	/**
	 * Map a WordPress language code to a provider locale key (typically two letters).
	 */
	public static function localeToProviderKey(string $lang): string
	{
		$lang = \sanitize_key($lang);
		$map  = \apply_filters('bec_translation_locale_map', [], $lang);
		if (\is_array($map) && isset($map[ $lang ]) && \is_string($map[ $lang ]) && $map[ $lang ] !== '') {
			return \strtolower($map[ $lang ]);
		}

		return \strlen($lang) >= 2 ? \strtolower(\substr($lang, 0, 2)) : $lang;
	}

	/**
	 * Apply WPML/Polylang language URL modes to a custom-built URL.
	 */
	public static function localizeUrl(string $url, ?string $lang = null, ?int $postId = null): string
	{
		if (! self::isActive()) {
			return $url;
		}

		if (($lang === null || $lang === '') && $postId !== null && $postId > 0) {
			$lang = self::getPostLanguage($postId);
		}

		if ($lang === null || $lang === '') {
			return $url;
		}

		if (\defined('ICL_SITEPRESS_VERSION')) {
			$localized = \apply_filters('wpml_permalink', $url, $lang);

			return \is_string($localized) && $localized !== '' ? $localized : $url;
		}

		if (\function_exists('pll_home_url')) {
			$home     = \home_url('/');
			$relative = $url;
			if (\str_starts_with($relative, $home)) {
				$relative = \substr($relative, \strlen($home));
			}
			$relative = \ltrim($relative, '/');
			$base     = \pll_home_url($lang);
			if (! \is_string($base) || $base === '') {
				return $url;
			}
			$built = $relative === '' ? \user_trailingslashit($base) : \user_trailingslashit($base) . $relative;

			return $built;
		}

		return $url;
	}

	/**
	 * Meta query branch: only canonical (non-translation) unit posts.
	 *
	 * @return array<string, mixed>
	 */
	public static function canonicalOnlyMetaQueryBranch(): array
	{
		return [
			'key'     => self::META_TRANSLATION_OF,
			'compare' => 'NOT EXISTS',
		];
	}

	public static function resolveCanonicalPostId(int $postId): int
	{
		if ($postId < 1) {
			return 0;
		}

		$parent = (int) \get_post_meta($postId, self::META_TRANSLATION_OF, true);

		return $parent > 0 ? $parent : $postId;
	}

	private static function wpmlElementType(): string
	{
		return 'post_' . UnitPostType::getSlug();
	}
}
