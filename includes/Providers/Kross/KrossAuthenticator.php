<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Api\HttpClient;
use BookingEngineConnector\Providers\Auth\AuthenticatorInterface;
use BookingEngineConnector\Providers\Auth\CredentialField;
use BookingEngineConnector\Providers\Contracts\ProviderErrorCategory;
use BookingEngineConnector\Providers\Contracts\ProviderException;

/**
 * Kross API v4: {@see /v4/auth/get-token} with JSON credentials; token at {@see data.auth_token}.
 * Uses POST for the request body: WordPress HTTP cannot send a JSON body on GET (it runs bodies through http_build_query).
 */
final class KrossAuthenticator implements AuthenticatorInterface
{
	public const OPTION_HOTEL_ID = 'bec_kross_hotel_id';

	public const OPTION_API_KEY = 'bec_kross_api_key';

	public const OPTION_USERNAME = 'bec_kross_username';

	public const OPTION_PASSWORD = 'bec_kross_password';

	private const TRANSIENT_TOKEN = 'bec_kross_access_token';

	private const TRANSIENT_EXP = 'bec_kross_access_token_exp';

	private const LOCK_KEY = 'bec_kross_token_lock';

	private string $lastExchangeRawBody = '';

	public function __construct(
		private HttpClient $http = new HttpClient()
	) {
	}

	public function getCredentialFields(): array
	{
		return [
			new CredentialField(
				'hotel_id',
				\__('Hotel ID', 'booking-engine-connector'),
				'text',
				'sanitize_text_field',
				true,
				\__('Kross property / hotel identifier.', 'booking-engine-connector')
			),
			new CredentialField(
				'api_key',
				\__('API key', 'booking-engine-connector'),
				'password',
				static function (string $v): string {
					return \is_string($v) ? \trim($v) : '';
				},
				true,
				\__('Stored in the database as configured in product policy.', 'booking-engine-connector')
			),
			new CredentialField(
				'username',
				\__('API username', 'booking-engine-connector'),
				'text',
				'sanitize_text_field',
				true,
				\__('e.g. apiv4 (see Kross v4 documentation).', 'booking-engine-connector')
			),
			new CredentialField(
				'password',
				\__('API user password', 'booking-engine-connector'),
				'password',
				static function (string $v): string {
					return \is_string($v) ? \trim($v) : '';
				},
				true,
				\__('API user password for token exchange.', 'booking-engine-connector')
			),
		];
	}

	public function getValidToken(): string
	{
		$hotelId  = (string) \get_option(self::OPTION_HOTEL_ID, '');
		$apiKey   = (string) \get_option(self::OPTION_API_KEY, '');
		$username = (string) \get_option(self::OPTION_USERNAME, '');
		$password = (string) \get_option(self::OPTION_PASSWORD, '');

		if ($hotelId === '' || $apiKey === '' || $username === '' || $password === '') {
			throw new ProviderException(
				\__('Kross credentials are not complete (hotel, API key, username, password).', 'booking-engine-connector'),
				ProviderErrorCategory::AUTH
			);
		}

		$cached = \get_transient(self::TRANSIENT_TOKEN);
		$exp    = (int) \get_transient(self::TRANSIENT_EXP);
		if (\is_string($cached) && $cached !== '' && $exp > \time() + 60) {
			return $cached;
		}

		return $this->acquireTokenLocked($hotelId, $apiKey, $username, $password);
	}

	public function invalidate(): void
	{
		\delete_transient(self::TRANSIENT_TOKEN);
		\delete_transient(self::TRANSIENT_EXP);
	}

	private function acquireTokenLocked(string $hotelId, string $apiKey, string $username, string $password): string
	{
		$lock = \get_transient(self::LOCK_KEY);
		if ($lock !== false) {
			$cached = \get_transient(self::TRANSIENT_TOKEN);
			if (\is_string($cached) && $cached !== '') {
				return $cached;
			}
		}

		\set_transient(self::LOCK_KEY, '1', 30);

		try {
			return $this->requestNewToken($hotelId, $apiKey, $username, $password);
		} finally {
			\delete_transient(self::LOCK_KEY);
		}
	}

	private function requestNewToken(string $hotelId, string $apiKey, string $username, string $password): string
	{
		$token = $this->exchangeToken($hotelId, $apiKey, $username, $password, true);

		$decoded = \json_decode($this->lastExchangeRawBody, true);
		$ttl     = $this->resolveTokenTtlSeconds(\is_array($decoded) ? $decoded : []);

		\set_transient(self::TRANSIENT_TOKEN, $token, $ttl);
		\set_transient(self::TRANSIENT_EXP, (string) (\time() + $ttl), $ttl);

		return $token;
	}

	/**
	 * @param array<string, mixed> $credentials Keys: hotel_id, api_key, username, password
	 *
	 * @throws ProviderException
	 */
	public function probeTokenExchange(array $credentials): void
	{
		$hotelId  = (string) ($credentials['hotel_id'] ?? '');
		$apiKey   = (string) ($credentials['api_key'] ?? '');
		$username = (string) ($credentials['username'] ?? '');
		$password = (string) ($credentials['password'] ?? '');

		$this->exchangeToken($hotelId, $apiKey, $username, $password, false);
	}

	/**
	 * @param array<string, mixed> $decoded Top-level JSON from get-token response
	 */
	private function resolveTokenTtlSeconds(array $decoded): int
	{
		$data = $decoded['data'] ?? null;
		if (! \is_array($data)) {
			return 3600;
		}

		$expireRaw = $data['auth_token_expire'] ?? null;
		if (\is_string($expireRaw) && $expireRaw !== '') {
			$ts = \strtotime($expireRaw);
			if ($ts !== false) {
				return \max(120, $ts - \time());
			}
		}

		return 3600;
	}

	/**
	 * @throws ProviderException
	 */
	private function exchangeToken(string $hotelId, string $apiKey, string $username, string $password, bool $invalidateOnFailure): string
	{
		$this->lastExchangeRawBody = '';

		/**
		 * Default Kross v4 token URL (override per environment if needed).
		 *
		 * @param string $default
		 */
		$url = (string) \apply_filters('bec_kross_auth_endpoint', 'https://api.krossbooking.com/v4/auth/get-token');

		if ($url === '') {
			throw new ProviderException(
				\__('Kross auth endpoint is not configured (bec_kross_auth_endpoint).', 'booking-engine-connector'),
				ProviderErrorCategory::AUTH
			);
		}

		$body = \wp_json_encode([
			'api_key'  => $apiKey,
			'hotel_id' => $hotelId,
			'username' => $username,
			'password' => $password,
		]);

		/**
		 * Reference docs mention GET + JSON body; WP HTTP only supports array/query bodies for GET.
		 * Kross accepts POST with the same JSON (verified against production-style clients).
		 */
		$response = $this->http->request('POST', $url, [
			'headers' => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			'body'                => $body,
			'bec_skip_auth'       => true,
			'bec_log_profile'     => 'auth',
			'bec_provider_slug'   => 'kross',
		]);

		if ($response->getStatusCode() >= 400) {
			if ($invalidateOnFailure) {
				$this->invalidate();
			}
			throw new ProviderException(
				\sprintf(
					/* translators: %d HTTP status */
					\__('Kross token request failed (%d).', 'booking-engine-connector'),
					$response->getStatusCode()
				),
				ProviderErrorCategory::AUTH
			);
		}

		$raw  = $response->getBody();
		$data = \json_decode($raw, true);
		$this->lastExchangeRawBody = $raw;

		$inner = \is_array($data) && isset($data['data']) && \is_array($data['data']) ? $data['data'] : $data;
		$token = \is_array($inner) && isset($inner['auth_token']) ? (string) $inner['auth_token'] : '';

		if ($token === '') {
			if ($invalidateOnFailure) {
				$this->invalidate();
			}
			throw new ProviderException(
				\__('Kross token response did not contain data.auth_token.', 'booking-engine-connector'),
				ProviderErrorCategory::AUTH
			);
		}

		return $token;
	}
}
