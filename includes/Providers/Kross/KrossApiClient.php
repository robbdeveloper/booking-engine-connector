<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Api\HttpClient;
use BookingEngineConnector\Api\HttpResponse;

/**
 * Kross API v5: authenticated calls send {@see Authorization: Bearer <token>} and a raw JSON body
 * (no {@code auth_token}/{@code data} wrapper). See https://api.krossbooking.com/apiv5/
 */
final class KrossApiClient
{
	public function __construct(
		private ?HttpClient $http = null,
		private ?KrossAuthenticator $authenticator = null
	) {
		$this->http          = $http ?? new HttpClient();
		$this->authenticator = $authenticator ?? new KrossAuthenticator($this->http);
	}

	public function getBaseUrl(): string
	{
		$url = (string) \apply_filters('bec_kross_base_url', 'https://api.krossbooking.com');

		return \rtrim($url, '/');
	}

	/**
	 * @param array<string, mixed> $data JSON object or list sent as the request body (v5 business payload).
	 */
	public function request(string $method, string $path, array $data = []): HttpResponse
	{
		$url = $this->getBaseUrl() . $path;

		$httpMethod = \strtoupper($method);
		/**
		 * v5 uses POST with JSON for most endpoints; WordPress HTTP cannot attach a JSON body to GET.
		 */
		if ($httpMethod === 'GET') {
			$httpMethod = 'POST';
		}

		$body = \wp_json_encode($data, \JSON_UNESCAPED_UNICODE);
		if ($body === false) {
			$body = '{}';
		}

		$max401Attempts = (int) \apply_filters('bec_kross_api_401_retries', 1);
		if ($max401Attempts < 0) {
			$max401Attempts = 0;
		}

		$authRetries = 0;
		while (true) {
			$token = $this->authenticator->getValidToken();

			$response = $this->http->request($httpMethod, $url, [
				'headers' => [
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'              => $body,
				'bec_skip_auth'     => true,
				'bec_provider_slug' => 'kross',
				'bec_log_profile'   => 'business',
			]);

			if ($response->getStatusCode() !== 401) {
				return $response;
			}

			if ($authRetries >= $max401Attempts) {
				return $response;
			}

			$this->authenticator->invalidate();
			++$authRetries;
		}
	}
}
