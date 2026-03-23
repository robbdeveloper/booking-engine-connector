<?php

declare(strict_types=1);

namespace BookingEngineConnector\Api;

use BookingEngineConnector\Logging\ApiLogRepository;
use BookingEngineConnector\Providers\Auth\AuthenticatorInterface;

/**
 * Central HTTP transport: timeouts, 429 backoff, optional bearer auth + single 401 refresh, structured logging.
 */
final class HttpClient
{
	private const CORRELATION_HEADER = 'X-BEC-Correlation-Id';

	/** @var list<string> */
	private const INTERNAL_ARG_KEYS = [
		'bec_skip_auth',
		'bec_log_profile',
		'bec_provider_slug',
		'bec_unit_id',
		'bec_log_message',
	];

	public function __construct(
		private ?AuthenticatorInterface $authenticator = null,
		private ?ApiLogRepository $apiLogRepository = null
	) {
		$this->apiLogRepository = $apiLogRepository ?? new ApiLogRepository();
	}

	public function request(string $method, string $url, array $args = []): HttpResponse
	{
		$timeout    = (int) \apply_filters('bec_http_timeout', 30);
		$maxRetries = (int) \apply_filters('bec_http_max_retries', 3);

		$skipAuth       = ! empty($args['bec_skip_auth']);
		$logProfile     = (string) ($args['bec_log_profile'] ?? 'business');
		$providerSlug   = (string) ($args['bec_provider_slug'] ?? '');
		$unitId         = isset($args['bec_unit_id']) ? (int) $args['bec_unit_id'] : null;
		$logMessageHint = isset($args['bec_log_message']) ? (string) $args['bec_log_message'] : null;

		$authRetryUsed = false;
		$retry429      = 0;

		while (true) {
			$wpArgs = $this->stripInternalKeys($args);
			$wpArgs['method']  = \strtoupper($method);
			$wpArgs['timeout'] = $timeout;

			$headers = isset($wpArgs['headers']) && \is_array($wpArgs['headers'])
				? $wpArgs['headers']
				: [];
			$headers = $this->ensureCorrelationHeader($headers);

			if ($this->authenticator !== null && ! $skipAuth) {
				$token = $this->authenticator->getValidToken();
				$headers['Authorization'] = 'Bearer ' . $token;
			}

			$wpArgs['headers'] = $headers;

			$correlationId = isset($headers[self::CORRELATION_HEADER])
				? (string) $headers[self::CORRELATION_HEADER]
				: '';

			$startedAt = \microtime(true);
			$response  = \wp_remote_request($url, $wpArgs);
			$durationMs = (int) \round((\microtime(true) - $startedAt) * 1000);

			$this->maybeLogRequest(
				$url,
				$wpArgs['method'],
				$response,
				$durationMs,
				$logProfile,
				$providerSlug,
				$unitId,
				$correlationId,
				$logMessageHint
			);

			if (\is_wp_error($response)) {
				return HttpResponse::fromTransportError($response->get_error_message());
			}

			$status = (int) \wp_remote_retrieve_response_code($response);

			if ($status === 429 && $retry429 < $maxRetries) {
				++$retry429;
				$this->sleepBackoffMs($retry429);
				continue;
			}

			if (($status === 401 || $status === 403) && $this->authenticator !== null && ! $skipAuth && ! $authRetryUsed) {
				$this->authenticator->invalidate();
				$authRetryUsed = true;
				continue;
			}

			return HttpResponse::fromWpRemote($response);
		}
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function stripInternalKeys(array $args): array
	{
		foreach (self::INTERNAL_ARG_KEYS as $key) {
			unset($args[$key]);
		}

		return $args;
	}

	/**
	 * @param array<string, string|array<int, string>> $headers
	 * @return array<string, string|array<int, string>>
	 */
	private function ensureCorrelationHeader(array $headers): array
	{
		foreach (\array_keys($headers) as $key) {
			if (\strcasecmp((string) $key, self::CORRELATION_HEADER) === 0) {
				return $headers;
			}
		}

		$headers[self::CORRELATION_HEADER] = $this->generateCorrelationId();

		return $headers;
	}

	private function generateCorrelationId(): string
	{
		if (\function_exists('wp_generate_uuid4')) {
			return \wp_generate_uuid4();
		}

		return \uniqid('bec_', true);
	}

	private function sleepBackoffMs(int $retryIndex): void
	{
		$baseMs = (int) \apply_filters('bec_http_429_base_delay_ms', 1000);
		$maxMs  = (int) \apply_filters('bec_http_429_max_delay_ms', 60000);

		if ($baseMs < 1) {
			$baseMs = 1;
		}

		$cap = \min($maxMs, (int) ($baseMs * (2 ** ($retryIndex - 1))));
		$jitterFactor = 0.5 + (\random_int(0, 1000) / 2000.0);
		$delayMs      = (int) \min($maxMs, \max(1, (int) \round($cap * $jitterFactor)));

		\usleep($delayMs * 1000);
	}

	/**
	 * @param mixed $response
	 */
	private function maybeLogRequest(
		string $url,
		string $httpMethod,
		$response,
		int $durationMs,
		string $logProfile,
		string $providerSlug,
		?int $unitId,
		string $correlationId,
		?string $logMessageHint
	): void {
		if ($logProfile === 'none') {
			return;
		}

		if ($logProfile === 'auth' && ! \apply_filters('bec_log_auth_requests', false)) {
			return;
		}

		$should = (bool) \apply_filters('bec_http_should_log_request', true, [
			'profile' => $logProfile,
			'url'     => $url,
		]);

		if (! $should) {
			return;
		}

		$endpoint = (string) \apply_filters('bec_http_log_endpoint', $this->sanitizeEndpointForLog($url), $url);

		if (\is_wp_error($response)) {
			$status  = 0;
			$message = $response->get_error_message();
		} else {
			$status = (int) \wp_remote_retrieve_response_code($response);
			$message = null;
			if ($logMessageHint !== null && $logMessageHint !== '') {
				$message = $logMessageHint;
			} elseif ($status >= 400) {
				$body = (string) \wp_remote_retrieve_body($response);
				$message = \mb_substr(\wp_strip_all_tags($body), 0, 500);
			}
		}

		if ($providerSlug === '') {
			$providerSlug = 'unknown';
		}

		$this->apiLogRepository->insert([
			'provider_slug'  => $providerSlug,
			'http_method'    => $httpMethod,
			'endpoint'       => $endpoint,
			'status_code'    => $status,
			'duration_ms'    => $durationMs,
			'message'        => $message,
			'unit_id'        => $unitId,
			'correlation_id' => $correlationId !== '' ? $correlationId : null,
		]);
	}

	private function sanitizeEndpointForLog(string $url): string
	{
		$parts = \wp_parse_url($url);
		if (! \is_array($parts)) {
			return $url;
		}

		$host = isset($parts['host']) ? $parts['host'] : '';
		$path = isset($parts['path']) ? $parts['path'] : '';

		return $host !== '' ? $host . $path : $path;
	}
}
