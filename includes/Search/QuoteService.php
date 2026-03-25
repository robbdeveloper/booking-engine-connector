<?php

declare(strict_types=1);

namespace BookingEngineConnector\Search;

use BookingEngineConnector\Providers\Contracts\BulkQuoteProviderInterface;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\ProviderRegistry;

/**
 * Fetches quotes via the active provider with transient caching (NFR-PERF).
 *
 * Providers implementing {@see BulkQuoteProviderInterface} (e.g. Kross) cache one batch
 * response per search context and derive per-unit quotes locally (archive loops).
 */
final class QuoteService
{
	/**
	 * @return mixed|\WP_Error Quote payload from the provider, or WP_Error (validation, missing mapping, provider failure).
	 */
	public static function getQuote(int $postId, ?SearchContext $ctx = null)
	{
		$ctx = $ctx ?? SearchContext::fromRequest();

		if (! $ctx->isComplete()) {
			return new \WP_Error(
				'bec_quote_incomplete',
				\__('Search parameters are incomplete.', 'booking-engine-connector')
			);
		}

		$validation = SearchValidator::validate($ctx);
		if ($validation instanceof \WP_Error) {
			return $validation;
		}

		$externalId = (string) \get_post_meta($postId, 'bec_external_id', true);
		if ($externalId === '') {
			return new \WP_Error(
				'bec_quote_no_external_id',
				\__('This unit is not linked to the booking provider.', 'booking-engine-connector')
			);
		}

		$storedSlug = (string) \get_post_meta($postId, 'bec_provider_slug', true);
		$slug       = $storedSlug !== '' ? $storedSlug : ProviderRegistry::getActiveSlug();
		$provider   = ProviderRegistry::getProvider($slug);

		$searchContext = $ctx->toProviderSearchContext();
		/**
		 * @var array<string, mixed> $searchContext
		 */
		$searchContext = (array) \apply_filters('bec_quote_search_context', $searchContext, $postId, $ctx);

		$ttl = (int) \apply_filters('bec_quote_cache_ttl', 5 * \MINUTE_IN_SECONDS, $postId, $ctx);

		if ($provider instanceof BulkQuoteProviderInterface) {
			$bulkKey = $provider->getBulkQuoteCacheKey($searchContext);
			$cachedBulk = \get_transient($bulkKey);

			try {
				if ($cachedBulk !== false) {
					$result = $provider->quoteFromBulk($cachedBulk, $externalId, $searchContext);
					$result = \apply_filters('bec_quote_cached_result', $result, $postId, $ctx);
				} else {
					$bulk = $provider->fetchBulkQuotes($searchContext);
					if ($ttl > 0) {
						\set_transient($bulkKey, $bulk, $ttl);
					}
					$result = $provider->quoteFromBulk($bulk, $externalId, $searchContext);
				}
			} catch (ProviderException $e) {
				$err = new \WP_Error(
					'bec_provider_error',
					$e->getMessage(),
					['category' => $e->getCategory()]
				);

				return \apply_filters('bec_quote_provider_error', $err, $e, $postId, $ctx);
			}

			$result = \apply_filters('bec_quote_result', $result, $postId, $ctx, $provider);
			$result = CanonicalQuote::enrich($result, $slug, $ctx);

			return $result;
		}

		$key = self::cacheKey($postId, $slug, $externalId, $searchContext);

		$cached = \get_transient($key);
		if ($cached !== false) {
			$cached = \apply_filters('bec_quote_cached_result', $cached, $postId, $ctx);

			return CanonicalQuote::enrich($cached, $slug, $ctx);
		}

		try {
			$result = $provider->getQuoteForUnit($externalId, $searchContext);
		} catch (ProviderException $e) {
			$err = new \WP_Error(
				'bec_provider_error',
				$e->getMessage(),
				['category' => $e->getCategory()]
			);

			return \apply_filters('bec_quote_provider_error', $err, $e, $postId, $ctx);
		}

		$result = \apply_filters('bec_quote_result', $result, $postId, $ctx, $provider);
		$result = CanonicalQuote::enrich($result, $slug, $ctx);

		if ($ttl > 0) {
			\set_transient($key, $result, $ttl);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $searchContext
	 */
	private static function cacheKey(int $postId, string $providerSlug, string $externalId, array $searchContext): string
	{
		$payload = [
			'post'      => $postId,
			'provider'  => $providerSlug,
			'unit'      => $externalId,
			'context'   => $searchContext,
		];

		return 'bec_quote_' . \md5((string) \wp_json_encode($payload));
	}
}
