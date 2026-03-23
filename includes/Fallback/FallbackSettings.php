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
}
