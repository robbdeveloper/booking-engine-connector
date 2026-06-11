<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Integrations\MultilingualBridge;

/**
 * Maps Kross be_name / be_description locale blocks to per-language title/content strings.
 */
final class KrossUnitTranslations
{
	public static function register(): void
	{
		\add_filter('bec_unit_translation_strings', [self::class, 'filterTranslationStrings'], 10, 3);
	}

	/**
	 * @param array<string, array{title?: string, content?: string}> $strings
	 * @param array<string, mixed>                                   $row
	 *
	 * @return array<string, array{title: string, content: string}>
	 */
	public static function filterTranslationStrings(array $strings, array $row, string $providerSlug): array
	{
		if ($providerSlug !== 'kross' || ! MultilingualBridge::isActive()) {
			return $strings;
		}

		$raw = isset($row['raw']) && \is_array($row['raw']) ? $row['raw'] : [];
		$nameBlock = isset($raw['be_name']) && \is_array($raw['be_name']) ? $raw['be_name'] : [];
		$descBlock = isset($raw['be_description']) && \is_array($raw['be_description']) ? $raw['be_description'] : [];

		$defaultLang = MultilingualBridge::getDefaultLanguage();
		$out         = $strings;

		foreach (MultilingualBridge::getActiveLanguages() as $lang) {
			if ($lang === $defaultLang) {
				continue;
			}

			$providerKey = MultilingualBridge::localeToProviderKey($lang);
			$title       = self::pickLocaleString($nameBlock, $providerKey);
			$content     = self::pickLocaleString($descBlock, $providerKey);

			if ($title === '' && $content === '') {
				continue;
			}

			$out[ $lang ] = [
				'title'   => $title,
				'content' => $content,
			];
		}

		return $out;
	}

	/**
	 * @param array<string|int, mixed> $block
	 */
	private static function pickLocaleString(array $block, string $locale): string
	{
		$primary = isset($block[ $locale ]) ? (string) $block[ $locale ] : '';
		if ($primary !== '') {
			return $primary;
		}

		foreach (['en', 'it', 'de', 'fr', 'es'] as $fallback) {
			if (isset($block[ $fallback ]) && (string) $block[ $fallback ] !== '') {
				return (string) $block[ $fallback ];
			}
		}

		foreach ($block as $v) {
			if (\is_string($v) && $v !== '') {
				return $v;
			}
		}

		return '';
	}
}
