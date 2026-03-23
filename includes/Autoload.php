<?php

declare(strict_types=1);

namespace BookingEngineConnector;

/**
 * Minimal PSR-4 autoloader for BookingEngineConnector\* → includes/.
 */
final class Autoload
{
	private const PREFIX = 'BookingEngineConnector\\';

	private const BASE_DIR = __DIR__ . DIRECTORY_SEPARATOR;

	public static function register(): void
	{
		spl_autoload_register([self::class, 'load']);
	}

	public static function load(string $class): void
	{
		if (strncmp(self::PREFIX, $class, strlen(self::PREFIX)) !== 0) {
			return;
		}

		$relative = substr($class, strlen(self::PREFIX));
		$relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
		$file         = self::BASE_DIR . $relativePath;

		if (is_readable($file)) {
			require $file;
		}
	}
}
