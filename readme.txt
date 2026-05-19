=== Booking Engine Connector ===
Contributors: robbdev
Tags: booking, kross, hospitality, availability
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.28
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

= How do I submit `[bec_search]` from one page to another? =

Use the **`redirect_url`** attribute so the form posts (GET) to your results page, e.g. `[bec_search redirect_url="/availability-results/"]`. The same **`bec_*`** query parameters are appended. If **`redirect_url`** is omitted, submissions go to the **units archive** (or **`home_url`** if the archive link is unavailable).

== Changelog ==

= 0.1.28 =
* **Units (core fields)**: Sync Kross **CIN** (Italian national identification code) into **`bec_core_cin`**; editable in the unit admin meta box.
* **Shortcodes (`[bec_quote]`)**: Configurable currency/number formatting (`currency_display`, `currency_position`, `decimals`, separators, `number_style`) via new **`MoneyFormatter`**.
* **Shortcodes (`[bec_dates]`)**: Configurable date range display (`date_format`, `preset`, `label_style`, optional `label`) via new **`DateFormatter`**; default remains ISO dates with an arrow.
* **Shortcodes (`[bec_unit_field]`)**: Output a scalar from the synced unit **`raw`** payload using a dot path (e.g. `field="cin"`, `field="custom_fields.custom_1.it"`); `type` is `string` (default) or `number`. Provider API **`getUnitFieldValue()`**; filters **`bec_unit_field_value`**, **`bec_kross_unit_field_value`**.
* **i18n**: POT / Italian updates for CIN and date-format strings.

= 0.1.27 =
* Version bump to 0.1.27.

= 0.1.26 =
* **Shortcodes**: **`[bec_search]`** accepts optional **`redirect_url`**; default target is the unit archive so homepage searches can land on a dedicated results page.

= 0.1.25 =
* **Frontend — Elementor (`PublicAssets`)**: Enqueue BEC CSS/JS when shortcodes live in **`_elementor_data`** or embedded **Library** templates (`template_id` / `import_template_id`), including Elementor Pro **Theme Builder** documents. Hooks **`wp_enqueue_scripts` (priority 20)** and **`elementor/frontend/before_enqueue_scripts`**; filters **`bec_public_assets_probe_post_ids`**, **`bec_elementor_theme_builder_locations_to_scan`**.

= 0.1.24 =
* **Frontend — public assets (`PublicAssets`)**: Enqueue search/booking-summary CSS and JS when any tracked BEC shortcode is present — including **`core/block`** reusable block refs, nested block markup, and common **widget** option blobs (`widget_block`, `widget_text`, `widget_custom_html`). New filter **`bec_shortcodes_requiring_public_assets`** extends the shortcode list.

= 0.1.23 =
* **Admin — Unit gallery**: On the **bec_unit** edit screen, **Gallery** (meta **bec_core_gallery**) shows **thumbnails only**; click opens the **Media Library** modal for that attachment (e.g. alt text). Meta stays a JSON array of attachment IDs; **`assets/admin-unit-gallery.js`** and **`admin-unit-gallery.css`** load on unit edit screens only.
* **i18n**: POT / Italian updates for the unit gallery UI strings.

= 0.1.22 =
* **Sync — scalable manual run**: Admin **Run sync now** uses short **`bec_sync_start_all`** / **`bec_sync_step_all`** requests with durable **`SyncManualBatchState`**, refreshable **`SyncLock`** (cron `c` vs manual `m:user:run`), and **stale-lock recovery** so interrupted runs do not block new ones indefinitely. **`RemoteGalleryImporter`** supports **batched, resumable** gallery imports with **atomic finalize**; manual unit sync can **defer** remote gallery downloads until after metadata is saved. Legacy **`bec_sync_run_all`** remains for compatibility.
* **Admin — Sync**: **Clear sync lock** control on the Sync settings screen (nonce + confirm) calls **`SyncLock::forceReleaseAll()`** for emergencies; optional **`bec_sync_allow_admin_clear_lock`** filter.
* **i18n**: New POT / Italian strings for batch sync, lock troubleshooting, and gallery-step messaging.

= 0.1.21 =
* **Sync**: Manual AJAX sync raises runtime limits where supported and returns a structured JSON error for unexpected failures instead of only the generic browser response message.
* **Gallery import**: Downloads missing unit images in per-unit batches, preserves gallery order, and avoids `.tmp` sideload names by preferring the remote image extension or detected MIME type.
* **Kross images**: More tolerant image URL extraction for alternate payload fields such as `images_full`, `gallery`, `photos`, `image_url`, `full_url`, `src`, and nested `urls`.

= 0.1.20 =
* **Unit categories**: Optional hierarchical taxonomy **`bec_unit_category`** — enable + URL slug on **Booking Engine ▸ Units — permalinks** (defaults off on existing installs); localized labels from synced **`names`** term meta and **`single_term_title`** on archives when enabled.
* **Sync**: **`UnitCategorySync`** assigns terms after **`bec_after_unit_sync`** using **`bec_sync_unit_category`**; term meta stores provider slug, external ID, JSON names, normalized descriptor snapshot, last sync time.
* **Kross**: Fetches **`/v5/rooms/get-room-types-categories`** when categories are enabled; enriches room-type rows with **`unit_category`** from **`id_room_type_category`** (`bec_kross_room_type_categories_payload`, **`bec_kross_room_type_categories`**, **`bec_kross_room_type_category_from_row`**).
* **Rewrites**: One-time flush runs at **`init`** priority **100** (**`UnitPostType::maybeFlushRewrites`**) so rules include both units and unit categories after registration.

= 0.1.19 =
* **i18n**: Plugin `languages/` with POT template plus Italian **`it_IT`** PO/MO; maintainer workflow in **`languages/README.txt`**.
* **Frontend**: Expanded **`becSearchForm`** strings (datepicker labels, separators, placeholders); Moment locale tied to **`determine_locale`** with map + **`bec_moment_locale`** filter; **`public-search-daterange.js`** consumes localized config.
* **Admin sync**: Localized **`admin-sync-progress.js`** error and result summary strings.
* **Quotes & fallback**: User-facing **`QuoteService`** provider errors generic + translatable (details in **`WP_Error`** data); fallback link text defaults empty with gettext **`Contact us`** when unset (see **`CHANGELOG.md`**).

= 0.1.18 =
* **Styling pipeline**: Plugin default `:root` tokens enqueue on `bec-public`; shared theme variables and Extra CSS enqueue **after** search + booking-summary presets (`bec-styling-overrides`) so overrides win (see `CHANGELOG.md`).
* **Admin — Styling**: Short semantic design-token block; legacy full-default fingerprints normalize in the textarea; plain saves apply on `:root` in late CSS for **portaled** pickers; optional CodeMirror for CSS fields.
* **UI**: Booking summary default preset — incomplete/stale-quote states, mobile bar/drawer, embedded search/readouts; enhanced search — mobile sheet transitions, `closeAll` can keep the date range; primary buttons/radii follow `--bec-color-primary` / `--bec-radius-*` tokens (`assets/styling/*.css`, `public-search*.js`).
* **Admin — units**: Duplicate `bec_sync_payload` debug strip removed from **Unit — core fields** (use **Booking engine — synced data**).

= 0.1.17 =
* **Enhanced search (mobile)**: Date range picker uses a bottom sheet with scrollable calendars and a pinned **Cancel / Apply** footer; **`bec-drp-is-open`** avoids the footer showing before the popup opens. See `CHANGELOG.md`.

= 0.1.16 =
* **Kross**: Admin UI to pick a booking engine, refresh engines from the API, and sync units for that engine; sync payloads use **`JsonExtensionFlags`**-backed encode options (`SyncPayloadEncoder`).
* **Admin sync**: Live progress and log while **Run full sync** runs (Ajax polling, `SyncProgressReporter`); gallery import reports into the same progress stream.
* **Maintainer**: GitHub **release** workflow restored; see `CHANGELOG.md` for full notes.

= 0.1.15 =
* Booking summary: **Check availability** enables when dates are applied (native bubbling `input`/`change` after daterange Apply; guest counts default when checking form completeness). See `CHANGELOG.md`.

= 0.1.14 =
* Enhanced search form: fix guest popover hidden behind the bar (`.bec-search-form__bar` no longer uses `overflow: hidden`, so the absolutely positioned guests panel is visible on desktop).

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
