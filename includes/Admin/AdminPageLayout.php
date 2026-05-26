<?php

declare(strict_types=1);

namespace BookingEngineConnector\Admin;

/**
 * Shared wp-admin layout helpers and targeted asset enqueueing for Booking Engine screens.
 */
final class AdminPageLayout
{
	/** @var list<string> */
	public const PAGE_SLUGS = [
		'bec-dashboard',
		'bec-connection',
		'bec-frontend',
		SyncAdmin::PAGE_SLUG,
		Settings\UnitPermalinkPage::PAGE_SLUG,
		Settings\UnitFiltersPage::PAGE_SLUG,
		Settings\StylingPage::PAGE_SLUG,
		Settings\FallbackPage::PAGE_SLUG,
		'bec-api-log',
	];

	public static function register(): void
	{
		\add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
	}

	public static function enqueueAssets(string $hookSuffix): void
	{
		if (! self::isBecAdminScreen($hookSuffix)) {
			return;
		}

		if (! \current_user_can(AdminMenu::CAPABILITY)) {
			return;
		}

		\wp_enqueue_style(
			'bec-admin',
			\BEC_PLUGIN_URL . 'assets/admin.css',
			[],
			\BEC_VERSION
		);
	}

	public static function isBecAdminScreen(?string $hookSuffix = null): bool
	{
		$page = isset($_GET['page'])
			? \sanitize_key(\wp_unslash((string) $_GET['page']))
			: '';

		if ($page !== '' && \in_array($page, self::PAGE_SLUGS, true)) {
			return true;
		}

		if ($hookSuffix === null) {
			return false;
		}

		if ($hookSuffix === 'toplevel_page_bec-dashboard') {
			return true;
		}

		return \str_starts_with($hookSuffix, 'bec-dashboard_page_');
	}

	public static function wrapOpen(string $title, string $description = '', string $wrapClass = ''): void
	{
		$classes = 'wrap bec-admin';
		if ($wrapClass !== '') {
			$classes .= ' ' . \esc_attr($wrapClass);
		}

		echo '<div class="' . $classes . '">';
		echo '<div class="bec-admin__header">';
		echo '<h1>' . \esc_html($title) . '</h1>';
		if ($description !== '') {
			echo '<p class="bec-admin__lead">' . \esc_html($description) . '</p>';
		}
		echo '</div>';
	}

	public static function wrapClose(): void
	{
		echo '</div>';
	}

	public static function cardOpen(string $title, string $description = '', string $extraClass = ''): void
	{
		$classes = 'bec-admin-card';
		if ($extraClass !== '') {
			$classes .= ' ' . \esc_attr($extraClass);
		}

		echo '<div class="' . $classes . '">';
		echo '<div class="bec-admin-card__header">';
		echo '<h2 class="bec-admin-card__title">' . \esc_html($title) . '</h2>';
		if ($description !== '') {
			echo '<p class="bec-admin-card__description">' . \wp_kses_post($description) . '</p>';
		}
		echo '</div>';
		echo '<div class="bec-admin-card__body">';
	}

	public static function cardClose(): void
	{
		echo '</div></div>';
	}

	public static function cardFooterOpen(): void
	{
		echo '</div><div class="bec-admin-card__footer">';
	}

	public static function cardFooterClose(): void
	{
		echo '</div></div>';
	}

	public static function badge(string $label, string $variant = 'neutral'): string
	{
		$allowed = ['success', 'warning', 'error', 'neutral'];
		if (! \in_array($variant, $allowed, true)) {
			$variant = 'neutral';
		}

		return '<span class="bec-admin-badge bec-admin-badge--' . \esc_attr($variant) . '">' . \esc_html($label) . '</span>';
	}

	public static function statusGridOpen(): void
	{
		echo '<div class="bec-admin-status-grid">';
	}

	public static function statusGridClose(): void
	{
		echo '</div>';
	}

	public static function statusCard(
		string $label,
		string $value,
		string $badgeHtml = '',
		string $meta = '',
		string $linkUrl = '',
		string $linkLabel = ''
	): void {
		echo '<div class="bec-admin-status-card">';
		echo '<span class="bec-admin-status-card__label">' . \esc_html($label) . '</span>';
		echo '<span class="bec-admin-status-card__value">' . \esc_html($value);
		if ($badgeHtml !== '') {
			echo ' ' . $badgeHtml;
		}
		echo '</span>';
		if ($meta !== '') {
			echo '<span class="bec-admin-status-card__meta">' . \esc_html($meta) . '</span>';
		}
		if ($linkUrl !== '' && $linkLabel !== '') {
			echo '<a class="bec-admin-status-card__link" href="' . \esc_url($linkUrl) . '">' . \esc_html($linkLabel) . '</a>';
		}
		echo '</div>';
	}

	public static function quickActionsOpen(): void
	{
		echo '<div class="bec-admin-quick-actions">';
	}

	public static function quickActionsClose(): void
	{
		echo '</div>';
	}

	public static function quickAction(string $url, string $label, string $description = ''): void
	{
		echo '<a class="bec-admin-quick-action" href="' . \esc_url($url) . '">';
		echo '<span class="bec-admin-quick-action__label">' . \esc_html($label) . '</span>';
		if ($description !== '') {
			echo '<span class="bec-admin-quick-action__desc">' . \esc_html($description) . '</span>';
		}
		echo '</a>';
	}

	public static function inlineNotice(string $message, string $variant = 'info'): void
	{
		$class = 'bec-admin-notice-inline';
		if ($variant === 'warning') {
			$class .= ' bec-admin-notice-inline--warning';
		}

		echo '<div class="' . \esc_attr($class) . '">' . \esc_html($message) . '</div>';
	}

	public static function tabsNavOpen(string $ariaLabel = ''): void
	{
		$label = $ariaLabel !== '' ? $ariaLabel : \__('Secondary menu', 'booking-engine-connector');

		echo '<nav class="nav-tab-wrapper bec-admin-tabs" aria-label="' . \esc_attr($label) . '">';
	}

	public static function tabLink(string $href, string $label, bool $active): void
	{
		$class = 'nav-tab';
		if ($active) {
			$class .= ' nav-tab-active';
		}

		echo '<a class="' . \esc_attr($class) . '" href="' . \esc_url($href) . '">' . \esc_html($label) . '</a>';
	}

	public static function tabsNavClose(): void
	{
		echo '</nav>';
	}

	public static function tabPanelOpen(string $panelId, bool $active): void
	{
		echo '<div id="' . \esc_attr($panelId) . '" class="bec-admin-tab-panel" role="tabpanel"';
		if (! $active) {
			echo ' hidden';
		}
		echo '>';
	}

	public static function tabPanelClose(): void
	{
		echo '</div>';
	}

	public static function renderSavedNotice(): void
	{
		if (! isset($_GET['bec_saved'])) {
			return;
		}

		if ((string) \sanitize_text_field(\wp_unslash((string) $_GET['bec_saved'])) !== '1') {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__(
			'Settings saved.',
			'booking-engine-connector'
		) . '</p></div>';
	}
}
