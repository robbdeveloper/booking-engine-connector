<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Kross;

use BookingEngineConnector\Api\HttpClient;
use BookingEngineConnector\Api\HttpResponse;

/**
 * Kross v4 JSON envelope: every call sends {@see auth_token} + {@see data} in the body (not Bearer).
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
	 * @param array<string, mixed> $data Business payload for the `data` key.
	 */
	public function request(string $method, string $path, array $data = []): HttpResponse
	{
		$token = $this->authenticator->getValidToken();
		$url   = $this->getBaseUrl() . $path;

		$body = \wp_json_encode([
			'auth_token' => $token,
			'data'       => $data,
		]);

		$httpMethod = \strtoupper($method);
		/**
		 * Kross reference uses GET with a JSON body for some paths; `wp_remote_request` cannot
		 * attach JSON to GET (Requests passes the body through http_build_query). POST with the
		 * same envelope is accepted (same as {@see KrossAuthenticator::exchangeToken}).
		 */
		if ($httpMethod === 'GET') {
			$httpMethod = 'POST';
		}

		return $this->http->request($httpMethod, $url, [
			'headers' => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			'body'                => $body,
			'bec_skip_auth'       => true,
			'bec_provider_slug'   => 'kross',
			'bec_log_profile'     => 'business',
		]);
	}
}
