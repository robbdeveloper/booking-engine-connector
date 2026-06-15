<?php

declare(strict_types=1);

namespace BookingEngineConnector\Fallback;

/**
 * Markup for inline + link fallback modes (TASK-FB-002).
 */
final class FallbackRenderer
{
	public static function render(): string
	{
		$mode = (string) \get_option(FallbackSettings::OPTION_MODE, 'inline');

		$url = \trim(FallbackSettings::getLocalizedLinkUrl());
		$storedLinkText = FallbackSettings::getLocalizedLinkText();
		if ($storedLinkText !== '') {
			$text = $storedLinkText;
		} else {
			/* translators: Default fallback link label when none is saved in settings. */
			$text = \__('Contact us', 'booking-engine-connector');
		}
		$inline = FallbackSettings::getLocalizedInlineContent();

		\ob_start();
		echo '<aside class="bec-fallback" role="complementary">';

		if ($mode === 'link' && $url !== '') {
			echo '<a class="bec-fallback__link" href="' . FallbackSettings::escapeLinkHref($url) . '">' . \esc_html($text) . '</a>';
		} elseif ($inline !== '') {
			echo '<div class="bec-fallback__inner">';
			echo \do_shortcode(\wp_kses_post($inline));
			echo '</div>';
		} else {
			echo '<p class="bec-fallback__hint">' . \esc_html__(
				'Add fallback content or link in Booking Engine → Fallback.',
				'booking-engine-connector'
			) . '</p>';
		}

		echo '</aside>';

		$html = (string) \ob_get_clean();

		return (string) \apply_filters('bec_fallback_html', $html);
	}
}
