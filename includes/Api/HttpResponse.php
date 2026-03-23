<?php

declare(strict_types=1);

namespace BookingEngineConnector\Api;

/**
 * Immutable HTTP response from {@see HttpClient}.
 */
final class HttpResponse
{
	public function __construct(
		private int $statusCode,
		private string $body,
		/** @var array<string, string|array<int, string>> */
		private array $headers
	) {
	}

	public function getStatusCode(): int
	{
		return $this->statusCode;
	}

	public function getBody(): string
	{
		return $this->body;
	}

	/**
	 * @return array<string, string|array<int, string>>
	 */
	public function getHeaders(): array
	{
		return $this->headers;
	}

	/**
	 * @param array|object $wpResponse Result of {@see wp_remote_request} when not a WP_Error.
	 */
	public static function fromWpRemote($wpResponse): self
	{
		$code = (int) wp_remote_retrieve_response_code($wpResponse);
		$body = (string) wp_remote_retrieve_body($wpResponse);
		$raw  = wp_remote_retrieve_headers($wpResponse);

		$headers = self::normalizeHeaders($raw);

		return new self($code, $body, $headers);
	}

	public static function fromTransportError(string $message): self
	{
		return new self(0, $message, []);
	}

	/**
	 * @param array|object $raw
	 * @return array<string, string|array<int, string>>
	 */
	private static function normalizeHeaders($raw): array
	{
		$out = [];

		if (is_object($raw) && $raw instanceof \IteratorAggregate) {
			foreach ($raw as $name => $value) {
				$out[(string) $name] = $value;
			}

			return $out;
		}

		if (is_array($raw)) {
			foreach ($raw as $name => $value) {
				$out[(string) $name] = $value;
			}
		}

		return $out;
	}
}
