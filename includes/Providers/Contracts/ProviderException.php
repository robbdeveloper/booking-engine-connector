<?php

declare(strict_types=1);

namespace BookingEngineConnector\Providers\Contracts;

/**
 * Provider-layer failure with a stable {@see ProviderErrorCategory} label.
 */
class ProviderException extends \Exception
{
	public function __construct(
		string $message,
		private string $category = ProviderErrorCategory::UNKNOWN,
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getCategory(): string
	{
		return $this->category;
	}
}
