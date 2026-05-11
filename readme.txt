=== Booking Engine Connector ===
Contributors: robbdev
Tags: booking, kross, hospitality, availability
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to external booking engines (Kross first) with structured sync, search context via URL parameters, checkout links, and contact fallback.

== Description ==

Booking Engine Connector (BEC) links your site to external reservation APIs in a modular way: provider abstraction, logging, WP-Cron sync, and shortcodes. See `docs/` for full specifications.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/booking-engine-connector/` or install the zip from the WordPress admin.
2. Activate the plugin through the **Plugins** screen.
3. Open **Booking Engine** in the admin menu and configure the connection (provider credentials).

== Frequently Asked Questions ==

= Which PHP and WordPress versions are supported? =

PHP 8.0+ and WordPress 6.4+ are required (see header above).

= Where is search context stored? =

Search context uses **GET query parameters** prefixed with `bec_` (e.g. check-in/out). See `docs/SEARCH-CONTEXT.md` when available.

== Changelog ==

= 0.1.13 =
* Fix GitHub updater visibility: Plugin Update Checker uses the **`Version`** header inside `booking-engine-connector.php` on the tagged commit (not only the Git tag name). Bump that header plus `BEC_VERSION` whenever you publish a release, or WordPress will not offer the update.

= 0.1.10 =
* GitHub release updates: bundled Plugin Update Checker (YahnisElsts), `Update URI` header, optional `BEC_GITHUB_UPDATER_TOKEN` for private repos; see `docs/RELEASES.md`.

= 0.1.8 =
* Booking summary shortcode `[bec_booking_summary]`, Kross service-line mapping in the view model, bedroom arrangements `[bec_unit_info key="bedroom_arrangements"]`, search guest-field modes (`SearchGuestFieldMode`, `bec_total_guests`), Connection admin overrides for guest input and child ages, and related public JS/CSS. See `CHANGELOG.md` for detail.

= 0.1.7 =
* Core unit fields, gallery import, sync payload hardening, Kross POST auth + API client, bulk quotes (`BulkQuoteProviderInterface`), shortcodes `[bec_unit_info]`, `[bec_unit_url]`, `[bec_version]`, Kross amenities grid (`amenities_grid`) with `AmenitiesAssets` and icon font packs. See `CHANGELOG.md` for the full list.

= 0.1.6 =
* Wave 6: Checkout URL service + booking CTA block, checkout & fallback admin page, fallback service/renderer, `assets/public.css`, shortcodes `[bec_search]`, `[bec_dates]`, `[bec_checkout]`, `[bec_quote]`, `[bec_fallback]`. `ProviderInterface::buildCheckoutUrl()` for Kross.

= 0.1.5 =
* Search form (GET `bec_*`), validation (`SearchValidator`), optional auto-form on single units + archive loop hooks; `QuoteService` with transient quote cache; helpers `bec_render_search_form` / `bec_get_unit_quote`. Filter `bec_unit_has_archive` for CPT archive.

= 0.1.4 =
* Unit sync service, WP-Cron schedule, admin Sync page, row/bulk sync actions, developer hooks.

= 0.1.3 =
* Kross API v5: token exchange, room types sync payload, calendar quote; JSON envelope client.

= 0.1.2 =
* Connection settings page (dynamic credentials per provider), save + verify token exchange.

= 0.1.1 =
* HTTP client: bearer auth, single 401 refresh, structured logging to DB; API Log admin screen.

= 0.1.0 =
* Initial scaffold: CPT units, HTTP client, provider contracts, Kross stubs, API log table migration, search context helper.
