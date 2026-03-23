<?php

declare(strict_types=1);

namespace BookingEngineConnector\Media;

/**
 * Downloads remote images into the Media Library and links them to a unit post.
 *
 * - Deduplicates by {@see self::SOURCE_URL_META}.
 * - Skips work when the ordered URL list hash matches {@see self::SOURCE_HASH_META} (no re-download).
 * - Downloads missing URLs in parallel batches (curl_multi) when available.
 */
final class RemoteGalleryImporter
{
	public const SOURCE_URL_META = '_bec_source_url';

	/** Post meta on the unit: sha256 of the canonical URL list (see {@see self::hashUrlList()}). */
	public const SOURCE_HASH_META = 'bec_sync_gallery_source_hash';

	/**
	 * @param list<string> $urls Ordered remote image URLs (https).
	 * @return list<int> Attachment IDs in the same order (skips failed downloads).
	 */
	public static function importUrls(int $parentPostId, array $urls): array
	{
		$urls = self::normalizeUrlList($urls);
		if ($urls === []) {
			\delete_post_meta($parentPostId, self::SOURCE_HASH_META);

			return [];
		}

		if (! \apply_filters('bec_sync_import_gallery_images', true, $parentPostId, $urls)) {
			return [];
		}

		$hash = self::hashUrlList($urls);
		if (! \apply_filters('bec_sync_gallery_ignore_hash', false, $parentPostId, $urls, $hash)) {
			$storedHash = (string) \get_post_meta($parentPostId, self::SOURCE_HASH_META, true);
			if ($storedHash !== '' && \hash_equals($storedHash, $hash)) {
				$reuse = self::reuseStoredGalleryIfComplete($parentPostId, $urls);
				if ($reuse !== null) {
					self::reparentAttachments($parentPostId, $reuse);

					return $reuse;
				}
			}
		}

		self::loadDependencies();

		$urlToId = self::findAttachmentIdsBySourceUrls($urls);
		$missing  = [];
		foreach ($urls as $u) {
			if (! isset($urlToId[ $u ])) {
				$missing[] = $u;
			}
		}

		$downloaded = self::downloadUrlsToTempFiles($missing, $parentPostId);

		foreach ($downloaded as $url => $tmpPath) {
			if (! is_string($tmpPath) || $tmpPath === '' || ! is_readable($tmpPath)) {
				continue;
			}

			$fileName = self::guessFileName($url);
			$fileArr  = [
				'name'     => $fileName,
				'tmp_name' => $tmpPath,
			];

			$attachmentId = \media_handle_sideload($fileArr, $parentPostId);
			if (\is_wp_error($attachmentId)) {
				@\unlink($tmpPath);
				continue;
			}

			\update_post_meta((int) $attachmentId, self::SOURCE_URL_META, $url);
			$urlToId[ $url ] = (int) $attachmentId;
		}

		$out = [];
		foreach ($urls as $u) {
			if (isset($urlToId[ $u ])) {
				$out[] = $urlToId[ $u ];
			}
		}

		if ($out !== []) {
			\update_post_meta($parentPostId, self::SOURCE_HASH_META, $hash);
		} else {
			\delete_post_meta($parentPostId, self::SOURCE_HASH_META);
		}

		return $out;
	}

	/**
	 * Stable hash for an ordered list of URLs (remote gallery fingerprint).
	 */
	public static function hashUrlList(array $urls): string
	{
		$urls = self::normalizeUrlList($urls);
		$json = \wp_json_encode($urls, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

		return $json !== false ? \hash('sha256', $json) : '';
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
	 * @param list<string> $urls
	 * @return list<int>|null
	 */
	private static function reuseStoredGalleryIfComplete(int $postId, array $urls): ?array
	{
		$raw = \get_post_meta($postId, 'bec_core_gallery', true);
		if (! is_string($raw) || $raw === '') {
			return null;
		}
		$decoded = \json_decode($raw, true);
		if (! is_array($decoded) || $decoded === []) {
			return null;
		}
		$ids = [];
		foreach ($decoded as $v) {
			if (! is_numeric($v)) {
				return null;
			}
			$n = (int) $v;
			if ($n <= 0) {
				return null;
			}
			$ids[] = $n;
		}
		if (\count($ids) !== \count($urls)) {
			return null;
		}

		foreach ($urls as $i => $u) {
			$aid = $ids[ $i ];
			if (\get_post_type($aid) !== 'attachment') {
				return null;
			}
			$attUrl = (string) \get_post_meta($aid, self::SOURCE_URL_META, true);
			$attUrl = \esc_url_raw(\trim($attUrl));
			if ($attUrl === '' || $attUrl !== $u) {
				return null;
			}
		}

		return $ids;
	}

	/**
	 * @param list<int> $ids
	 */
	private static function reparentAttachments(int $parentPostId, array $ids): void
	{
		foreach ($ids as $id) {
			if ($id <= 0) {
				continue;
			}
			\wp_update_post([
				'ID'          => $id,
				'post_parent' => $parentPostId,
			]);
		}
	}

	/**
	 * @param list<string> $urls
	 * @return array<string, int> Normalised URL => attachment ID
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
	 * @param list<string> $urls
	 * @return array<string, string> URL => temp file path
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
		$chunks = array_chunk($urls, $concurrency);

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

	private static function guessFileName(string $url): string
	{
		$path = (string) \parse_url($url, PHP_URL_PATH);
		$base = $path !== '' ? \basename($path) : 'image.jpg';
		$base = \sanitize_file_name($base);
		if ($base === '' || $base === '.' || $base === '..') {
			$base = 'image.jpg';
		}

		return $base;
	}

	private static function isHttpUrl(string $url): bool
	{
		return \str_starts_with($url, 'http://') || \str_starts_with($url, 'https://');
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
