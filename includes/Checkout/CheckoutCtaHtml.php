<?php

declare(strict_types=1);

namespace BookingEngineConnector\Checkout;

/**
 * Renders checkout CTA as a link (GET) or form (POST) from a normalized payload.
 */
final class CheckoutCtaHtml
{
	/**
	 * @param array<string, mixed> $data Normalized payload from {@see CheckoutUrlService::buildForPost()}.
	 */
	public static function renderCta(array $data): string
	{
		$url = (string) $data['url'];
		if ($url === '') {
			return '';
		}

		$label = isset($data['label']) && (string) $data['label'] !== ''
			? (string) $data['label']
			: \__('Book now', 'booking-engine-connector');

		$method = isset($data['method']) ? \strtolower(\trim((string) $data['method'])) : 'get';
		if ($method === 'post') {
			return self::renderPostForm($url, \is_array($data['post_fields'] ?? null) ? $data['post_fields'] : [], $label);
		}

		return '<a class="bec-checkout-cta" href="' . \esc_url($url) . '">' . \esc_html($label) . '</a>';
	}

	/**
	 * @param array<string, mixed> $postFields
	 */
	private static function renderPostForm(string $actionUrl, array $postFields, string $label): string
	{
		\ob_start();
		echo '<form class="bec-checkout-form" method="post" action="' . \esc_url($actionUrl) . '">';
		foreach ($postFields as $name => $value) {
			if (! \is_string($name) || $name === '' || \is_array($value)) {
				continue;
			}
			echo '<input type="hidden" name="' . \esc_attr($name) . '" value="' . \esc_attr((string) $value) . '" />';
		}
		echo '<button type="submit" class="bec-checkout-cta">' . \esc_html($label) . '</button>';
		echo '</form>';

		return (string) \ob_get_clean();
	}
}
