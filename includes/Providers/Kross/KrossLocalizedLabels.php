<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Integrations\Multilingual;

/**
 * Resolves Kross API locale maps (e.g. `name_rate` on `calendar/book` rows).
 *
 * Priority: active site locale → first non-`main` translation (API order) → `main`.
 */
final class KrossLocalizedLabels
{
	/**
	 * @param mixed $value Scalar label or locale map from the Kross API.
	 */
	public static function resolve($value, ?string $locale2 = null): string
	{
		if (\is_string($value)) {
			return \trim($value);
		}
		if (! \is_array($value) || $value === []) {
			return '';
		}

		$locale = $locale2 ?? self::currentLocaleCode();

		if (isset($value[ $locale ]) && \is_string($value[ $locale ]) && \trim($value[ $locale ]) !== '') {
			return \trim($value[ $locale ]);
		}

		foreach ($value as $key => $v) {
			if ($key === 'main') {
				continue;
			}
			if (\is_string($v) && \trim($v) !== '') {
				return \trim($v);
			}
		}

		if (isset($value['main']) && \is_string($value['main']) && \trim($value['main']) !== '') {
			return \trim($value['main']);
		}

		return '';
	}

	public static function currentLocaleCode(): string
	{
		$locale = Multilingual::filteredSiteLocale('kross_rate');
		$locale = \str_replace('-', '_', $locale);
		$primary = \explode('_', $locale, 2)[0];
		$code = \strtolower(\substr($primary, 0, 2));

		return \preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : 'en';
	}
}
