<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\Media\RemoteGalleryImporter;
use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Units\AmenityItem;
use BookingEngineConnector\Units\CoreUnitMetaKeys;
use BookingEngineConnector\Units\CoreUnitSemantic;

/**
 * Canonical unit fields (`bec_core_*`): registration, sync application, admin UI.
 *
 * Filters: {@see bec_sync_apply_core_unit_fields}, {@see bec_core_unit_fields}, {@see bec_core_unit_locale},
 * {@see bec_provider_amenities_from_row}, {@see bec_sync_import_gallery_images}, {@see bec_core_unit_gallery_before_save},
 * {@see bec_core_unit_gallery_remote_urls}, {@see bec_sync_gallery_ignore_hash} (3rd param: order-key hash), {@see bec_gallery_download_concurrency}.
 */
final class CoreUnitFieldRegistry
{
	private static bool $postMetaRegistered = false;

	public static function register(): void
	{
		add_action('init', [self::class, 'onInit'], 10);
		add_action('add_meta_boxes', [self::class, 'addMetaBox']);
		add_action('save_post', [self::class, 'onSavePost'], 10, 2);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueUnitGalleryAssets']);
	}

	public static function onInit(): void
	{
		self::registerPostMeta();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function applyFromProviderRow(int $postId, string $providerSlug, array $row, bool $deferGallery = false): void
	{
		if (! \apply_filters('bec_sync_apply_core_unit_fields', true, $postId, $providerSlug, $row)) {
			return;
		}

		$provider = ProviderRegistry::getProvider($providerSlug);
		$data     = $provider->extractCoreUnitFields($row);
		$data     = \apply_filters('bec_core_unit_fields', $data, $providerSlug, $row);

		if (! is_array($data)) {
			return;
		}

		foreach (CoreUnitSemantic::all() as $sem) {
			if (! array_key_exists($sem, $data)) {
				continue;
			}

			$defs = CoreUnitMetaKeys::definitions();
			if (! isset($defs[ $sem ])) {
				continue;
			}

			$conf    = $defs[ $sem ];
			$metaKey = $conf['meta_key'];
			$type    = $conf['type'];
			$value   = $data[ $sem ];
			if ($sem === CoreUnitSemantic::GALLERY) {
				if ($deferGallery) {
					$value = self::preserveExistingGalleryMetaForDeferredImport($postId, $value);
				} else {
					$value = self::resolveGalleryForStorage($postId, $value);
				}
			}
			// Pass raw values: registered meta sanitize runs once inside update_post_meta.
			// Pre-encoding JSON then updating corrupts payloads when update_metadata wp_unslash() runs
			// before sanitize (breaks \" and \u0027 sequences).
			update_post_meta($postId, $metaKey, $value);
		}
	}

	/**
	 * @param mixed $incomingGallery Value from the provider pipeline (may be a remote payload). Existing attachment IDs are kept until a deferred import finishes.
	 */
	private static function preserveExistingGalleryMetaForDeferredImport(int $postId, $incomingGallery)
	{
		unset($incomingGallery);

		$metaKey = CoreUnitMetaKeys::definitions()[ CoreUnitSemantic::GALLERY ]['meta_key'];
		$raw     = \get_post_meta($postId, $metaKey, true);

		return self::decodeGalleryIdListFromMeta($raw);
	}

	/**
	 * @return list<int>
	 */
	private static function decodeGalleryIdListFromMeta($raw): array
	{
		if (\is_string($raw) && $raw !== '') {
			$decoded = \json_decode($raw, true);
		} elseif (\is_array($raw)) {
			$decoded = $raw;
		} else {
			return [];
		}
		if (! \is_array($decoded) || $decoded === []) {
			return [];
		}
		$ids = [];
		foreach ($decoded as $v) {
			if (\is_numeric($v)) {
				$n = (int) $v;
				if ($n > 0) {
					$ids[] = $n;
				}
			}
		}

		return $ids;
	}

	private static function registerPostMeta(): void
	{
		if (self::$postMetaRegistered) {
			return;
		}

		$auth = static function ($allowed, $meta_key, $post_id) {
			return current_user_can('edit_post', (int) $post_id);
		};

		$cpt = UnitPostType::getSlug();

		foreach (CoreUnitMetaKeys::definitions() as $semantic => $conf) {
			$type    = $conf['type'];
			$metaKey = $conf['meta_key'];
			$wpType  = self::wpMetaType($type);

			register_post_meta(
				$cpt,
				$metaKey,
				[
					'type'              => $wpType,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static function ($value) use ($type) {
						return CoreUnitFieldRegistry::sanitizeValue($type, $value);
					},
					'auth_callback'     => $auth,
					'default'         => self::defaultForType($type),
				]
			);
		}

		self::$postMetaRegistered = true;
	}

	private static function wpMetaType(string $type): string
	{
		return 'string';
	}

	/**
	 * @return string|bool
	 */
	private static function defaultForType(string $type)
	{
		return '';
	}

	/**
	 * @param mixed $value Remote payload (`urls` + optional `featured_url`), list of URL strings, or attachment IDs.
	 * @return list<int>|string|mixed
	 */
	private static function resolveGalleryForStorage(int $postId, $value)
	{
		/** @var mixed $value */
		$value = \apply_filters('bec_core_unit_gallery_before_save', $value, $postId);

		if ($value === null || $value === '') {
			return '';
		}

		if (! is_array($value)) {
			return $value;
		}

		if (isset($value['urls']) && is_array($value['urls'])) {
			$flat = [];
			if (isset($value['items']) && is_array($value['items']) && $value['items'] !== []) {
				foreach ($value['items'] as $row) {
					if (is_array($row) && isset($row['url']) && is_string($row['url']) && self::looksLikeHttpUrl($row['url'])) {
						$flat[] = $row['url'];
					}
				}
			}
			if ($flat === []) {
				foreach ($value['urls'] as $u) {
					if (is_string($u) && self::looksLikeHttpUrl($u)) {
						$flat[] = $u;
						continue;
					}
					if (is_array($u) && isset($u['url']) && is_string($u['url'])) {
						$flat[] = $u['url'];
					}
				}
			}
			$flat = \apply_filters('bec_core_unit_gallery_remote_urls', $flat, $postId, $value);

			if (isset($value['items']) && is_array($value['items']) && $value['items'] !== [] && \count($flat) === \count($value['items'])) {
				$i = 0;
				foreach ($value['items'] as $k => $row) {
					if (is_array($row) && isset($row['url'])) {
						$value['items'][ $k ]['url'] = $flat[ $i ];
					}
					++$i;
				}
			}
			$value['urls'] = $flat;

			return RemoteGalleryImporter::importFromRemotePayload($postId, $value);
		}

		if ($value === []) {
			return [];
		}

		$allUrls = true;
		foreach ($value as $x) {
			if (! is_string($x) || ! self::looksLikeHttpUrl($x)) {
				$allUrls = false;
				break;
			}
		}
		if ($allUrls) {
			return RemoteGalleryImporter::importUrls($postId, array_values($value));
		}

		$allIds = true;
		foreach ($value as $x) {
			if (is_int($x)) {
				continue;
			}
			if (is_string($x) && $x !== '' && is_numeric($x)) {
				continue;
			}
			$allIds = false;
			break;
		}
		if ($allIds) {
			$out = [];
			foreach ($value as $x) {
				$out[] = (int) $x;
			}

			return $out;
		}

		return $value;
	}

	private static function looksLikeHttpUrl(string $s): bool
	{
		$s = \trim($s);

		return $s !== '' && ( \str_starts_with($s, 'http://') || \str_starts_with($s, 'https://') );
	}

	/**
	 * @param 'string'|'textarea'|'number'|'bathrooms'|'amenities_json'|'gallery_json' $type
	 *
	 * @return mixed
	 */
	public static function sanitizeValue(string $type, $value)
	{
		switch ($type) {
			case 'string':
				if ($value === null) {
					return '';
				}
				if (is_bool($value)) {
					return $value ? '1' : '';
				}

				return sanitize_text_field((string) $value);

			case 'textarea':
				if ($value === null) {
					return '';
				}

				return sanitize_textarea_field((string) $value);

			case 'number':
				if ($value === null || $value === '') {
					return '';
				}
				if (is_numeric($value)) {
					$n = $value + 0;

					return is_float($n) ? (string) $n : (string) (int) $n;
				}

				return '';

			case 'bathrooms':
				if ($value === null || $value === '') {
					return '';
				}
				if (is_numeric($value)) {
					$n = $value + 0;

					return is_float($n) ? (string) $n : (string) (int) $n;
				}

				return sanitize_text_field((string) $value);

			case 'amenities_json':
				if ($value === null || $value === '') {
					return '';
				}
				if (is_array($value)) {
					$norm = AmenityItem::normalizeList($value);
					$enc  = wp_json_encode($norm, SyncPayloadEncoder::metaEncodeFlags(false));

					return $enc !== false ? $enc : '';
				}
				if (! is_string($value)) {
					return '';
				}
				$trim = trim($value);
				if ($trim === '') {
					return '';
				}
				$decoded = SyncPayloadEncoder::decodeMetaJson($trim);
				if (! is_array($decoded)) {
					return '';
				}
				$norm = AmenityItem::normalizeList($decoded);
				$enc  = wp_json_encode($norm, SyncPayloadEncoder::metaEncodeFlags(false));

				return $enc !== false ? $enc : '';

			case 'gallery_json':
				if ($value === null || $value === '') {
					return '';
				}
				if (is_array($value)) {
					$ids = [];
					foreach ($value as $v) {
						if (is_numeric($v)) {
							$n = (int) $v;
							if ($n > 0) {
								$ids[] = $n;
							}
						}
					}
					$enc = wp_json_encode(array_values(array_unique($ids)), SyncPayloadEncoder::metaEncodeFlags(false));

					return $enc !== false ? $enc : '';
				}
				if (! is_string($value)) {
					return '';
				}
				$trim = trim($value);
				if ($trim === '') {
					return '';
				}
				$decoded = SyncPayloadEncoder::decodeMetaJson($trim);
				if (! is_array($decoded)) {
					return '';
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
				$enc = wp_json_encode(array_values(array_unique($ids)), SyncPayloadEncoder::metaEncodeFlags(false));

				return $enc !== false ? $enc : '';

			default:
				return '';
		}
	}

	public static function addMetaBox(): void
	{
		add_meta_box(
			'bec_unit_core_fields',
			__('Unit — core fields (canonical)', 'booking-engine-connector'),
			[self::class, 'renderMetaBox'],
			UnitPostType::getSlug(),
			'normal',
			'high'
		);
	}

	public static function renderMetaBox(\WP_Post $post): void
	{
		if (! current_user_can('edit_post', $post->ID)) {
			return;
		}

		wp_nonce_field('bec_unit_core_fields_save', 'bec_unit_core_fields_nonce');

		echo '<p class="description">' . esc_html__(
			'Provider-independent fields used in themes and shortcodes. Sync overwrites these on each run unless disabled via filter.',
			'booking-engine-connector'
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach (CoreUnitMetaKeys::definitions() as $semantic => $conf) {
			$metaKey = $conf['meta_key'];
			$label   = $conf['label'];
			$type    = $conf['type'];
			$val     = get_post_meta($post->ID, $metaKey, true);

			echo '<tr><th scope="row"><label for="bec_core_' . esc_attr($metaKey) . '">' . esc_html($label) . '</label></th><td>';

			$name = 'bec_core_fields[' . $metaKey . ']';

			switch ($type) {
				case 'textarea':
					echo '<textarea class="large-text" rows="4" id="bec_core_' . esc_attr($metaKey) . '" name="' . esc_attr($name) . '">';
					echo esc_textarea(is_string($val) ? $val : '');
					echo '</textarea>';
					break;
				case 'amenities_json':
					$display = '';
					if (is_string($val) && $val !== '') {
						$display = $val;
					} elseif (is_array($val)) {
						$prettyFlags = JsonExtensionFlags::prettyPrint() | SyncPayloadEncoder::metaEncodeFlags(false);
						$enc         = wp_json_encode($val, $prettyFlags);
						$display     = $enc !== false ? $enc : '';
					} else {
						$dec = SyncPayloadEncoder::decodeMetaJson((string) $val);
						if (is_array($dec)) {
							$prettyFlags = JsonExtensionFlags::prettyPrint() | SyncPayloadEncoder::metaEncodeFlags(false);
							$enc         = wp_json_encode($dec, $prettyFlags);
							$display     = $enc !== false ? $enc : '';
						}
					}
					echo '<textarea class="large-text code" rows="8" id="bec_core_' . esc_attr($metaKey) . '" name="' . esc_attr($name) . '" spellcheck="false">';
					echo esc_textarea($display);
					echo '</textarea>';
					echo '<p class="description">' . esc_html__(
						'Array of items: key, labels per locale, optional icon. Override via bec_provider_amenities_from_row / bec_kross_amenities_from_raw.',
						'booking-engine-connector'
					) . '</p>';
					break;
				case 'gallery_json':
					self::renderGalleryField($post, $metaKey, $name, $val);
					break;
				case 'number':
				case 'bathrooms':
					echo '<input type="text" class="regular-text" id="bec_core_' . esc_attr($metaKey) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_scalar($val) ? (string) $val : '') . '" inputmode="decimal" />';
					if ($type === 'bathrooms') {
						echo '<p class="description">' . esc_html__(
							'Use decimals if needed (e.g. 1.5).',
							'booking-engine-connector'
						) . '</p>';
					}
					break;
				default:
					echo '<input type="text" class="large-text" id="bec_core_' . esc_attr($metaKey) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_scalar($val) ? (string) $val : '') . '" />';
			}

			echo '<p class="description"><code>' . esc_html($metaKey) . '</code> · <code>' . esc_html($semantic) . '</code></p>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		//UnitPostType::renderRemoteRowJsonPanel($post);
	}

	public static function enqueueUnitGalleryAssets(string $hook): void
	{
		if ($hook !== 'post.php' && $hook !== 'post-new.php') {
			return;
		}
		if (! function_exists('get_current_screen')) {
			return;
		}
		$screen = get_current_screen();
		if (! $screen || $screen->post_type !== UnitPostType::getSlug()) {
			return;
		}

		$postId = isset($_GET['post']) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only for frame context.

		wp_enqueue_media(['post' => $postId]);

		wp_enqueue_style(
			'bec-admin-unit-gallery',
			BEC_PLUGIN_URL . 'assets/admin-unit-gallery.css',
			[],
			BEC_VERSION
		);

		wp_enqueue_script(
			'bec-admin-unit-gallery',
			BEC_PLUGIN_URL . 'assets/admin-unit-gallery.js',
			['jquery', 'media-editor'],
			BEC_VERSION,
			true
		);

		wp_localize_script(
			'bec-admin-unit-gallery',
			'becUnitGallery',
			[
				'i18n' => [
					'frameTitle'   => __('Attachment details', 'booking-engine-connector'),
					'frameButton'  => __('Close', 'booking-engine-connector'),
				],
			]
		);
	}

	/**
	 * @param mixed $val Stored meta for bec_core_gallery.
	 */
	private static function renderGalleryField(\WP_Post $post, string $metaKey, string $name, $val): void
	{
		unset($post);

		$decodedIds = self::decodeGalleryIdListFromMeta($val);
		$normalized = self::sanitizeValue('gallery_json', $decodedIds);
		/** @var list<int> $idList */
		$idList = [];
		if (is_string($normalized) && $normalized !== '') {
			$fromJson = json_decode($normalized, true);
			if (is_array($fromJson)) {
				foreach ($fromJson as $n) {
					if (is_numeric($n)) {
						$i = (int) $n;
						if ($i > 0) {
							$idList[] = $i;
						}
					}
				}
			}
		}

		$fieldId = 'bec_core_' . $metaKey;

		echo '<div class="bec-unit-gallery-field" data-bec-unit-gallery-root>';

		echo '<input type="hidden" id="' . esc_attr($fieldId) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_string($normalized) ? $normalized : '') . '" autocomplete="off" />';

		if ($idList === []) {
			echo '<p class="description">' . esc_html__(
				'No images yet. Gallery images are filled by sync (attachment IDs in post meta).',
				'booking-engine-connector'
			) . '</p>';
		} else {
			echo '<ul class="bec-unit-gallery-grid">';
			foreach ($idList as $attachmentId) {
				self::renderGalleryThumbCell($attachmentId);
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	private static function renderGalleryThumbCell(int $attachmentId): void
	{
		$attachment = get_post($attachmentId);
		$isImage    = $attachment && wp_attachment_is_image($attachment);

		echo '<li class="bec-unit-gallery-item" data-attachment-id="' . esc_attr((string) $attachmentId) . '">';

		if (! $isImage) {
			echo '<div class="bec-unit-gallery-missing" title="' . esc_attr(sprintf(
				/* translators: %d: attachment post ID */
				__('Missing image attachment #%d', 'booking-engine-connector'),
				$attachmentId
			)) . '"><span class="bec-unit-gallery-missing-label">' . esc_html(sprintf(
				/* translators: %d: attachment post ID */
				__('Attachment %d', 'booking-engine-connector'),
				$attachmentId
			)) . '</span></div>';
			echo '</li>';

			return;
		}

		$thumb = wp_get_attachment_image(
			$attachmentId,
			'thumbnail',
			false,
			['class' => 'bec-unit-gallery-thumb-img']
		);

		echo '<button type="button" class="bec-unit-gallery-open">';
		echo $thumb;
		echo '<span class="screen-reader-text">' . esc_html__(
			'Open attachment details',
			'booking-engine-connector'
		) . '</span>';
		echo '</button>';
		echo '</li>';
	}

	/**
	 * @param int|\WP_Post $postId
	 */
	public static function onSavePost($postId, $post): void
	{
		if (! is_int($postId) || $postId <= 0) {
			return;
		}

		if (! $post instanceof \WP_Post) {
			return;
		}

		if ($post->post_type !== UnitPostType::getSlug()) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($postId)) {
			return;
		}

		if (! isset($_POST['bec_unit_core_fields_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['bec_unit_core_fields_nonce'])), 'bec_unit_core_fields_save')) {
			return;
		}

		if (! current_user_can('edit_post', $postId)) {
			return;
		}

		$postedRaw = isset($_POST['bec_core_fields']) && is_array($_POST['bec_core_fields']) ? $_POST['bec_core_fields'] : [];

		foreach (CoreUnitMetaKeys::definitions() as $semantic => $conf) {
			$metaKey = $conf['meta_key'];
			$type    = $conf['type'];

			if (! array_key_exists($metaKey, $postedRaw)) {
				continue;
			}

			$raw = $postedRaw[ $metaKey ];
			// Let update_metadata wp_unslash + registered sanitize run once (see applyFromProviderRow).
			update_post_meta($postId, $metaKey, $raw);
		}
	}
}
