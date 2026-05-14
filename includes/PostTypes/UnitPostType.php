<?php

declare(strict_types=1);

namespace BookingEngineConnector\PostTypes;

use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Sync\JsonExtensionFlags;
use BookingEngineConnector\Sync\SyncPayloadEncoder;
use DateTimeImmutable;
use DateTimeInterface;
use WP_Query;

/**
 * Registers the Unit custom post type and related meta, REST fields, and admin list UI.
 */
final class UnitPostType
{
	/**
	 * Internal post type name (stored in the database). Do not change once content exists.
	 */
	public const POST_TYPE = 'bec_unit';

	public const OPTION_PERMALINK_SLUG = 'bec_unit_permalink_slug';

	/**
	 * Whether the public units post type registers an archive URL; value is the default for the `bec_unit_has_archive` filter.
	 */
	public const OPTION_HAS_ARCHIVE = 'bec_unit_has_archive';

	public const OPTION_NEEDS_REWRITE_FLUSH = 'bec_needs_rewrite_flush';

	private static string $permalinkSlug = 'bec_unit';

	public static function register(): void
	{
		add_action('init', [self::class, 'onInit'], 5);
	}

	/**
	 * Registered post type key (always {@see UnitPostType::POST_TYPE}).
	 */
	public static function getSlug(): string
	{
		return self::POST_TYPE;
	}

	/**
	 * Public URL segment for single units and the archive (Permalink Settings / filters).
	 */
	public static function getPermalinkSlug(): string
	{
		return self::$permalinkSlug;
	}

	public static function onInit(): void
	{
		self::$permalinkSlug = self::resolvePermalinkSlug();

		$hasArchiveDefault = (bool) get_option(self::OPTION_HAS_ARCHIVE, false);

		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => self::labels(),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-building',
				'menu_position'       => 6,
				'supports'            => ['title', 'editor', 'thumbnail'],
				'has_archive'         => (bool) apply_filters('bec_unit_has_archive', $hasArchiveDefault),
				'rewrite'             => ['slug' => self::$permalinkSlug],
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			]
		);

		if (get_option(self::OPTION_NEEDS_REWRITE_FLUSH)) {
			delete_option(self::OPTION_NEEDS_REWRITE_FLUSH);
			flush_rewrite_rules(false);
		}

		self::registerMeta();
		self::registerAdminListHooks();
		self::registerAdminEditorHooks();
	}

	/**
	 * @return array<string, string>
	 */
	private static function labels(): array
	{
		return [
			'name'                  => _x('Units', 'post type general name', 'booking-engine-connector'),
			'singular_name'         => _x('Unit', 'post type singular name', 'booking-engine-connector'),
			'menu_name'             => _x('Units', 'Admin Menu text', 'booking-engine-connector'),
			'name_admin_bar'        => _x('Unit', 'Add New on Toolbar', 'booking-engine-connector'),
			'add_new'               => __('Add New', 'booking-engine-connector'),
			'add_new_item'          => __('Add New Unit', 'booking-engine-connector'),
			'new_item'              => __('New Unit', 'booking-engine-connector'),
			'edit_item'             => __('Edit Unit', 'booking-engine-connector'),
			'view_item'             => __('View Unit', 'booking-engine-connector'),
			'all_items'             => __('Units', 'booking-engine-connector'),
			'search_items'          => __('Search Units', 'booking-engine-connector'),
			'parent_item_colon'     => __('Parent Units:', 'booking-engine-connector'),
			'not_found'             => __('No units found.', 'booking-engine-connector'),
			'not_found_in_trash'    => __('No units found in Trash.', 'booking-engine-connector'),
			'featured_image'        => _x('Unit featured image', 'Overrides “Featured Image”', 'booking-engine-connector'),
			'set_featured_image'    => _x('Set unit image', 'Overrides “Set featured image”', 'booking-engine-connector'),
			'remove_featured_image' => _x('Remove unit image', 'Overrides “Remove featured image”', 'booking-engine-connector'),
			'use_featured_image'    => _x('Use as unit image', 'Overrides “Use as featured image”', 'booking-engine-connector'),
			'archives'              => _x('Unit archives', 'Post type archive label in nav menus', 'booking-engine-connector'),
			'insert_into_item'      => _x('Insert into unit', 'Overrides “Insert into post”', 'booking-engine-connector'),
			'uploaded_to_this_item' => _x('Uploaded to this unit', 'Overrides “Uploaded to this post”', 'booking-engine-connector'),
			'filter_items_list'     => _x('Filter units list', 'Screen reader text for filter links', 'booking-engine-connector'),
			'items_list_navigation' => _x('Units list navigation', 'Screen reader text for pagination', 'booking-engine-connector'),
			'items_list'            => _x('Units list', 'Screen reader text for items list', 'booking-engine-connector'),
		];
	}

	/**
	 * Persists a one-time rewrite flush on the next {@see init} (e.g. after plugin activation).
	 */
	public static function scheduleRewriteFlush(): void
	{
		update_option(self::OPTION_NEEDS_REWRITE_FLUSH, '1', false);
	}

	private static function resolvePermalinkSlug(): string
	{
		$stored = get_option(self::OPTION_PERMALINK_SLUG, '');
		if (! is_string($stored)) {
			$stored = '';
		}
		$stored = trim($stored);
		$candidate = $stored === '' ? 'bec_unit' : sanitize_title($stored);
		if ($candidate === '') {
			$candidate = 'bec_unit';
		}
		$filtered = (string) apply_filters('bec_unit_post_type_slug', $candidate);
		$filtered = (string) apply_filters('bec_unit_rewrite_slug', $filtered);
		$filtered = sanitize_title($filtered);
		if ($filtered === '') {
			$filtered = 'bec_unit';
		}

		return $filtered;
	}

	private static function registerMeta(): void
	{
		$auth = static function ($allowed, $meta_key, $post_id) {
			return current_user_can('edit_post', (int) $post_id);
		};

		register_post_meta(
			self::POST_TYPE,
			'bec_external_id',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'default'           => '',
				'schema'            => [
					'type' => 'string',
				],
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'bec_provider_slug',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
				'default'           => '',
				'schema'            => [
					'type' => 'string',
				],
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'bec_last_sync_at',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => [self::class, 'sanitizeLastSyncAt'],
				'auth_callback'     => $auth,
				'default'           => '',
				'schema'            => [
					'type'        => 'string',
					'description' => 'ISO 8601 datetime or empty.',
				],
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'bec_sync_enabled',
			[
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => [self::class, 'sanitizeSyncEnabled'],
				'auth_callback'     => $auth,
				'default'           => true,
				'schema'            => [
					'type' => 'boolean',
				],
			]
		);

		register_post_meta(
			self::POST_TYPE,
			'bec_sync_payload',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => [self::class, 'sanitizeSyncPayload'],
				'auth_callback'     => $auth,
				'default'           => '',
				'schema'            => [
					'type'        => 'string',
					'description' => 'JSON snapshot of the last synced remote unit row.',
				],
			]
		);
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitizeLastSyncAt($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		if (! is_string($value)) {
			return '';
		}

		$value = trim($value);
		if ($value === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
		} catch (\Exception $e) {
			return '';
		}
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitizeSyncEnabled($value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_string($value)) {
			return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
		}

		if (is_int($value) || is_float($value)) {
			return (int) $value !== 0;
		}

		return (bool) $value;
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitizeSyncPayload($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		if (! is_string($value)) {
			return '';
		}

		$value = trim($value);
		if ($value === '') {
			return '';
		}

		$decoded = SyncPayloadEncoder::decodeStored($value);

		if ($decoded === null) {
			// Do not wipe payloads that fail re-parse (depth, edge cases); keep as stored.
			return $value;
		}

		$encoded = wp_json_encode($decoded, SyncPayloadEncoder::metaEncodeFlags());

		return $encoded !== false ? $encoded : $value;
	}

	private static function registerAdminListHooks(): void
	{
		add_filter('manage_' . self::POST_TYPE . '_posts_columns', [self::class, 'filterPostColumns']);
		add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [self::class, 'renderPostColumn'], 10, 2);
		add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [self::class, 'filterSortableColumns']);
		add_action('pre_get_posts', [self::class, 'sortAdminListByMeta']);
	}

	private static function registerAdminEditorHooks(): void
	{
		add_action('add_meta_boxes', [self::class, 'addSyncInspectorMetaBox']);
	}

	public static function addSyncInspectorMetaBox(): void
	{
		add_meta_box(
			'bec_unit_sync_inspector',
			__('Booking engine — synced data', 'booking-engine-connector'),
			[self::class, 'renderSyncInspectorMetaBox'],
			self::POST_TYPE,
			'normal',
			'default'
		);
	}

	public static function renderSyncInspectorMetaBox(\WP_Post $post): void
	{
		if (! current_user_can('edit_post', $post->ID)) {
			return;
		}

		$lastSyncRaw = (string) get_post_meta($post->ID, 'bec_last_sync_at', true);
		$lastSync    = $lastSyncRaw;
		if ($lastSyncRaw !== '') {
			$ts = strtotime($lastSyncRaw);
			if ($ts !== false) {
				$lastSync = wp_date(
					get_option('date_format') . ' ' . get_option('time_format'),
					$ts
				);
			}
		}

		$rows = [
			'bec_external_id'   => __('External ID', 'booking-engine-connector'),
			'bec_provider_slug' => __('Provider', 'booking-engine-connector'),
			'bec_last_sync_at'  => __('Last sync (stored)', 'booking-engine-connector'),
			'bec_sync_enabled'  => __('Sync enabled', 'booking-engine-connector'),
		];

		echo '<p class="description">' . esc_html__(
			'Read-only fields from the booking engine sync. Use the JSON below to see every key returned for this unit (e.g. for templates and shortcodes).',
			'booking-engine-connector'
		) . '</p>';

		echo '<table class="widefat striped" style="margin-top:0.5em;"><tbody>';
		foreach ($rows as $metaKey => $label) {
			$raw = get_post_meta($post->ID, $metaKey, true);
			if ($metaKey === 'bec_last_sync_at') {
				$display = $lastSync !== '' ? $lastSync : '—';
			} elseif ($metaKey === 'bec_sync_enabled') {
				$display = $raw ? __('Yes', 'booking-engine-connector') : __('No', 'booking-engine-connector');
			} else {
				$display = $raw !== '' && $raw !== null ? (string) $raw : '—';
			}

			echo '<tr><th scope="row" style="width:12rem;">' . esc_html($label) . '</th><td><code>' . esc_html($display) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		self::renderRemoteRowJsonPanel($post);
	}

	/**
	 * Last synced normalised remote row (`bec_sync_payload`) as pretty-printed JSON for debugging.
	 *
	 * Also rendered under **Unit — core fields (canonical)** so it stays visible without scrolling past long forms.
	 */
	public static function renderRemoteRowJsonPanel(\WP_Post $post): void
	{
		if (! current_user_can('edit_post', $post->ID)) {
			return;
		}

		$payload = (string) get_post_meta($post->ID, 'bec_sync_payload', true);
		echo '<h4 style="margin:1.25em 0 0.5em;">' . esc_html__('Remote row (JSON)', 'booking-engine-connector') . '</h4>';

		if ($payload === '') {
			echo '<p>' . esc_html__(
				'No payload stored yet. After the next successful sync, the full remote row will appear here.',
				'booking-engine-connector'
			) . '</p>';

			return;
		}

		$decoded = SyncPayloadEncoder::decodeStored($payload);

		if (! is_array($decoded)) {
			echo '<pre style="max-height:24rem;overflow:auto;padding:12px;background:#f6f7f7;border:1px solid #c3c4c7;">';
			echo esc_html($payload);
			echo '</pre>';

			return;
		}

		$pretty = wp_json_encode(
			$decoded,
			JsonExtensionFlags::prettyPrint() | SyncPayloadEncoder::metaEncodeFlags(false)
		);
		if ($pretty === false) {
			echo '<pre style="max-height:24rem;overflow:auto;padding:12px;background:#f6f7f7;border:1px solid #c3c4c7;">';
			echo esc_html($payload);
			echo '</pre>';

			return;
		}

		echo '<pre style="max-height:24rem;overflow:auto;padding:12px;background:#f6f7f7;border:1px solid #c3c4c7;font-size:12px;line-height:1.45;">';
		echo esc_html($pretty);
		echo '</pre>';
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function filterPostColumns(array $columns): array
	{
		$out = [];
		foreach ($columns as $key => $label) {
			$out[ $key ] = $label;
			if ($key === 'title') {
				$out['bec_external_id']   = __('External ID', 'booking-engine-connector');
				$out['bec_provider_slug'] = __('Provider', 'booking-engine-connector');
				$out['bec_last_sync_at']  = __('Last sync', 'booking-engine-connector');

				if (ProviderRegistry::getActiveSlug() === 'kross') {
					/* translators: Kross API field indicating which booking engine slugs enable this unit. */
					$out['bec_kross_be_enabled'] = __('Kross BE enabled', 'booking-engine-connector');
				}
			}
		}

		return $out;
	}

	public static function renderPostColumn(string $column, int $post_id): void
	{
		switch ($column) {
			case 'bec_external_id':
				echo esc_html((string) get_post_meta($post_id, 'bec_external_id', true));
				break;
			case 'bec_provider_slug':
				echo esc_html((string) get_post_meta($post_id, 'bec_provider_slug', true));
				break;
			case 'bec_last_sync_at':
				echo esc_html((string) get_post_meta($post_id, 'bec_last_sync_at', true));
				break;
			case 'bec_kross_be_enabled':
				if (ProviderRegistry::getActiveSlug() !== 'kross') {
					break;
				}
				echo self::formatKrossBeEnabledListCell($post_id);
				break;
		}
	}

	private static function formatKrossBeEnabledListCell(int $postId): void
	{
		$payload = (string) get_post_meta($postId, 'bec_sync_payload', true);

		if ($payload === '') {
			echo '<span aria-hidden="true">—</span>';

			return;
		}

		$slugs = SyncPayloadEncoder::readBeEnabledSlugsFromStoredPayload($payload);

		if ($slugs === []) {
			echo '<span aria-hidden="true">—</span>';

			return;
		}

		$first = true;

		foreach ($slugs as $slug) {
			if (! $first) {
				echo ', ';
			}

			$first = false;
			echo '<code>' . esc_html($slug) . '</code>';
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function filterSortableColumns(array $columns): array
	{
		$columns['bec_external_id']   = 'bec_external_id';
		$columns['bec_provider_slug'] = 'bec_provider_slug';
		$columns['bec_last_sync_at']  = 'bec_last_sync_at';

		return $columns;
	}

	public static function sortAdminListByMeta(WP_Query $query): void
	{
		if (! is_admin() || ! $query->is_main_query()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen === null || $screen->id !== 'edit-' . self::POST_TYPE) {
			return;
		}

		$orderby = $query->get('orderby');
		if (! is_string($orderby)) {
			return;
		}

		if (! in_array($orderby, ['bec_external_id', 'bec_provider_slug', 'bec_last_sync_at'], true)) {
			return;
		}

		$query->set('meta_key', $orderby);
		$query->set('orderby', 'meta_value');
	}
}
