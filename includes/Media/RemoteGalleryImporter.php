<?php

declare(strict_types=1);

namespace BookingEngineConnector\Media;

use BookingEngineConnector\PostTypes\UnitPostType;

/**
 * Downloads remote unit gallery images with per-unit attachment ownership and stable image keys.
 *
 * - Deduplication is per unit + {@see self::GALLERY_IMAGE_KEY_META}, not global URL.
 * - Fingerprints: {@see self::IMAGE_SET_HASH_META} (order-insensitive) and {@see self::IMAGE_ORDER_HASH_META} (order).
 * - Legacy: {@see self::SOURCE_HASH_META} (ordered URL list) is still read for first-run migration.
 * - On removal from a unit, attachments are deleted only when not referenced by any `bec_unit` gallery or featured image.
 * - Orphan Library files (same `_bec_source_url`, owner unit deleted/trashed/unscoped): reclaimed or duplicated onto the syncing unit instead of silently skipping imports.
 */
final class RemoteGalleryImporter
{
	public const SOURCE_URL_META = '_bec_source_url';

	/** Parent unit post (bec_unit) for this gallery image attachment. */
	public const GALLERY_UNIT_ID_META = '_bec_gallery_unit_id';

	/** Stable id for this image within the unit (provider id or hash). */
	public const GALLERY_IMAGE_KEY_META = 'bec_gallery_image_key';

	/** sha256 of local file (for same-bytes dedupe when the remote key/url changes). */
	public const GALLERY_FILE_HASH_META = 'bec_gallery_file_hash';

	/** @deprecated Use IMAGE_SET_HASH_META + IMAGE_ORDER_HASH_META. Kept for migration. */
	public const SOURCE_HASH_META = 'bec_sync_gallery_source_hash';

	/** Post meta on the unit: sha256 of sorted image keys (JSON). */
	public const IMAGE_SET_HASH_META = 'bec_sync_gallery_image_set_hash';

	/** Post meta on the unit: sha256 of ordered image keys (JSON). */
	public const IMAGE_ORDER_HASH_META = 'bec_sync_gallery_image_order_hash';

	/**
	 * @param list<string> $urls Ordered remote image URLs (https).
	 * @return list<int> Attachment IDs in the same order (skips failed downloads).
	 */
	public static function importUrls(int $parentPostId, array $urls): array
	{
		$urls = self::normalizeUrlList($urls);
		if ($urls === []) {
			return self::importGalleryItems($parentPostId, [], null);
		}

		$items = self::buildItemsFromPlainUrlList($urls);

		return self::importGalleryItems($parentPostId, $items, $items[0]['url'] ?? null);
	}

	/**
	 * @param array{items?: list<array<string, mixed>>, urls?: list<string>, featured_url?: string|null} $payload
	 * @return list<int>
	 */
	public static function importFromRemotePayload(int $parentPostId, array $payload): array
	{
		$items    = self::normalizeRemoteItems($payload);
		$featured = isset($payload['featured_url']) ? \trim((string) $payload['featured_url']) : '';
		$feat     = $featured !== '' && self::isHttpUrl($featured) ? \esc_url_raw($featured) : null;
		if ($items === [] && $feat === null) {
			if (isset($payload['urls']) && is_array($payload['urls']) && $payload['urls'] !== []) {
				return self::importUrls($parentPostId, self::normalizeUrlList($payload['urls']));
			}
		}

		return self::importGalleryItems($parentPostId, $items, $feat);
	}

	/**
	 * @param list<array{url: string, key: string, order: int, main?: bool}> $items
	 * @return list<int>
	 */
	public static function importGalleryItems(int $parentPostId, array $items, ?string $featuredUrl): array
	{
		\usort(
			$items,
			static function (array $a, array $b): int {
				return ( $a['order'] <=> $b['order'] ) ?: ( $a['key'] <=> $b['key'] );
			}
		);

		$urls = [];
		foreach ($items as $it) {
			$urls[] = (string) $it['url'];
		}
		$urls = self::normalizeUrlList($urls);

		if ($items === [] || $urls === []) {
			$oldForClear = self::readStoredGalleryIdsOnly($parentPostId);
			\delete_post_thumbnail($parentPostId);
			self::clearUnitGalleryMeta($parentPostId);
			self::deleteRemovedFromPreviousSync($parentPostId, [], true, $oldForClear, []);

			return [];
		}

		if (! \apply_filters('bec_sync_import_gallery_images', true, $parentPostId, $urls)) {
			return [];
		}

		$orderedKeys = [];
		foreach ($items as $it) {
			$orderedKeys[] = (string) $it['key'];
		}
		$setHash   = self::hashKeyListSet($orderedKeys);
		$orderHash = self::hashKeyListOrdered($orderedKeys);
		$urlHash   = self::hashUrlList($urls);

		$ignore = \apply_filters('bec_sync_gallery_ignore_hash', false, $parentPostId, $urls, $orderHash);

		if (! $ignore) {
			$storedSet   = (string) \get_post_meta($parentPostId, self::IMAGE_SET_HASH_META, true);
			$storedOrder = (string) \get_post_meta($parentPostId, self::IMAGE_ORDER_HASH_META, true);
			$oldUrlHash  = (string) \get_post_meta($parentPostId, self::SOURCE_HASH_META, true);

			if ($storedSet !== '' && $storedOrder !== '' && \hash_equals($storedSet, $setHash) && \hash_equals($storedOrder, $orderHash)) {
				$reuse = self::reuseGalleryWhenHashesMatch($parentPostId, $items, $urls);
				if ($reuse !== null) {
					self::reparentAttachments($parentPostId, $reuse);
					self::markAttachmentsWithKeys($parentPostId, $items, $reuse, $urls);
					self::applyFeaturedImage($parentPostId, $featuredUrl, $items, $reuse, $urls);
					self::updateUnitHashes($parentPostId, $setHash, $orderHash, $urlHash);
					\update_post_meta($parentPostId, self::SOURCE_HASH_META, $urlHash);

					return $reuse;
				}
			}

			if ($storedSet === '' && $oldUrlHash !== '' && \hash_equals($oldUrlHash, $urlHash)) {
				$legacy = self::reuseLegacyUrlGallery($parentPostId, $items, $urls);
				if ($legacy !== null) {
					$oldIdsBefore = self::readStoredGalleryIdsOnly($parentPostId);
					self::reparentAttachments($parentPostId, $legacy);
					self::markAttachmentsWithKeys($parentPostId, $items, $legacy, $urls);
					self::applyFeaturedImage($parentPostId, $featuredUrl, $items, $legacy, $urls);
					self::updateUnitHashes($parentPostId, $setHash, $orderHash, $urlHash);
					\update_post_meta($parentPostId, self::SOURCE_HASH_META, $urlHash);
					self::deleteRemovedFromPreviousSync($parentPostId, $legacy, false, $oldIdsBefore, $legacy);

					return $legacy;
				}
			}

			if ($storedSet !== '' && \hash_equals($storedSet, $setHash) && ! \hash_equals((string) \get_post_meta($parentPostId, self::IMAGE_ORDER_HASH_META, true), $orderHash)) {
				$reordered = self::reorderGalleryOnly($parentPostId, $items, $urls);
				if ($reordered !== null) {
					$oldIdsBefore = self::readStoredGalleryIdsOnly($parentPostId);
					self::reparentAttachments($parentPostId, $reordered);
					self::applyFeaturedImage($parentPostId, $featuredUrl, $items, $reordered, $urls);
					self::updateUnitHashes($parentPostId, $setHash, $orderHash, $urlHash);
					\update_post_meta($parentPostId, self::SOURCE_HASH_META, $urlHash);
					self::deleteRemovedFromPreviousSync($parentPostId, $reordered, false, $oldIdsBefore, $reordered);

					return $reordered;
				}
			}
		}

		return self::syncGalleryFull($parentPostId, $items, $featuredUrl, $setHash, $orderHash, $urlHash, $ignore);
	}

	/**
	 * Stable hash for an ordered list of URLs (remote gallery fingerprint, legacy + migration).
	 */
	public static function hashUrlList(array $urls): string
	{
		$urls = self::normalizeUrlList($urls);
		$json = \wp_json_encode($urls, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

		return $json !== false ? \hash('sha256', $json) : '';
	}

	/**
	 * @param list<string> $keys
	 */
	public static function hashKeyListSet(array $keys): string
	{
		$keys = \array_values(\array_unique(\array_map('strval', $keys)));
		\sort($keys, \SORT_STRING);
		$json = \wp_json_encode($keys, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

		return $json !== false ? \hash('sha256', $json) : '';
	}

	/**
	 * @param list<string> $keys
	 */
	public static function hashKeyListOrdered(array $keys): string
	{
		$keys = \array_map('strval', $keys);
		$json = \wp_json_encode($keys, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

		return $json !== false ? \hash('sha256', $json) : '';
	}

	/**
	 * @param list<string> $urls
	 * @return array<string, int>
	 */
	public static function findAttachmentIdsBySourceUrls(array $urls): array
	{
		$urls = self::normalizeUrlList($urls);
		if ($urls === []) {
			return [];
		}

		$q = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'     => self::SOURCE_URL_META,
						'value'   => $urls,
						'compare' => 'IN',
					],
				],
			]
		);

		$map = [];
		foreach ($q->posts as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				continue;
			}
			$u = (string) \get_post_meta($id, self::SOURCE_URL_META, true);
			$u = \esc_url_raw(\trim($u));
			if ($u !== '') {
				$map[ $u ] = $id;
			}
		}

		return $map;
	}

	/**
	 * All image attachments whose gallery source URL meta matches exactly (may be multiple).
	 *
	 * @return list<int>
	 */
	private static function findAttachmentIdsWithExactSourceUrl(string $normalizedUrl): array
	{
		if ($normalizedUrl === '') {
			return [];
		}

		$q = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'     => self::SOURCE_URL_META,
						'value'   => $normalizedUrl,
						'compare' => '=',
					],
				],
			]
		);

		$out = [];
		foreach ($q->posts as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * True when this attachment may be reclaimed for $unitId without copying (unscoped, same unit, deleted unit, or trashed unit owner).
	 */
	private static function attachmentMayReuseOrClaimForGalleryUnit(int $unitId, int $attachmentId): bool
	{
		if ($unitId < 1 || $attachmentId < 1 || \get_post_type($attachmentId) !== 'attachment') {
			return false;
		}

		$owned = (int) \get_post_meta($attachmentId, self::GALLERY_UNIT_ID_META, true);

		if ($owned === 0 || $owned === $unitId) {
			return true;
		}

		$post = \get_post($owned);
		if (! $post instanceof \WP_Post) {
			return true;
		}

		if ($post->post_type !== UnitPostType::getSlug()) {
			return false;
		}

		return $post->post_status === 'trash';
	}

	/**
	 * Re-link an existing library file to this unit before downloading again (e.g. unit was deleted but media kept).
	 *
	 * @param list<int> $oldGalleryIds
	 */
	private static function reuseLibraryAttachmentForGalleryUnit(int $unitId, string $key, string $url, array $oldGalleryIds): ?int
	{
		unset($oldGalleryIds);
		$url = \esc_url_raw(\trim($url));
		if ($url === '' || ! self::isHttpUrl($url)) {
			return null;
		}

		$candidates = self::findAttachmentIdsWithExactSourceUrl($url);
		if ($candidates === []) {
			return null;
		}

		\rsort($candidates, \SORT_NUMERIC);

		foreach ($candidates as $aid) {
			$aid = (int) $aid;
			if ($aid < 1 || ! \wp_attachment_is_image($aid)) {
				continue;
			}

			$file = \get_attached_file($aid, true);
			if (! \is_string($file) || $file === '' || ! \is_readable($file)) {
				continue;
			}

			$metaUrl = \esc_url_raw(\trim((string) \get_post_meta($aid, self::SOURCE_URL_META, true)));
			if ($metaUrl !== $url) {
				continue;
			}

			$kmeta = (string) \get_post_meta($aid, self::GALLERY_IMAGE_KEY_META, true);
			if ($kmeta !== '' && $kmeta !== $key) {
				continue;
			}

			if (self::attachmentMayReuseOrClaimForGalleryUnit($unitId, $aid)) {
				return self::reconcileExistingAttachment($aid, $unitId, $key, $url);
			}

			$dup = self::duplicateAttachmentForUnit($aid, $unitId, $key, $url, []);
			if ($dup !== null) {
				return $dup;
			}
		}

		return null;
	}

	/**
	 * Resolves a gallery attachment for this unit: prefers unit+URL ownership, else legacy.
	 */
	public static function findAttachmentIdBySourceUrl(string $url): ?int
	{
		$url = \esc_url_raw(\trim($url));
		if ($url === '') {
			return null;
		}
		$map = self::findAttachmentIdsBySourceUrls([ $url ]);

		return $map[ $url ] ?? null;
	}

	/**
	 * Attachment for this unit’s gallery with a given remote URL (for featured image after import).
	 */
	public static function findAttachmentIdForUnitAndUrl(int $unitId, string $url): ?int
	{
		$url = \esc_url_raw(\trim($url));
		if ($url === '' || $unitId < 1) {
			return null;
		}
		$q = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => self::GALLERY_UNIT_ID_META,
						'value' => (string) $unitId,
					],
					[
						'key'   => self::SOURCE_URL_META,
						'value' => $url,
					],
				],
			]
		);
		$id = isset($q->posts[0]) ? (int) $q->posts[0] : 0;

		return $id > 0 ? $id : null;
	}

	/**
	 * @param list<array<string, mixed>> $payload
	 * @return list<array{url: string, key: string, order: int, main: bool}>
	 */
	private static function normalizeRemoteItems(array $payload): array
	{
		$out = [];
		if (isset($payload['items']) && is_array($payload['items']) && $payload['items'] !== []) {
			$i = 0;
			foreach ($payload['items'] as $row) {
				if (! is_array($row)) {
					++$i;
					continue;
				}
				$u = isset($row['url']) ? \esc_url_raw(\trim((string) $row['url'])) : '';
				if ($u === '' || ! self::isHttpUrl($u)) {
					++$i;
					continue;
				}
				$key = isset($row['key']) ? \trim((string) $row['key']) : '';
				if ($key === '') {
					$key = \hash('sha256', $u);
				}
				$order = isset($row['order']) && is_numeric($row['order']) ? (int) $row['order'] : $i;
				$main  = ! empty($row['main']);
				$out[] = [
					'url'   => $u,
					'key'   => $key,
					'order' => $order,
					'main'  => $main,
				];
				++$i;
			}
			$used = [];
			foreach ($out as $k => $row) {
				$base = $row['key'];
				$kk   = $base;
				$n    = 0;
				while (isset($used[ $kk ])) {
					++$n;
					$kk = $base . ':' . (string) $n;
				}
				$used[ $kk ]       = true;
				$out[ $k ]['key'] = $kk;
			}

			return $out;
		}

		if (isset($payload['urls']) && is_array($payload['urls']) && $payload['urls'] !== []) {
			return self::buildItemsFromPlainUrlList(self::normalizeUrlList($payload['urls']));
		}

		return [];
	}

	/**
	 * @param list<string> $urls
	 * @return list<array{url: string, key: string, order: int, main: bool}>
	 */
	private static function buildItemsFromPlainUrlList(array $urls): array
	{
		$used  = [];
		$items = [];
		$ord   = 0;
		foreach ($urls as $u) {
			$base = \hash('sha256', $u);
			$k    = $base;
			$n    = 0;
			while (isset($used[ $k ])) {
				++$n;
				$k = $base . ':' . (string) $n;
			}
			$used[ $k ] = true;
			$items[]    = [
				'url'   => $u,
				'key'   => $k,
				'order' => $ord,
				'main'  => $ord === 0,
			];
			++$ord;
		}

		return $items;
	}

	/**
	 * @param list<string> $urls
	 * @return list<string>
	 */
	private static function normalizeUrlList(array $urls): array
	{
		$out = [];
		foreach ($urls as $u) {
			$u = \esc_url_raw(\trim((string) $u));
			if ($u === '' || ! self::isHttpUrl($u)) {
				continue;
			}
			$out[] = $u;
		}

		return $out;
	}

	/**
	 * @param list<array{url: string, key: string, order: int, main: bool}> $items
	 * @param list<string> $urls
	 * @return list<int>|null
	 */
	private static function reuseGalleryWhenHashesMatch(int $postId, array $items, array $urls): ?array
	{
		$raw = \get_post_meta($postId, 'bec_core_gallery', true);
		$ids = self::decodeIdList($raw);
		if (\count($ids) !== \count($items)) {
			return null;
		}
		foreach ($urls as $i => $u) {
			$key = (string) $items[ $i ]['key'];
			$aid = $ids[ $i ] ?? 0;
			if ($aid < 1 || \get_post_type($aid) !== 'attachment') {
				return null;
			}
			if (! self::attachmentMaySyncWithUnitGallery($postId, $aid)) {
				return null;
			}
			$attKey = (string) \get_post_meta($aid, self::GALLERY_IMAGE_KEY_META, true);
			if ($attKey !== '' && $attKey !== $key) {
				return null;
			}
			$attUrl = \esc_url_raw(\trim((string) \get_post_meta($aid, self::SOURCE_URL_META, true)));
			if ($attUrl === '' || $attUrl !== $u) {
				return null;
			}
		}

		return $ids;
	}

	/**
	 * @param list<array{url: string, key: string, order: int, main: bool}> $items
	 * @param list<string> $urls
	 * @return list<int>|null
	 */
	private static function reuseLegacyUrlGallery(int $postId, array $items, array $urls): ?array
	{
		unset($items);

		$raw = \get_post_meta($postId, 'bec_core_gallery', true);
		$ids = self::decodeIdList($raw);
		if (\count($ids) !== \count($urls)) {
			return null;
		}
		foreach ($urls as $i => $u) {
			$aid = $ids[ $i ] ?? 0;
			if ($aid < 1 || \get_post_type($aid) !== 'attachment') {
				return null;
			}
			if (! self::attachmentMaySyncWithUnitGallery($postId, $aid)) {
				return null;
			}
			$attUrl = \esc_url_raw(\trim((string) \get_post_meta($aid, self::SOURCE_URL_META, true)));
			if ($attUrl === '' || $attUrl !== $u) {
				return null;
			}
		}

		return $ids;
	}

	/**
	 * Fast-path gallery reuse requires the attachment to be linked to this unit (or legacy unscoped).
	 */
	private static function attachmentMaySyncWithUnitGallery(int $unitId, int $attachmentId): bool
	{
		if ($unitId < 1 || $attachmentId < 1 || \get_post_type($attachmentId) !== 'attachment') {
			return false;
		}
		$owned = (int) \get_post_meta($attachmentId, self::GALLERY_UNIT_ID_META, true);

		return $owned === 0 || $owned === $unitId;
	}

	/**
	 * @param list<array{url: string, key: string, order: int, main: bool}> $items
	 * @param list<string> $urls
	 * @return list<int>|null
	 */
	private static function reorderGalleryOnly(int $postId, array $items, array $urls): ?array
	{
		$keyToId = self::keyToAttachmentIdMapForUnit($postId);
		if ($keyToId === []) {
			return null;
		}
		$need = [];
		foreach ($items as $it) {
			$need[] = (string) $it['key'];
		}
		foreach ($need as $k) {
			if (! isset($keyToId[ $k ])) {
				return null;
			}
		}
		$out = [];
		foreach ($need as $k) {
			$out[] = (int) $keyToId[ $k ];
		}
		foreach ($urls as $i => $u) {
			$aid = $out[ $i ] ?? 0;
			$st  = \esc_url_raw(\trim((string) \get_post_meta($aid, self::SOURCE_URL_META, true)));
			if ($st !== $u) {
				return null;
			}
		}

		return $out;
	}

	/**
	 * @return array<string, int>
	 */
	private static function keyToAttachmentIdMapForUnit(int $unitId): array
	{
		$q = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'   => self::GALLERY_UNIT_ID_META,
						'value' => (string) $unitId,
					],
					[
						'key'     => self::GALLERY_IMAGE_KEY_META,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$map = [];
		foreach ($q->posts as $id) {
			$id  = (int) $id;
			$ake = (string) \get_post_meta($id, self::GALLERY_IMAGE_KEY_META, true);
			if ($ake !== '') {
				$map[ $ake ] = $id;
			}
		}

		return $map;
	}

	/**
	 * @param list<array{url: string, key: string, order: int, main: bool}> $items
	 * @param list<int> $ids
	 * @param list<string> $urls
	 */
	private static function markAttachmentsWithKeys(int $unitId, array $items, array $ids, array $urls): void
	{
		foreach ($items as $i => $it) {
			$aid = $ids[ $i ] ?? 0;
			if ($aid < 1) {
				continue;
			}
			$u   = $urls[ $i ] ?? '';
			$key = (string) $it['key'];
			\update_post_meta($aid, self::GALLERY_IMAGE_KEY_META, $key);
			\update_post_meta($aid, self::GALLERY_UNIT_ID_META, (string) $unitId);
			if ($u !== '') {
				\update_post_meta($aid, self::SOURCE_URL_META, $u);
			}
			self::ensureFileHashMeta($aid);
		}
	}

	/**
	 * @param list<array{url: string, key: string, order: int, main: bool}> $items
	 * @param list<int> $outIds
	 * @return list<int>
	 */
	private static function syncGalleryFull(
		int $unitId,
		array $items,
		?string $featuredUrl,
		string $setHash,
		string $orderHash,
		string $urlHash,
		bool $ignoreHashes
	): array {
		unset($ignoreHashes);
		self::loadDependencies();

		$oldIds  = self::readStoredGalleryIdsOnly($unitId);
		$keyToId = self::keyToAttachmentIdMapForUnit($unitId);
		$outByIndex    = [];
		$urlOutByIndex = [];
		$pending       = [];
		$index   = 0;

		foreach ($items as $it) {
			++$index;
			$key = (string) $it['key'];
			$url = (string) $it['url'];
			$id  = $keyToId[ $key ] ?? null;
			if ($id !== null) {
				$resolved = self::reconcileExistingAttachment(
					(int) $id,
					$unitId,
					$key,
					$url
				);
				$outByIndex[ $index ]    = $resolved;
				$urlOutByIndex[ $index ] = $url;
				continue;
			}

			$adopted = self::adoptOrCloneFromPreviousGallery(
				$unitId,
				$key,
				$url,
				$oldIds
			);
			if ($adopted !== null) {
				$keyToId[ $key ] = $adopted;
				$outByIndex[ $index ]    = $adopted;
				$urlOutByIndex[ $index ] = $url;
				continue;
			}

			$fromLib = self::reuseLibraryAttachmentForGalleryUnit($unitId, $key, $url, $oldIds);
			if ($fromLib !== null) {
				$keyToId[ $key ] = $fromLib;
				$outByIndex[ $index ]    = $fromLib;
				$urlOutByIndex[ $index ] = $url;
				continue;
			}

			$pending[] = [
				'index' => $index,
				'key'   => $key,
				'url'   => $url,
			];
		}

		$pendingUrls = [];
		foreach ($pending as $row) {
			$pendingUrls[] = (string) $row['url'];
		}

		$downloads = self::downloadUrlsToTempFiles($pendingUrls, $unitId);

		foreach ($pending as $row) {
			$index = (int) $row['index'];
			$key   = (string) $row['key'];
			$url   = (string) $row['url'];
			$tmp   = $downloads[ $url ] ?? null;
			if (! is_string($tmp) || $tmp === '' || ! is_readable($tmp) || \filesize($tmp) < 1) {
				@\is_string($tmp) && @\unlink($tmp);
				continue;
			}
			$bytesHash = @\hash_file('sha256', $tmp) ?: '';
			$dupId     = self::findByUnitAndFileHash($unitId, $bytesHash, $key);
			if ($dupId !== null) {
				@\unlink($tmp);
				\update_post_meta($dupId, self::SOURCE_URL_META, $url);
				\update_post_meta($dupId, self::GALLERY_IMAGE_KEY_META, $key);
				\update_post_meta($dupId, self::GALLERY_UNIT_ID_META, (string) $unitId);
				$outByIndex[ $index ]    = $dupId;
				$urlOutByIndex[ $index ] = $url;
				continue;
			}

			$ext  = self::extensionFromPathOrUrl($tmp, $url);
			$base = GalleryImageSyncSettings::composeGalleryBasename($unitId, $index, $ext);
			$arr  = [ 'name' => $base, 'tmp_name' => $tmp ];
			$aid  = \media_handle_sideload($arr, $unitId);
			if (\is_wp_error($aid)) {
				@\unlink($tmp);
				continue;
			}
			$aid = (int) $aid;
			\update_post_meta($aid, self::SOURCE_URL_META, $url);
			\update_post_meta($aid, self::GALLERY_IMAGE_KEY_META, $key);
			\update_post_meta($aid, self::GALLERY_UNIT_ID_META, (string) $unitId);
			if ($bytesHash !== '') {
				\update_post_meta($aid, self::GALLERY_FILE_HASH_META, $bytesHash);
			}
			$outByIndex[ $index ]    = $aid;
			$urlOutByIndex[ $index ] = $url;
		}

		\ksort($outByIndex, \SORT_NUMERIC);
		\ksort($urlOutByIndex, \SORT_NUMERIC);
		$out    = \array_values($outByIndex);
		$urlOut = \array_values($urlOutByIndex);

		self::applyFeaturedImage($unitId, $featuredUrl, $items, $out, $urlOut);
		self::deleteRemovedFromPreviousSync($unitId, $out, false, $oldIds, $out);
		self::updateUnitHashes($unitId, $setHash, $orderHash, $urlHash);
		\update_post_meta($unitId, self::SOURCE_HASH_META, $urlHash);
		self::reparentAttachments($unitId, $out);

		return $out;
	}

	/**
	 * @param list<int> $oldIds
	 * @param list<int> $newIdsForThisUnit In-memory new gallery (post meta is not written yet; used for ref counting).
	 */
	private static function deleteRemovedFromPreviousSync(int $unitId, array $newIds, bool $allRemoved, ?array $oldIds = null, ?array $newIdsForThisUnit = null): void
	{
		$old   = $oldIds ?? self::readStoredGalleryIdsOnly($unitId);
		$fresh = $newIdsForThisUnit !== null ? $newIdsForThisUnit : $newIds;
		if ($allRemoved) {
			$candidates = $old;
		} else {
			$keep       = \array_fill_keys($newIds, true);
			$candidates = [];
			foreach ($old as $oid) {
				if (! isset($keep[ $oid ])) {
					$candidates[] = $oid;
				}
			}
		}
		foreach ($candidates as $cid) {
			$ref = (int) $cid;
			if ($ref < 1) {
				continue;
			}
			$u = (int) \get_post_meta($ref, self::GALLERY_UNIT_ID_META, true);
			if ($u > 0 && $u !== $unitId) {
				continue;
			}
			if (self::countUnitReferencesToAttachmentRespectingNewGallery($ref, $unitId, $fresh) < 1) {
				\wp_delete_attachment($ref, true);
			}
		}
	}

	/**
	 * Featured image follows Kross/list order via URL pairing (handles partial galleries where `$out[$i]` no longer aligns with `$items[$i]`).
	 *
	 * @param list<array<string, mixed>>                                                   $items
	 * @param list<int>                                                                    $attachmentIdsOut
	 * @param list<string>                                                                 $attachmentUrlsAligned Parallel URL per attachment ID (same count as `$attachmentIdsOut` when coming from importer).
	 */
	private static function applyFeaturedImage(int $unitId, ?string $featuredUrl, array $items, array $attachmentIdsOut, array $attachmentUrlsAligned): void
	{
		if ($attachmentIdsOut === []) {
			\delete_post_thumbnail($unitId);

			return;
		}

		$urlToAtt = [];
		$nPairs   = \min(\count($attachmentIdsOut), \count($attachmentUrlsAligned));

		for ($i = 0; $i < $nPairs; ++$i) {
			$tid = (int) $attachmentIdsOut[ $i ];
			if ($tid < 1) {
				continue;
			}
			$u = \esc_url_raw(\trim((string) $attachmentUrlsAligned[ $i ]));
			if ($u !== '' && self::isHttpUrl($u)) {
				$urlToAtt[ $u ] = $tid;
			}
		}

		foreach ($items as $it) {
			if (empty($it['main'])) {
				continue;
			}
			$uItem = isset($it['url']) ? \esc_url_raw(\trim((string) $it['url'])) : '';
			if ($uItem !== '' && isset($urlToAtt[ $uItem ])) {
				\set_post_thumbnail($unitId, (int) $urlToAtt[ $uItem ]);

				return;
			}
		}

		$feat = $featuredUrl !== null && $featuredUrl !== '' ? \esc_url_raw(\trim($featuredUrl)) : null;
		if ($feat !== null && $feat !== '' && isset($urlToAtt[ $feat ])) {
			\set_post_thumbnail($unitId, (int) $urlToAtt[ $feat ]);

			return;
		}

		\set_post_thumbnail($unitId, (int) $attachmentIdsOut[0] );

		if ((int) \get_post_thumbnail_id($unitId) < 1) {
			foreach ($attachmentIdsOut as $fallbackId) {
				$fallbackId = (int) $fallbackId;
				if ($fallbackId > 0) {
					\set_post_thumbnail($unitId, $fallbackId);
					break;
				}
			}
		}
	}

	/**
	 * @param list<int> $ids
	 */
	private static function reparentAttachments(int $parentPostId, array $ids): void
	{
		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				continue;
			}
			\wp_update_post([
				'ID'          => $id,
				'post_parent' => $parentPostId,
			]);
		}
	}

	private static function clearUnitGalleryMeta(int $unitId): void
	{
		\delete_post_meta($unitId, self::SOURCE_HASH_META);
		\delete_post_meta($unitId, self::IMAGE_SET_HASH_META);
		\delete_post_meta($unitId, self::IMAGE_ORDER_HASH_META);
	}

	/**
	 * Gallery attachment IDs as stored in post meta (before a sync overwrites the field).
	 *
	 * @return list<int>
	 */
	private static function readStoredGalleryIdsOnly(int $unitId): array
	{
		$raw = \get_post_meta($unitId, 'bec_core_gallery', true);

		return self::decodeIdList($raw);
	}

	/**
	 * @return list<int>
	 */
	private static function decodeIdList($raw): array
	{
		if (is_string($raw) && $raw !== '') {
			$decoded = \json_decode($raw, true);
		} elseif (is_array($raw)) {
			$decoded = $raw;
		} else {
			return [];
		}
		if (! is_array($decoded) || $decoded === []) {
			return [];
		}
		$ids = [];
		foreach ($decoded as $v) {
			if (is_numeric($v)) {
				$n = (int) $v;
				if ($n > 0) {
					$ids[] = $n;
				}
			}
		}

		return $ids;
	}

	private static function updateUnitHashes(int $unitId, string $setHash, string $orderHash, string $urlHash): void
	{
		unset($urlHash);
		\update_post_meta($unitId, self::IMAGE_SET_HASH_META, $setHash);
		\update_post_meta($unitId, self::IMAGE_ORDER_HASH_META, $orderHash);
	}

	/**
	 * @param int $attachmentId
	 */
	private static function countUnitReferencesToAttachment(int $attachmentId): int
	{
		$slug = UnitPostType::getSlug();
		$q    = new \WP_Query(
			[
				'post_type'      => $slug,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		$n = 0;
		foreach ($q->posts as $pid) {
			$pid = (int) $pid;
			$uses = false;
			if ((int) \get_post_thumbnail_id($pid) === $attachmentId) {
				$uses = true;
			}
			$g = (string) \get_post_meta($pid, 'bec_core_gallery', true);
			if ($g !== '') {
				$ids = \json_decode($g, true);
				if (is_array($ids)) {
					foreach ($ids as $v) {
						if (is_numeric($v) && (int) $v === $attachmentId) {
							$uses = true;
							break;
						}
					}
				}
			}
			if ($uses) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * @param list<int> $newGalleryForSyncingUnit
	 */
	private static function countUnitReferencesToAttachmentRespectingNewGallery(
		int $attachmentId,
		int $syncingUnitId,
		array $newGalleryForSyncingUnit
	): int {
		$slug = UnitPostType::getSlug();
		$q    = new \WP_Query(
			[
				'post_type'      => $slug,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		$n = 0;
		foreach ($q->posts as $pid) {
			$pid = (int) $pid;
			$uses = false;
			if ((int) \get_post_thumbnail_id($pid) === $attachmentId) {
				$uses = true;
			}
			if ($pid === $syncingUnitId) {
				foreach ($newGalleryForSyncingUnit as $gid) {
					if ( (int) $gid === $attachmentId ) {
						$uses = true;
						break;
					}
				}
			} else {
				$g = (string) \get_post_meta($pid, 'bec_core_gallery', true);
				if ($g !== '') {
					$ids = \json_decode($g, true);
					if (is_array($ids)) {
						foreach ($ids as $v) {
							if (is_numeric($v) && (int) $v === $attachmentId) {
								$uses = true;
								break;
							}
						}
					}
				}
			}
			if ($uses) {
				++$n;
			}
		}

		return $n;
	}

	private static function reconcileExistingAttachment(int $attachmentId, int $unitId, string $key, string $url): int
	{
		\update_post_meta($attachmentId, self::GALLERY_IMAGE_KEY_META, $key);
		\update_post_meta($attachmentId, self::GALLERY_UNIT_ID_META, (string) $unitId);
		\update_post_meta($attachmentId, self::SOURCE_URL_META, $url);
		self::ensureFileHashMeta($attachmentId);

		return $attachmentId;
	}

	private static function findByUnitAndFileHash(int $unitId, string $hash, string $currentKey): ?int
	{
		if ($hash === '') {
			return null;
		}
		$q = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'meta_query'             => [
					[
						'key'   => self::GALLERY_UNIT_ID_META,
						'value' => (string) $unitId,
					],
					[
						'key'   => self::GALLERY_FILE_HASH_META,
						'value' => $hash,
					],
				],
			]
		);
		foreach ($q->posts as $id) {
			$id = (int) $id;
			$k  = (string) \get_post_meta($id, self::GALLERY_IMAGE_KEY_META, true);
			if ($k === '' || $k === $currentKey) {
				return $id;
			}
		}

		return null;
	}

	private static function ensureFileHashMeta(int $attachmentId): void
	{
		$existing = (string) \get_post_meta($attachmentId, self::GALLERY_FILE_HASH_META, true);
		if ($existing !== '') {
			return;
		}
		$file = \get_attached_file($attachmentId);
		if (! is_string($file) || $file === '' || ! is_readable($file)) {
			return;
		}
		$h = @\hash_file('sha256', $file) ?: '';
		if ($h !== '') {
			\update_post_meta($attachmentId, self::GALLERY_FILE_HASH_META, $h);
		}
	}

	/**
	 * @param list<int> $oldIds
	 */
	private static function adoptOrCloneFromPreviousGallery(
		int $unitId,
		string $key,
		string $url,
		array $oldIds
	): ?int
	{
		foreach ($oldIds as $aid) {
			$aid = (int) $aid;
			if ($aid < 1) {
				continue;
			}
			$u  = \esc_url_raw(\trim((string) \get_post_meta($aid, self::SOURCE_URL_META, true)));
			$st = (int) \get_post_meta($aid, self::GALLERY_UNIT_ID_META, true);
			if ($u !== $url) {
				continue;
			}
			$kmeta = (string) \get_post_meta($aid, self::GALLERY_IMAGE_KEY_META, true);
			if ( $kmeta !== '' && $kmeta !== $key ) {
				continue;
			}
			if ( $st !== 0 && $st !== $unitId ) {
				return self::duplicateAttachmentForUnit($aid, $unitId, $key, $url, $oldIds);
			}
			\update_post_meta($aid, self::GALLERY_IMAGE_KEY_META, $key);
			\update_post_meta($aid, self::GALLERY_UNIT_ID_META, (string) $unitId);
			\update_post_meta($aid, self::SOURCE_URL_META, $url);
			return $aid;
		}

		return null;
	}

	/**
	 * @param list<int> $oldIds
	 */
	private static function duplicateAttachmentForUnit(
		int $sourceId,
		int $unitId,
		string $key,
		string $url,
		array $oldIds
	): ?int
	{
		self::loadDependencies();
		$file = \get_attached_file($sourceId);
		if (! is_string($file) || $file === '' || ! is_readable($file)) {
			return null;
		}
		$tmp = \wp_tempnam('bec-gal-copy-');
		if ( $tmp === false || ! @\copy($file, $tmp) || ! is_readable($tmp) ) {
			@\unlink((string) $tmp);

			return null;
		}
		$ext  = self::extensionFromPathOrUrl($file, $url);
		$uniq = \substr( \hash( 'sha256', $key . \wp_generate_password( 8, false, false ) ), 0, 12 );
		$base = \sanitize_file_name( 'bec-dup-' . $uniq . $ext );
		$arr  = [ 'name' => $base, 'tmp_name' => $tmp ];
		$aid  = \media_handle_sideload($arr, $unitId);
		@\unlink($tmp);
		if (\is_wp_error($aid)) {
			return null;
		}
		$aid = (int) $aid;
		\update_post_meta($aid, self::SOURCE_URL_META, $url);
		\update_post_meta($aid, self::GALLERY_IMAGE_KEY_META, $key);
		\update_post_meta($aid, self::GALLERY_UNIT_ID_META, (string) $unitId);
		$h = @\hash_file('sha256', (string) \get_attached_file($aid) ) ?: '';
		if ($h !== '') {
			\update_post_meta($aid, self::GALLERY_FILE_HASH_META, $h);
		}
		unset($oldIds);

		return $aid;
	}

	private static function extensionFromPathOrUrl(string $pathOrTmp, string $url): string
	{
		$path = (string) \parse_url($url, PHP_URL_PATH);
		$base = $path !== '' ? \basename($path) : 'image.jpg';
		$ext2 = \strtolower(\pathinfo($base, \PATHINFO_EXTENSION));
		$fromUrl = self::validImageExtension($ext2);
		if ($fromUrl !== null) {
			return $fromUrl;
		}

		$ext = \strtolower(\pathinfo($pathOrTmp, \PATHINFO_EXTENSION));
		$fromPath = self::validImageExtension($ext);
		if ($fromPath !== null) {
			return $fromPath;
		}

		$fromMime = self::extensionFromImageMime($pathOrTmp);
		if ($fromMime !== null) {
			return $fromMime;
		}

		return '.jpg';
	}

	private static function extensionFromImageMime(string $path): ?string
	{
		if (! \is_readable($path)) {
			return null;
		}

		$mime = '';
		if (\function_exists('wp_get_image_mime')) {
			$detected = \wp_get_image_mime($path);
			$mime     = \is_string($detected) ? $detected : '';
		}

		if ($mime === '' && \function_exists('mime_content_type')) {
			$detected = @\mime_content_type($path);
			$mime     = \is_string($detected) ? $detected : '';
		}

		$map = [
			'image/jpeg' => '.jpg',
			'image/png'  => '.png',
			'image/gif'  => '.gif',
			'image/webp' => '.webp',
			'image/avif' => '.avif',
			'image/heic' => '.heic',
			'image/heif' => '.heif',
			'image/bmp'  => '.bmp',
			'image/tiff' => '.tif',
			'image/x-icon' => '.ico',
		];

		return $map[ $mime ] ?? null;
	}

	private static function validImageExtension(string $ext): ?string
	{
		$ext = \strtolower(\ltrim($ext, '.'));
		if (
			$ext === ''
			|| \strlen($ext) > 5
			|| \preg_match('/^[a-z0-9]+$/', $ext) !== 1
		) {
			return null;
		}

		$allowed = [
			'jpg'  => true,
			'jpeg' => true,
			'jpe'  => true,
			'png'  => true,
			'gif'  => true,
			'webp' => true,
			'avif' => true,
			'heic' => true,
			'heif' => true,
			'bmp'  => true,
			'tif'  => true,
			'tiff' => true,
			'ico'  => true,
		];

		return isset($allowed[ $ext ]) ? '.' . $ext : null;
	}

	private static function isHttpUrl(string $url): bool
	{
		return \str_starts_with($url, 'http://') || \str_starts_with($url, 'https://');
	}

	/**
	 * @param list<string> $urls
	 * @return array<string, string>
	 */
	private static function downloadUrlsToTempFiles(array $urls, int $parentPostId): array
	{
		if ($urls === []) {
			return [];
		}

		$concurrency = (int) \apply_filters('bec_gallery_download_concurrency', 8, $parentPostId, $urls);
		if ($concurrency < 1) {
			$concurrency = 1;
		}
		if ($concurrency > 32) {
			$concurrency = 32;
		}

		if (\extension_loaded('curl') && \function_exists('curl_multi_init')) {
			return self::downloadUrlsCurlMulti($urls, $concurrency);
		}

		return self::downloadUrlsSequential($urls);
	}

	/**
	 * @param list<string> $urls
	 * @return array<string, string>
	 */
	private static function downloadUrlsSequential(array $urls): array
	{
		self::loadDependencies();

		$out = [];
		foreach ($urls as $url) {
			$tmp = \download_url($url);
			if (\is_wp_error($tmp)) {
				continue;
			}
			if (is_string($tmp) && $tmp !== '' && is_readable($tmp) && filesize($tmp) > 0) {
				$out[ $url ] = $tmp;
			} else {
				@\unlink((string) $tmp);
			}
		}

		return $out;
	}

	/**
	 * @param list<string> $urls
	 * @return array<string, string>
	 */
	private static function downloadUrlsCurlMulti(array $urls, int $concurrency): array
	{
		$out    = [];
		$chunks = \array_chunk($urls, $concurrency);

		foreach ($chunks as $chunk) {
			$handles = [];

			foreach ($chunk as $url) {
				$tmp = \wp_tempnam('bec-gal-');
				if ($tmp === false) {
					continue;
				}
				$fp = @\fopen($tmp, 'wb');
				if ($fp === false) {
					@\unlink($tmp);
					continue;
				}

				$ch = \curl_init($url);
				if ($ch === false) {
					\fclose($fp);
					@\unlink($tmp);
					continue;
				}

				\curl_setopt_array(
					$ch,
					[
						\CURLOPT_FILE            => $fp,
						\CURLOPT_FOLLOWLOCATION  => true,
						\CURLOPT_MAXREDIRS       => 5,
						\CURLOPT_TIMEOUT         => 120,
						\CURLOPT_CONNECTTIMEOUT  => 15,
						\CURLOPT_SSL_VERIFYPEER  => true,
						\CURLOPT_SSL_VERIFYHOST  => 2,
						\CURLOPT_USERAGENT       => self::curlUserAgent(),
						\CURLOPT_PROTOCOLS       => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
						\CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
					]
				);

				$handles[] = [
					'ch'  => $ch,
					'fp'  => $fp,
					'tmp' => $tmp,
					'url' => $url,
				];
			}

			if ($handles === []) {
				continue;
			}

			$mh = \curl_multi_init();
			if ($mh === false) {
				foreach ($handles as $h) {
					\fclose($h['fp']);
					@\unlink($h['tmp']);
					\curl_close($h['ch']);
				}

				continue;
			}

			foreach ($handles as $h) {
				\curl_multi_add_handle($mh, $h['ch']);
			}

			do {
				$status = \curl_multi_exec($mh, $active);
				if ($active) {
					\curl_multi_select($mh, 0.5);
				}
			} while ($active && $status === \CURLM_OK);

			foreach ($handles as $h) {
				\curl_multi_remove_handle($mh, $h['ch']);
				\fclose($h['fp']);
				$code = (int) \curl_getinfo($h['ch'], \CURLINFO_HTTP_CODE);
				\curl_close($h['ch']);
				$url = $h['url'];
				$tmp = $h['tmp'];

				if ($code >= 200 && $code < 300 && is_readable($tmp) && filesize($tmp) > 0) {
					$out[ $url ] = $tmp;
				} else {
					@\unlink($tmp);
				}
			}

			\curl_multi_close($mh);
		}

		return $out;
	}

	private static function curlUserAgent(): string
	{
		$ver = \function_exists('get_bloginfo') ? (string) \get_bloginfo('version') : '';

		return 'WordPress/' . $ver . '; ' . \home_url('/');
	}

	private static function loadDependencies(): void
	{
		if (! \function_exists('download_url')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (! \function_exists('media_handle_sideload')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if (! \function_exists('wp_read_image_metadata')) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}