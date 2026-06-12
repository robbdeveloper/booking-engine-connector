<?php

declare(strict_types=1);

namespace BookingEngineConnector\Fallback;

/**
 * Option keys for fallback + checkout base URL (Wave 6).
 */
final class FallbackSettings
{
	public const OPTION_ENABLED = 'bec_fallback_enabled';

	public const OPTION_MODE = 'bec_fallback_mode';

	public const OPTION_FORCE = 'bec_fallback_force';

	public const OPTION_TRIGGER_CATEGORIES = 'bec_fallback_trigger_categories';

	public const OPTION_EMPTY_QUOTE = 'bec_fallback_empty_quote';

	public const OPTION_LINK_URL = 'bec_fallback_link_url';

	public const OPTION_LINK_TEXT = 'bec_fallback_link_text';

	public const OPTION_INLINE_CONTENT = 'bec_fallback_inline_content';

	public const OPTION_CHECKOUT_BASE_URL = 'bec_kross_checkout_base_url';

	/** `get` or `post` — how the browser reaches the Kross booking engine entry URL. */
	public const OPTION_CHECKOUT_HTTP_METHOD = 'bec_kross_checkout_http_method';

	public static function sanitizeLinkTarget(string $raw): string
	{
		$raw = \trim($raw);
		if ($raw === '') {
			return '';
		}

		$raw = \wp_strip_all_tags($raw);
		$raw = (string) \preg_replace('/[\r\n\t]/', '', $raw);

		return self::isAllowedLinkTarget($raw) ? $raw : '';
	}

	public static function escapeLinkHref(string $target): string
	{
		$target = \trim($target);
		if ($target === '' || ! self::isAllowedLinkTarget($target)) {
			return '';
		}

		if (
			$target[0] !== '/'
			&& $target[0] !== '?'
			&& $target[0] !== '#'
			&& ! \preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)
			&& \str_contains($target, '=')
		) {
			$target = '?' . \ltrim($target, '?&');
		}

		if (\preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) {
			return \esc_url($target);
		}

		// esc_url() rewrites encoded hash/query fragments (e.g. Elementor popup triggers).
		return \esc_attr($target);
	}

	private static function isAllowedLinkTarget(string $target): bool
	{
		if ($target === '' || \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $target) === 1) {
			return false;
		}

		return ! \preg_match('#^\s*(javascript|data|vbscript):#i', $target);
	}
}
