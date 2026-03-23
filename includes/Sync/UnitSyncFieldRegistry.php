<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

use BookingEngineConnector\PostTypes\UnitPostType;
use BookingEngineConnector\Providers\ProviderRegistry;

/**
 * Registers mapped unit meta keys, applies values on sync, and renders editable fields in the unit editor.
 *
 * Filters: {@see bec_unit_sync_provider_slugs}, {@see bec_unit_sync_field_definitions}, {@see bec_sync_apply_mapped_unit_fields}.
 */
final class UnitSyncFieldRegistry
{
	/** @var array<string, list<UnitSyncFieldDefinition>>|null */
	private static ?array $definitionsByProvider = null;

	private static bool $postMetaRegistered = false;

	public static function register(): void
	{
		add_action('init', [self::class, 'onInit'], 11);
		add_action('add_meta_boxes', [self::class, 'addMetaBox']);
		add_action('save_post', [self::class, 'onSavePost'], 10, 2);
	}

	public static function onInit(): void
	{
		self::ensureDefinitionsLoaded();
		self::registerPostMetaForCollectedKeys();
	}

	/**
	 * Writes provider-mapped meta from a normalised remote row (overwrites on each sync).
	 *
	 * @param array<string, mixed> $row
	 */
	public static function applyFromRemoteRow(int $postId, string $providerSlug, array $row): void
	{
		self::ensureDefinitionsLoaded();

		if (! apply_filters('bec_sync_apply_mapped_unit_fields', true, $postId, $providerSlug, $row)) {
			return;
		}

		$defs = self::$definitionsByProvider[ $providerSlug ] ?? [];
		foreach ($defs as $def) {
			$extract = $def->extract;
			$value   = $extract( $row );
			$value   = self::sanitizeValue( $def->type, $value );
			update_post_meta( $postId, $def->metaKey, $value );
		}
	}

	private static function ensureDefinitionsLoaded(): void
	{
		if (self::$definitionsByProvider !== null) {
			return;
		}

		self::$definitionsByProvider = [];
		$slugs                       = apply_filters('bec_unit_sync_provider_slugs', ['kross']);

		foreach ($slugs as $slug) {
			$slug = sanitize_key( (string) $slug );
			if ($slug === '') {
				continue;
			}

			$provider = ProviderRegistry::getProvider( $slug );
			$defs     = $provider->getUnitSyncFieldDefinitions();
			/** @var list<UnitSyncFieldDefinition> $defs */
			$defs = apply_filters('bec_unit_sync_field_definitions', $defs, $slug);

			$out = [];
			foreach ($defs as $d) {
				if ($d instanceof UnitSyncFieldDefinition && $d->providerSlug === $slug) {
					$out[] = $d;
				}
			}
			self::$definitionsByProvider[ $slug ] = $out;
		}
	}

	private static function registerPostMetaForCollectedKeys(): void
	{
		if (self::$postMetaRegistered) {
			return;
		}

		self::ensureDefinitionsLoaded();

		$auth = static function ($allowed, $meta_key, $post_id) {
			return current_user_can('edit_post', (int) $post_id);
		};

		$typesByKey = [];
		foreach (self::$definitionsByProvider ?? [] as $defs) {
			foreach ($defs as $def) {
				if (! isset( $typesByKey[ $def->metaKey ] )) {
					$typesByKey[ $def->metaKey ] = $def->type;
				}
			}
		}

		$cpt = UnitPostType::getSlug();

		foreach ($typesByKey as $metaKey => $type) {
			$typeArg = self::wpMetaType( $type );

			register_post_meta(
				$cpt,
				$metaKey,
				[
					'type'              => $typeArg,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static function ($value) use ($type) {
						return UnitSyncFieldRegistry::sanitizeValue( $type, $value );
					},
					'auth_callback'     => $auth,
					'default'           => self::defaultForType( $type ),
				]
			);
		}

		self::$postMetaRegistered = true;
	}

	/**
	 * @param 'string'|'textarea'|'number'|'boolean'|'json' $type
	 */
	private static function wpMetaType(string $type): string
	{
		if ($type === 'boolean') {
			return 'boolean';
		}

		return 'string';
	}

	/**
	 * @param 'string'|'textarea'|'number'|'boolean'|'json' $type
	 *
	 * @return string|bool
	 */
	private static function defaultForType(string $type)
	{
		if ($type === 'boolean') {
			return false;
		}

		return '';
	}

	/**
	 * @param 'string'|'textarea'|'number'|'boolean'|'json' $type
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

				return sanitize_text_field( (string) $value );

			case 'textarea':
				if ($value === null) {
					return '';
				}

				return sanitize_textarea_field( (string) $value );

			case 'number':
				if ($value === null || $value === '') {
					return '';
				}
				if (is_numeric( $value )) {
					$n = $value + 0;

					return is_float( $n ) ? (string) $n : (string) (int) $n;
				}

				return '';

			case 'boolean':
				if ($value === null || $value === '') {
					return false;
				}
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

			case 'json':
				if ($value === null || $value === '') {
					return '';
				}
				if (is_array($value)) {
					$enc = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

					return $enc !== false ? $enc : '';
				}
				if (! is_string($value)) {
					return '';
				}
				$trim = trim($value);
				if ($trim === '') {
					return '';
				}
				$decoded = json_decode($trim, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					return '';
				}
				$enc = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				return $enc !== false ? $enc : '';

			default:
				return '';
		}
	}

	public static function addMetaBox(): void
	{
		add_meta_box(
			'bec_unit_mapped_fields',
			__('Booking engine — unit fields', 'booking-engine-connector'),
			[self::class, 'renderMetaBox'],
			UnitPostType::getSlug(),
			'normal',
			'low'
		);
	}

	public static function renderMetaBox(\WP_Post $post): void
	{
		if (! current_user_can('edit_post', $post->ID)) {
			return;
		}

		self::ensureDefinitionsLoaded();

		$providerSlug = (string) get_post_meta($post->ID, 'bec_provider_slug', true);
		if ($providerSlug === '') {
			echo '<p class="description">' . esc_html__(
				'Save the unit after sync so the provider is set; mapped fields appear per active provider.',
				'booking-engine-connector'
			) . '</p>';

			return;
		}

		$defs = self::$definitionsByProvider[ $providerSlug ] ?? [];
		if ($defs === []) {
			echo '<p class="description">' . esc_html__(
				'No extra provider-specific fields are registered. Use the filter bec_unit_sync_field_definitions (or implement getUnitSyncFieldDefinitions) for client-specific mappings. Core fields are in “Unit — core fields (canonical)”.',
				'booking-engine-connector'
			) . '</p>';

			return;
		}

		wp_nonce_field('bec_unit_mapped_fields_save', 'bec_unit_mapped_fields_nonce');

		echo '<p class="description">' . esc_html__(
			'Optional provider-specific meta (in addition to core fields). Values refresh on sync; you can override here for the site.',
			'booking-engine-connector'
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ($defs as $def) {
			$key   = $def->metaKey;
			$label = $def->label;
			$val   = get_post_meta($post->ID, $key, true);

			echo '<tr><th scope="row"><label for="bec_mapped_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';

			$name = 'bec_mapped_fields[' . $key . ']';

			switch ($def->type) {
				case 'textarea':
					echo '<textarea class="large-text" rows="4" id="bec_mapped_' . esc_attr($key) . '" name="' . esc_attr($name) . '">';
					echo esc_textarea(is_string($val) ? $val : '');
					echo '</textarea>';
					break;
				case 'boolean':
					$on = (bool) $val;
					echo '<input type="hidden" name="' . esc_attr($name) . '" value="0" />';
					echo '<label><input type="checkbox" id="bec_mapped_' . esc_attr($key) . '" name="' . esc_attr($name) . '" value="1" ' . checked($on, true, false) . ' /> ';
					echo esc_html__('Enabled', 'booking-engine-connector') . '</label>';
					break;
				case 'json':
					$display = '';
					if (is_string($val) && $val !== '') {
						$display = $val;
					} elseif (is_array($val)) {
						$enc = wp_json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
						$display = $enc !== false ? $enc : '';
					}
					echo '<textarea class="large-text code" rows="6" id="bec_mapped_' . esc_attr($key) . '" name="' . esc_attr($name) . '" spellcheck="false">';
					echo esc_textarea($display);
					echo '</textarea>';
					break;
				case 'number':
					echo '<input type="text" class="regular-text" id="bec_mapped_' . esc_attr($key) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_scalar($val) ? (string) $val : '') . '" inputmode="decimal" />';
					break;
				default:
					echo '<input type="text" class="large-text" id="bec_mapped_' . esc_attr($key) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_scalar($val) ? (string) $val : '') . '" />';
			}

			echo '<p class="description"><code>' . esc_html($key) . '</code></p>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
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

		if (! isset($_POST['bec_unit_mapped_fields_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['bec_unit_mapped_fields_nonce'])), 'bec_unit_mapped_fields_save')) {
			return;
		}

		if (! current_user_can('edit_post', $postId)) {
			return;
		}

		self::ensureDefinitionsLoaded();

		$providerSlug = (string) get_post_meta($postId, 'bec_provider_slug', true);
		if ($providerSlug === '') {
			return;
		}

		$defs = self::$definitionsByProvider[ $providerSlug ] ?? [];
		if ($defs === []) {
			return;
		}

		$posted = isset($_POST['bec_mapped_fields']) && is_array($_POST['bec_mapped_fields']) ? wp_unslash($_POST['bec_mapped_fields']) : [];

		foreach ($defs as $def) {
			$key = $def->metaKey;

			if ($def->type === 'boolean') {
				$raw = isset($posted[ $key ]) && (string) $posted[ $key ] === '1';
				update_post_meta($postId, $key, self::sanitizeValue('boolean', $raw));

				continue;
			}

			if (! array_key_exists($key, $posted)) {
				continue;
			}

			$raw = $posted[ $key ];
			update_post_meta($postId, $key, self::sanitizeValue($def->type, $raw));
		}
	}
}
