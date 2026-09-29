<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Integrations\MultilingualBridge;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Search\SearchContext;
use BookingEngineConnector\Taxonomies\UnitCategoryTaxonomy;
use BookingEngineConnector\Sync\SyncPayloadEncoder;

/**
 * Kross `stop_sell`: last sellable calendar day from room types; listings hide once today reaches it.
 */
final class KrossStopSell
{
	public const META_KEY = 'bec_kross_stop_sell';

	private const OPTION_BACKFILL_DONE = 'bec_kross_stop_sell_backfill_done';

	/** @var list<int>|null */
	private static ?array $hiddenListingIdsMemo = null;

	/** Re-entrancy guard: resolving hidden IDs must not run {@see \WP_Query} (triggers {@see \pre_get_posts}). */
	private static bool $hiddenListingIdsInProgress = false;

	public static function register(): void
	{
		\add_action('pre_get_posts', [self::class, 'onPreGetPosts'], 25);
		\add_action('bec_after_unit_sync', [self::class, 'onAfterUnitSync'], 10, 3);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function onAfterUnitSync(int $postId, string $providerSlug, array $row): void
	{
		if ($providerSlug !== 'kross' || $postId < 1) {
			return;
		}

		self::$hiddenListingIdsMemo = null;
	}

	public static function metaKey(): string
	{
		return self::META_KEY;
	}

	/**
	 * @param array<string, mixed> $row Normalised remote row (with `raw`).
	 */
	public static function extractFromNormalisedRow(array $row): string
	{
		$raw = isset($row['raw']) && \is_array($row['raw']) ? $row['raw'] : $row;

		return self::normalizeDateString(isset($raw['stop_sell']) ? $raw['stop_sell'] : null);
	}

	public static function normalizeDateString(mixed $value): string
	{
		if ($value === null || $value === false) {
			return '';
		}

		$s = \trim((string) $value);
		if ($s === '') {
			return '';
		}

		if (\preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m) !== 1) {
			return '';
		}

		$date = $m[1];
		$dt   = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

		return $dt instanceof \DateTimeImmutable && $dt->format('Y-m-d') === $date ? $date : '';
	}

	public static function todaySiteDate(): string
	{
		return \current_time('Y-m-d');
	}

	/**
	 * Whether the unit must not appear in public listings (archive, loops, counts).
	 */
	public static function isHiddenFromListings(int $postId): bool
	{
		$stopSell = self::getStopSellDateForPost($postId);
		if ($stopSell === '') {
			return false;
		}

		return self::todaySiteDate() >= $stopSell;
	}

	/**
	 * Whether the search stay extends past the last sellable day (check-in or check-out strictly after stop_sell).
	 */
	public static function isStayInvalidForStopSell(int $postId, SearchContext $ctx): bool
	{
		$stopSell = self::getStopSellDateForPost($postId);
		if ($stopSell === '') {
			return false;
		}

		if (! $ctx->isComplete()) {
			return false;
		}

		$checkin  = $ctx->getCheckin();
		$checkout = $ctx->getCheckout();
		if ($checkin === '' || $checkout === '') {
			return false;
		}

		$checkin  = self::normalizeDateString($checkin);
		$checkout = self::normalizeDateString($checkout);
		if ($checkin === '' || $checkout === '') {
			return false;
		}

		return $checkin > $stopSell || $checkout > $stopSell;
	}

	public static function getStopSellDateForPost(int $postId): string
	{
		if ($postId < 1) {
			return '';
		}

		$stored = \get_post_meta($postId, self::META_KEY, true);
		if (\is_string($stored) && $stored !== '') {
			$normalized = self::normalizeDateString($stored);

			return $normalized;
		}

		$fromPayload = self::readStopSellFromPayloadMeta($postId);
		if ($fromPayload !== '') {
			\update_post_meta($postId, self::META_KEY, $fromPayload);
		}

		return $fromPayload;
	}

	/**
	 * Published unit post IDs that should be excluded from public listings today.
	 *
	 * @return list<int>
	 */
	public static function getPostIdsHiddenFromListings(): array
	{
		if (self::$hiddenListingIdsMemo !== null) {
			return self::$hiddenListingIdsMemo;
		}

		if (self::$hiddenListingIdsInProgress) {
			return [];
		}

		self::$hiddenListingIdsInProgress = true;

		try {
			self::maybeBackfillAllFromPayloads();

			self::$hiddenListingIdsMemo = self::queryHiddenPostIdsViaDb(self::todaySiteDate());
		} finally {
			self::$hiddenListingIdsInProgress = false;
		}

		return self::$hiddenListingIdsMemo;
	}

	/**
	 * @param array<string, mixed> $queryArgs {@see WP_Query} arguments.
	 * @return array<string, mixed>
	 */
	public static function mergeHiddenIdsIntoQueryArgs(array $queryArgs): array
	{
		$hidden = self::getPostIdsHiddenFromListings();
		if ($hidden === []) {
			return $queryArgs;
		}

		$existing = isset($queryArgs['post__not_in']) && \is_array($queryArgs['post__not_in'])
			? \array_map('intval', $queryArgs['post__not_in'])
			: [];

		$queryArgs['post__not_in'] = \array_values(\array_unique(\array_merge($existing, $hidden)));

		return $queryArgs;
	}

	public static function onPreGetPosts(\WP_Query $query): void
	{
		if (\is_admin()) {
			return;
		}

		if (self::$hiddenListingIdsInProgress) {
			return;
		}

		if ($query->get('suppress_filters')) {
			return;
		}

		if ($query->is_singular()) {
			return;
		}

		if (! self::queryTargetsUnitListings($query)) {
			return;
		}

		$hidden = self::getPostIdsHiddenFromListings();
		if ($hidden === []) {
			return;
		}

		$notIn = $query->get('post__not_in');
		if (! \is_array($notIn)) {
			$notIn = [];
		}

		$query->set(
			'post__not_in',
			\array_values(\array_unique(\array_merge(\array_map('intval', $notIn), $hidden)))
		);
	}

	private static function queryTargetsUnitListings(\WP_Query $query): bool
	{
		$slug = UnitPostType::getSlug();

		if ($query->is_post_type_archive($slug) || $query->is_tax(UnitCategoryTaxonomy::TAXONOMY)) {
			return true;
		}

		$postType = $query->get('post_type');
		if ($postType === $slug) {
			return true;
		}

		if (\is_array($postType) && \in_array($slug, $postType, true)) {
			return true;
		}

		return false;
	}

	private static function readStopSellFromPayloadMeta(int $postId): string
	{
		$syncJson = (string) \get_post_meta($postId, 'bec_sync_payload', true);
		if ($syncJson === '') {
			return '';
		}

		$payload = SyncPayloadEncoder::decodeStored($syncJson);
		if (! \is_array($payload)) {
			return '';
		}

		return self::extractFromNormalisedRow($payload);
	}

	private static function maybeBackfillAllFromPayloads(): void
	{
		if (\get_option(self::OPTION_BACKFILL_DONE, false)) {
			return;
		}

		$lastId = 0;

		do {
			$batch = self::queryCanonicalKrossUnitIdsForBackfill($lastId, 200);
			if ($batch === []) {
				break;
			}

			foreach ($batch as $postId) {
				$postId = (int) $postId;
				if ($postId < 1) {
					continue;
				}

				$existing = \get_post_meta($postId, self::META_KEY, true);
				if (\is_string($existing) && $existing !== '') {
					continue;
				}

				$fromPayload = self::readStopSellFromPayloadMeta($postId);
				\update_post_meta($postId, self::META_KEY, $fromPayload);
			}

			$lastId = (int) max($batch);
		} while (\count($batch) === 200);

		\update_option(self::OPTION_BACKFILL_DONE, true, false);
	}

	/**
	 * @return list<int>
	 */
	private static function queryHiddenPostIdsViaDb(string $today): array
	{
		global $wpdb;

		$postType   = \esc_sql(UnitPostType::getSlug());
		$providerKey = \esc_sql('bec_provider_slug');
		$stopKey    = \esc_sql(self::META_KEY);

		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS pm_provider
				ON pm_provider.post_id = p.ID
				AND pm_provider.meta_key = %s
				AND pm_provider.meta_value = %s
			INNER JOIN {$wpdb->postmeta} AS pm_stop
				ON pm_stop.post_id = p.ID
				AND pm_stop.meta_key = %s
				AND pm_stop.meta_value <> ''
				AND pm_stop.meta_value <= %s
			WHERE p.post_type = %s
			AND p.post_status = 'publish'",
			$providerKey,
			'kross',
			$stopKey,
			$today,
			$postType
		);

		$col = $wpdb->get_col($sql);
		if (! \is_array($col)) {
			return [];
		}

		return \array_values(\array_map('intval', $col));
	}

	/**
	 * Canonical Kross unit IDs for one-time stop_sell meta backfill (no {@see \WP_Query}).
	 *
	 * @return list<int>
	 */
	private static function queryCanonicalKrossUnitIdsForBackfill(int $afterId, int $limit): array
	{
		global $wpdb;

		$postType        = \esc_sql(UnitPostType::getSlug());
		$providerKey     = \esc_sql('bec_provider_slug');
		$translationKey  = \esc_sql(MultilingualBridge::META_TRANSLATION_OF);
		$limit           = $limit > 0 ? $limit : 200;

		$sql = $wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS pm_provider
				ON pm_provider.post_id = p.ID
				AND pm_provider.meta_key = %s
				AND pm_provider.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} AS pm_tr
				ON pm_tr.post_id = p.ID
				AND pm_tr.meta_key = %s
			WHERE p.post_type = %s
			AND pm_tr.meta_id IS NULL
			AND p.ID > %d
			ORDER BY p.ID ASC
			LIMIT %d",
			$providerKey,
			'kross',
			$translationKey,
			$postType,
			$afterId,
			$limit
		);

		$col = $wpdb->get_col($sql);
		if (! \is_array($col)) {
			return [];
		}

		return \array_values(\array_map('intval', $col));
	}
}
