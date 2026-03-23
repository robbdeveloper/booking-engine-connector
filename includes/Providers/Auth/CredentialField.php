<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Auth;

/**
 * One admin credential field (schema + presentation).
 */
final class CredentialField
{
	/**
	 * @param null|callable(string): string $sanitize_callback
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $type,
		public $sanitize_callback,
		public bool $required,
		public string $help = ''
	) {
	}
}
