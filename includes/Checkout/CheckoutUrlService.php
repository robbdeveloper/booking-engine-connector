<?php

declare(strict_types=1);

namespace BookingEngineConnector\Checkout;

use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Search\QuoteService;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Search\SearchValidator;

/**
 * Resolves checkout URL for a unit post via the active provider (TASK-CHK-001).
 *
 * Payload shape (after normalization):
 * - GET (default): `url`, optional `label`.
 * - POST: `url` (action), `method` => `post`, `post_fields` => name => scalar, optional `label`.
 */
final class CheckoutUrlService
{
	/**
	 * @return array<string, mixed>|null
	 */
	public static function buildForPost(int $postId, SearchContext $ctx): ?array
	{
		if (! $ctx->isComplete()) {
			return null;
		}

		$validation = SearchValidator::validate($ctx);
		if ($validation instanceof \WP_Error) {
			return null;
		}

		$externalId = (string) \get_post_meta($postId, 'bec_external_id', true);
		if ($externalId === '') {
			return null;
		}

		$stored = (string) \get_post_meta($postId, 'bec_provider_slug', true);
		$slug   = $stored !== '' ? $stored : ProviderRegistry::getActiveSlug();
		$provider = ProviderRegistry::getProvider($slug);

		$providerSearch = $ctx->toProviderSearchContext();
		/**
		 * @var array<string, mixed> $providerSearch
		 */
		$providerSearch = (array) \apply_filters('bec_quote_search_context', $providerSearch, $postId, $ctx);

		if ($slug === 'kross' && ($providerSearch['rate_id'] ?? '') === '') {
			$quote = QuoteService::getQuote($postId, $ctx);
			if (! \is_wp_error($quote) && \is_array($quote)) {
				$selected = $quote['selected_rate'] ?? null;
				if (\is_array($selected) && isset($selected['id']) && (string) $selected['id'] !== '') {
					$providerSearch['rate_id'] = (string) $selected['id'];
				}
			}
		}

		$raw = $provider->buildCheckoutUrl($externalId, $providerSearch);

		/** @var array<string, mixed>|null $filtered */
		$filtered = \apply_filters('bec_checkout_url', $raw, $postId, $ctx);

		return self::normalize($filtered);
	}

	/**
	 * @param array<string, mixed>|null $raw
	 *
	 * @return array<string, mixed>|null
	 */
	private static function normalize(?array $raw): ?array
	{
		if ($raw === null) {
			return null;
		}
		if (! isset($raw['url']) || (string) $raw['url'] === '') {
			return null;
		}

		$method = isset($raw['method']) ? \strtolower(\trim((string) $raw['method'])) : 'get';
		if ($method !== 'post') {
			$out = $raw;
			$out['method'] = 'get';
			unset($out['post_fields']);

			return $out;
		}

		$fields = $raw['post_fields'] ?? [];
		if (! \is_array($fields)) {
			$fields = [];
		}

		$norm = [];
		foreach ($fields as $name => $value) {
			if (! \is_string($name) || $name === '') {
				continue;
			}
			if (\is_array($value) || \is_object($value)) {
				continue;
			}
			$norm[$name] = $value;
		}

		$out = $raw;
		$out['method'] = 'post';
		$out['post_fields'] = $norm;

		return $out;
	}
}
