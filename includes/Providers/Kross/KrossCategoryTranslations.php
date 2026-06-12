<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Maps Kross category names locale blocks to per-language category labels.
 */
final class KrossCategoryTranslations
{
	public static function register(): void
	{
		\add_filter('bec_category_translation_strings', [self::class, 'filterTranslationStrings'], 10, 4);
	}

	/**
	 * @param array<string, string> $strings
	 * @param array<string, mixed>  $descriptor
	 *
	 * @return array<string, string>
	 */
	public static function filterTranslationStrings(array $strings, array $descriptor, string $providerSlug, int $canonicalTermId): array
	{
		unset($canonicalTermId);

		if ($providerSlug !== 'kross' || ! MultilingualBridge::isActive()) {
			return $strings;
		}

		$namesRaw = $descriptor['names'] ?? [];
		if (! \is_array($namesRaw)) {
			return $strings;
		}

		/** @var array<mixed, mixed> $namesRaw */
		$nameBlock = UnitCategoryTaxonomy::coerceDescriptorNamesToMap($namesRaw);
		if ($nameBlock === []) {
			return $strings;
		}

		$out = $strings;
		foreach (MultilingualBridge::getActiveLanguages() as $lang) {
			$providerKey = MultilingualBridge::localeToProviderKey($lang);
			$name        = self::pickLocaleString($nameBlock, $providerKey);
			if ($name === '') {
				continue;
			}

			$out[ $lang ] = $name;
		}

		return $out;
	}

	/**
	 * @param array<string, string> $block
	 */
	private static function pickLocaleString(array $block, string $locale): string
	{
		if (isset($block[ $locale ]) && $block[ $locale ] !== '') {
			return $block[ $locale ];
		}

		foreach (['en', 'it', 'de', 'fr', 'es'] as $fallback) {
			if (isset($block[ $fallback ]) && $block[ $fallback ] !== '') {
				return $block[ $fallback ];
			}
		}

		foreach ($block as $value) {
			if (\is_string($value) && $value !== '') {
				return $value;
			}
		}

		return '';
	}
}
