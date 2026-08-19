<?php

declare(strict_types=1);

namespace BookingEngineConnector\Units\Completeness;

/**
 * Cached completeness report for admin surfaces.
 */
final class UnitCompletenessReport
{
	private const TRANSIENT_KEY = 'bec_unit_completeness_report';

	private const CACHE_TTL = 300;

	public static function register(): void
	{
		\add_action('bec_after_unit_sync', [self::class, 'clearCache'], 10, 1);
		\add_action('bec_after_unit_gallery_sync', [self::class, 'clearCache'], 10, 1);
		\add_action('save_post_' . \BookingEngineConnector\PostTypes\UnitPostType::getSlug(), [self::class, 'onSavePost'], 10, 1);
	}

	public static function onSavePost(int $postId): void
	{
		if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		self::clearCache();
	}

	public static function clearCache(): void
	{
		\delete_transient(self::TRANSIENT_KEY);
	}

	/**
	 * @return array{
	 *   total: int,
	 *   incomplete: int,
	 *   incomplete_units: list<array{
	 *     post_id: int,
	 *     title: string,
	 *     external_id: string,
	 *     provider: string,
	 *     edit_url: string,
	 *     missing: list<string>,
	 *     details: array<string, string>,
	 *     field_status: array<string, string>
	 *   }>
	 * }
	 */
	public static function getSummary(bool $forceRefresh = false): array
	{
		if (! $forceRefresh) {
			$cached = \get_transient(self::TRANSIENT_KEY);
			if (\is_array($cached)
				&& isset($cached['total'], $cached['incomplete'], $cached['incomplete_units'])
				&& \is_array($cached['incomplete_units'])
			) {
				return $cached;
			}
		}

		$summary = UnitCompletenessChecker::buildSummary();
		\set_transient(self::TRANSIENT_KEY, $summary, self::CACHE_TTL);

		return $summary;
	}

	/**
	 * @return list<array{
	 *   post_id: int,
	 *   title: string,
	 *   external_id: string,
	 *   provider: string,
	 *   edit_url: string,
	 *   missing: list<string>,
	 *   details: array<string, string>,
	 *   field_status: array<string, string>
	 * }>
	 */
	public static function getIncompleteUnits(bool $forceRefresh = false): array
	{
		return self::getSummary($forceRefresh)['incomplete_units'];
	}

	/**
	 * @return list<int>
	 */
	public static function getIncompletePostIds(bool $forceRefresh = false): array
	{
		$ids = [];
		foreach (self::getIncompleteUnits($forceRefresh) as $unit) {
			$ids[] = (int) $unit['post_id'];
		}

		return $ids;
	}
}
