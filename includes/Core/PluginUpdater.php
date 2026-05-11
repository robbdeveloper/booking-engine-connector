<?php

declare(strict_types=1);

namespace BookingEngineConnector\Core;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * GitHub release updates via vendored Plugin Update Checker.
 *
 * Releases must publish a .zip asset (see docs/RELEASES.md). For private repos, set
 * optional `const` `BEC_GITHUB_UPDATER_TOKEN` in wp-config.php.
 */
final class PluginUpdater
{
	/**
	 * Optional GitHub PAT for private repositories (define in wp-config.php before plugins load).
	 */
	public const AUTH_TOKEN_CONSTANT = 'BEC_GITHUB_UPDATER_TOKEN';

	private const GITHUB_REPO_URL = 'https://github.com/robbdeveloper/booking-engine-connector/';

	private const SLUG = 'booking-engine-connector';

	public static function register(): void
	{
		$pucLoader = \BEC_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

		if (! is_readable($pucLoader)) {
			return;
		}

		require_once $pucLoader;

		$checker = PucFactory::buildUpdateChecker(self::GITHUB_REPO_URL, \BEC_PLUGIN_FILE, self::SLUG);

		$vcsApi = method_exists($checker, 'getVcsApi') ? $checker->getVcsApi() : null;
		if ($vcsApi !== null && \is_object($vcsApi) && method_exists($vcsApi, 'enableReleaseAssets')) {
			$vcsApi->enableReleaseAssets('/booking-engine-connector-.*\\.zip$/i');
		}

		if (\defined(self::AUTH_TOKEN_CONSTANT) && \is_string(\constant(self::AUTH_TOKEN_CONSTANT))) {
			$token = (string) \constant(self::AUTH_TOKEN_CONSTANT);
			if ($token !== '' && method_exists($checker, 'setAuthentication')) {
				$checker->setAuthentication($token);
			}
		}
	}
}
