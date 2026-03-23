<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Auth;

/**
 * Provider authentication: credential shape, valid access token, invalidation.
 */
interface AuthenticatorInterface
{
	/**
	 * @return list<CredentialField>
	 */
	public function getCredentialFields(): array;

	public function getValidToken(): string;

	public function invalidate(): void;
}
