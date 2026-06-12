<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;

/**
 * Maps Kross category names locale blocks to per-language category labels.
 */
final class KrossCategoryTranslations
{
	public static function register(): void
	{
		\add_filter('bec_category_translation_strings', [self::class, 'filterTranslationStrings'], 10, 4);
		\add_filter('bec_sync_provider_category_descriptors', [self::class, 'filterProviderCategoryDescriptors'], 10, 3);
	}

	/**
	 * @param array<string, array<string, mixed>> $unique
	 * @param list<array<string, mixed>>          $rows
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function filterProviderCategoryDescriptors(array $unique, string $providerSlug, array $rows): array
	{
		unset($rows);

		if ($providerSlug !== 'kross' || ! UnitCategoryTaxonomy::isEnabled()) {
			return $unique;
		}

		$provider = ProviderRegistry::getProvider('kross');
		if (! $provider instanceof KrossProvider) {
			return $unique;
		}

		foreach ($provider->getCategoryDescriptorMap() as $externalId => $descriptor) {
			if (! \is_array($descriptor)) {
				continue;
			}

			$id = (string) $externalId;
			if ($id === '') {
				continue;
			}

			/** @var array<string, mixed> $descriptor */
			$unique[ $id ] = $descriptor;
		}

		return $unique;
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
