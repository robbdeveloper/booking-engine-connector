<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin\Settings;

use BookingEngineConnector\Admin\AdminMenu;
use BookingEngineConnector\Providers\Auth\CredentialField;
use BookingEngineConnector\Providers\Contracts\ProviderException;
use BookingEngineConnector\Providers\Kross\KrossAuthenticator;
use BookingEngineConnector\Providers\ProviderRegistry;
use BookingEngineConnector\Search\SearchSettings;

/**
 * Admin “Connection” settings: provider selection, dynamic credential fields, save + test (TASK-AUTH-003).
 */
final class ConnectionPage
{
	public const PAGE_SLUG = 'bec-connection';

	private const NONCE_ACTION = 'bec_connection_save';

	public static function register(): void
	{
		\add_action('admin_init', [self::class, 'handlePost']);
	}

	public static function render(): void
	{
		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$providers = self::registeredProviders();
		$slug      = \sanitize_key((string) \get_option(ProviderRegistry::OPTION_ACTIVE, 'kross'));
		if (! isset($providers[ $slug ])) {
			$slug = 'kross';
		}

		$provider = ProviderRegistry::getProvider($slug);
		/** @var list<CredentialField> $fields */
		$fields = $provider->getCredentialSchema();

		$guestInputMode  = SearchSettings::getGuestInputModeOption();
		$childAgesMode   = SearchSettings::getChildAgesModeOption();

		echo '<div class="wrap bec-connection">';
		echo '<h1>' . \esc_html__('Connection', 'booking-engine-connector') . '</h1>';

		self::renderNotices();

		echo '<form method="post" action="' . \esc_url(\admin_url('admin.php')) . '" class="bec-connection__form">';
		echo '<input type="hidden" name="page" value="' . \esc_attr(self::PAGE_SLUG) . '" />';
		\wp_nonce_field(self::NONCE_ACTION, 'bec_connection_nonce');

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="bec_active_provider">' . \esc_html__('Provider', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_active_provider" id="bec_active_provider">';
		foreach ($providers as $pSlug => $label) {
			echo '<option value="' . \esc_attr((string) $pSlug) . '" ' . \selected($slug, (string) $pSlug, false) . '>' . \esc_html((string) $label) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . \esc_html__('Additional providers can be registered via the bec_registered_providers filter.', 'booking-engine-connector') . '</p>';
		echo '</td></tr>';

		foreach ($fields as $field) {
			$stored = (string) \get_option(self::storageKey($slug, $field->key), '');
			$value  = $stored;

			$inputId = 'bec_credential_' . \sanitize_key($field->key);
			echo '<tr><th scope="row"><label for="' . \esc_attr($inputId) . '">' . \esc_html($field->label);
			if ($field->required) {
				echo ' <span class="description">(' . \esc_html__('required', 'booking-engine-connector') . ')</span>';
			}
			echo '</label></th><td>';

			if ($field->type === 'password') {
				echo '<input type="password" class="regular-text" name="bec_credential[' . \esc_attr($field->key) . ']" id="' . \esc_attr($inputId) . '" value="" autocomplete="new-password" />';
				if ($stored !== '') {
					echo '<p class="description">' . \esc_html__('Leave blank to keep the saved API key unchanged.', 'booking-engine-connector') . '</p>';
				}
			} else {
				echo '<input type="text" class="regular-text" name="bec_credential[' . \esc_attr($field->key) . ']" id="' . \esc_attr($inputId) . '" value="' . \esc_attr($value) . '" />';
			}

			if ($field->help !== '') {
				echo '<p class="description">' . \esc_html($field->help) . '</p>';
			}

			echo '</td></tr>';
		}

		echo '</table>';

		echo '<h2>' . \esc_html__('Search form (front)', 'booking-engine-connector') . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="bec_search_guest_input_mode">' . \esc_html__('How guests are collected', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_search_guest_input_mode" id="bec_search_guest_input_mode">';
		echo '<option value="' . \esc_attr(SearchSettings::GUEST_MODE_PROVIDER) . '" ' . \selected($guestInputMode, SearchSettings::GUEST_MODE_PROVIDER, false) . '>' . \esc_html__(
			'Follow the active provider (default)',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::GUEST_MODE_TOTAL) . '" ' . \selected($guestInputMode, SearchSettings::GUEST_MODE_TOTAL, false) . '>' . \esc_html__(
			'Single “Guests” count only',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::GUEST_MODE_BREAKDOWN) . '" ' . \selected($guestInputMode, SearchSettings::GUEST_MODE_BREAKDOWN, false) . '>' . \esc_html__(
			'Adults and children (separate fields)',
			'booking-engine-connector'
		) . '</option>';
		echo '</select>';
		echo '<p class="description">' . \esc_html__(
			'Controls the availability search bar. Use a single total or split adults/children, regardless of the provider. “Follow the active provider” uses each engine’s own rules (e.g. Kross can default to a simple guest count).',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="bec_search_child_ages_mode">' . \esc_html__('Child ages in search', 'booking-engine-connector') . '</label></th><td>';
		echo '<select name="bec_search_child_ages_mode" id="bec_search_child_ages_mode">';
		echo '<option value="' . \esc_attr(SearchSettings::CHILD_AGES_PROVIDER) . '" ' . \selected($childAgesMode, SearchSettings::CHILD_AGES_PROVIDER, false) . '>' . \esc_html__(
			'Follow the active provider',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::CHILD_AGES_YES) . '" ' . \selected($childAgesMode, SearchSettings::CHILD_AGES_YES, false) . '>' . \esc_html__(
			'Ask for each child’s age',
			'booking-engine-connector'
		) . '</option>';
		echo '<option value="' . \esc_attr(SearchSettings::CHILD_AGES_NO) . '" ' . \selected($childAgesMode, SearchSettings::CHILD_AGES_NO, false) . '>' . \esc_html__(
			'Do not ask for child ages',
			'booking-engine-connector'
		) . '</option>';
		echo '</select>';
		echo '<p class="description">' . \esc_html__(
			'Applies when the search form shows adults and children. Ignored when using only a single guest count.',
			'booking-engine-connector'
		) . '</p>';
		echo '</td></tr>';

		echo '</table>';

		echo '<p class="submit">';
		echo '<button type="submit" name="bec_connection_action" value="save" class="button button-primary">' . \esc_html__('Save connection settings', 'booking-engine-connector') . '</button> ';
		echo '<button type="submit" name="bec_connection_action" value="test" class="button">' . \esc_html__('Verify connection', 'booking-engine-connector') . '</button>';
		echo '</p>';
		echo '<p class="description">' . \esc_html__('Save stores credentials in the database. Verify runs a token exchange against the configured auth endpoint without printing secrets.', 'booking-engine-connector') . '</p>';

		echo '</form></div>';
	}

	public static function handlePost(): void
	{
		if (! isset($_POST['page'], $_POST['bec_connection_nonce'], $_POST['bec_connection_action'])) {
			return;
		}

		if (\sanitize_key(\wp_unslash((string) $_POST['page'])) !== self::PAGE_SLUG) {
			return;
		}

		$nonce = \wp_unslash((string) $_POST['bec_connection_nonce']);
		if (! \wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		$action = \sanitize_key(\wp_unslash((string) $_POST['bec_connection_action']));
		if ($action !== 'save' && $action !== 'test') {
			return;
		}

		$providers = self::registeredProviders();
		$slug      = isset($_POST['bec_active_provider']) ? \sanitize_key(\wp_unslash((string) $_POST['bec_active_provider'])) : 'kross';
		if (! isset($providers[ $slug ])) {
			$slug = 'kross';
		}

		$provider = ProviderRegistry::getProvider($slug);
		/** @var list<CredentialField> $fields */
		$fields = $provider->getCredentialSchema();

		$raw = isset($_POST['bec_credential']) && \is_array($_POST['bec_credential'])
			? \wp_unslash($_POST['bec_credential'])
			: [];

		$values = [];
		foreach ($fields as $field) {
			$optionName = self::storageKey($slug, $field->key);
			$stored     = (string) \get_option($optionName, '');
			$incoming   = isset($raw[ $field->key ]) ? (string) $raw[ $field->key ] : '';

			if ($field->type === 'password' && $incoming === '') {
				$values[ $field->key ] = $stored;
				continue;
			}

			/** @var callable(string): string $cb */
			$cb                    = $field->sanitize_callback;
			$values[ $field->key ] = $cb($incoming);
		}

		if ($action === 'save') {
			foreach ($fields as $field) {
				if ($field->required && $values[ $field->key ] === '') {
					self::setFlash('error', \__('Please fill in all required credential fields.', 'booking-engine-connector'));
					self::redirectBack();
				}
			}

			\update_option(ProviderRegistry::OPTION_ACTIVE, $slug, false);

			foreach ($fields as $field) {
				\update_option(self::storageKey($slug, $field->key), $values[ $field->key ], false);
			}

			$gMode = isset($_POST['bec_search_guest_input_mode'])
				? \sanitize_key(\wp_unslash((string) $_POST['bec_search_guest_input_mode']))
				: SearchSettings::GUEST_MODE_PROVIDER;
			if (! \in_array($gMode, [SearchSettings::GUEST_MODE_PROVIDER, SearchSettings::GUEST_MODE_TOTAL, SearchSettings::GUEST_MODE_BREAKDOWN], true)) {
				$gMode = SearchSettings::GUEST_MODE_PROVIDER;
			}
			\update_option(SearchSettings::OPTION_GUEST_INPUT_MODE, $gMode, false);

			$cMode = isset($_POST['bec_search_child_ages_mode'])
				? \sanitize_key(\wp_unslash((string) $_POST['bec_search_child_ages_mode']))
				: SearchSettings::CHILD_AGES_PROVIDER;
			if (! \in_array($cMode, [SearchSettings::CHILD_AGES_PROVIDER, SearchSettings::CHILD_AGES_YES, SearchSettings::CHILD_AGES_NO], true)) {
				$cMode = SearchSettings::CHILD_AGES_PROVIDER;
			}
			\update_option(SearchSettings::OPTION_CHILD_AGES_MODE, $cMode, false);

			self::setFlash('success', \__('Connection settings saved.', 'booking-engine-connector'));
			self::redirectBack();
		}

		if ($action === 'test') {
			foreach ($fields as $field) {
				if ($values[ $field->key ] === '') {
					self::setFlash('error', \__('Enter all required credentials (including API user and password) before running the connection test.', 'booking-engine-connector'));
					self::redirectBack();
				}
			}

			$handled = \apply_filters('bec_test_connection', null, $slug, $values);
			if ($handled instanceof \WP_Error) {
				self::setFlash('error', $handled->get_error_message());
				self::redirectBack();
			}
			if (\is_string($handled) && $handled !== '') {
				self::setFlash('success', $handled);
				self::redirectBack();
			}

			if ($slug === 'kross') {
				try {
					$auth = new KrossAuthenticator();
					$auth->probeTokenExchange($values);
					self::setFlash('success', \__('Connection test succeeded (token exchange OK).', 'booking-engine-connector'));
				} catch (ProviderException $e) {
					self::setFlash('error', $e->getMessage());
				}
				self::redirectBack();
			}

			self::setFlash('error', \__('No connection test is registered for this provider.', 'booking-engine-connector'));
			self::redirectBack();
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function registeredProviders(): array
	{
		$defaults = [
			'kross' => \__('Kross Booking', 'booking-engine-connector'),
		];

		$merged = \apply_filters('bec_registered_providers', $defaults);

		return \is_array($merged) ? $merged : $defaults;
	}

	private static function storageKey(string $providerSlug, string $fieldKey): string
	{
		return 'bec_' . \sanitize_key($providerSlug) . '_' . \sanitize_key($fieldKey);
	}

	private static function redirectBack(): void
	{
		$url = \add_query_arg(
			[
				'page'     => self::PAGE_SLUG,
				'bec_flash' => '1',
			],
			\admin_url('admin.php')
		);

		\wp_safe_redirect($url);
		exit;
	}

	private static function setFlash(string $type, string $message): void
	{
		\set_transient(
			self::flashKey(),
			[
				'type'    => $type,
				'message' => $message,
			],
			120
		);
	}

	private static function flashKey(): string
	{
		$userId = \get_current_user_id();

		return 'bec_connection_flash_' . $userId;
	}

	private static function renderNotices(): void
	{
		if (! isset($_GET['bec_flash'])) {
			return;
		}

		$data = \get_transient(self::flashKey());
		\delete_transient(self::flashKey());

		if (! \is_array($data) || ! isset($data['type'], $data['message'])) {
			return;
		}

		$type    = $data['type'] === 'success' ? 'success' : 'error';
		$message = (string) $data['message'];

		echo '<div class="notice notice-' . \esc_attr($type) . ' is-dismissible"><p>' . \esc_html($message) . '</p></div>';
	}
}
