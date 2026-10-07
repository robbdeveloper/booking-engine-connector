<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Integrations\MultilingualBridge;

/**
 * Kross `hide_be`: room types hidden from the booking engine are stored as drafts.
 *
 * Sync still upserts title, content, gallery, and meta. The next sync publishes the same
 * post when `hide_be` is no longer true. Linked translation posts follow the canonical status.
 */
final class KrossHideFromBookingEngine
{
	public static function register(): void
	{
		\add_filter('bec_sync_unit_post_data', [self::class, 'filterPostData'], 10, 4);
		\add_action('bec_after_unit_sync', [self::class, 'alignTranslationStatuses'], 30, 3);
	}

	/**
	 * @param array<string, mixed> $postData
	 * @param array<string, mixed> $row Normalised remote row (with `raw`).
	 */
	public static function filterPostData(array $postData, array $row, string $providerSlug, int $postId): array
	{
		unset($postId);

		if ($providerSlug !== 'kross') {
			return $postData;
		}

		$postData['post_status'] = self::isHidden($row) ? 'draft' : 'publish';

		return $postData;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function alignTranslationStatuses(int $postId, string $providerSlug, array $row): void
	{
		unset($row);

		if ($providerSlug !== 'kross' || $postId < 1) {
			return;
		}

		$status = \get_post_status($postId);
		if ($status !== 'draft' && $status !== 'publish') {
			return;
		}

		foreach (self::linkedTranslationIds($postId) as $translationId) {
			$current = \get_post_status($translationId);
			if ($current === false || $current === 'trash' || $current === $status) {
				continue;
			}

			\wp_update_post(
				[
					'ID'          => $translationId,
					'post_status' => $status,
				],
				true
			);
		}
	}

	/**
	 * @param array<string, mixed> $row Normalised remote row (with `raw`).
	 */
	public static function isHidden(array $row): bool
	{
		$raw = isset($row['raw']) && \is_array($row['raw']) ? $row['raw'] : $row;
		if (! isset($raw['hide_be'])) {
			return false;
		}

		return \filter_var($raw['hide_be'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
	}

	/**
	 * @return list<int>
	 */
	private static function linkedTranslationIds(int $canonicalId): array
	{
		$ids = [];

		$map = \get_post_meta($canonicalId, MultilingualBridge::META_TRANSLATION_POST_IDS, true);
		if (\is_array($map)) {
			foreach ($map as $linkedId) {
				$linkedId = (int) $linkedId;
				if ($linkedId > 0 && $linkedId !== $canonicalId) {
					$ids[ $linkedId ] = $linkedId;
				}
			}
		}

		if (MultilingualBridge::isActive()) {
			$defaultLang = MultilingualBridge::getDefaultLanguage();
			foreach (MultilingualBridge::getActiveLanguages() as $lang) {
				if ($lang === $defaultLang) {
					continue;
				}

				$linkedId = (int) ( MultilingualBridge::getTranslatedPostId($canonicalId, $lang) ?? 0 );
				if ($linkedId > 0 && $linkedId !== $canonicalId) {
					$ids[ $linkedId ] = $linkedId;
				}
			}
		}

		return \array_values($ids);
	}
}
