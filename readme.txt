=== Booking Engine Connector ===
Contributors: robbdev
Tags: booking, kross, hospitality, availability
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.2.3
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

Use **`popover_placement`** to control where the date and guest popovers open relative to the search field on desktop/tablet: **`auto`** (default — opens below and flips above when there is not enough space), **`top`**, or **`bottom`**. On mobile, popovers still use the bottom sheet layout. Example: `[bec_search popover_placement="top"]`. You can also pass **`popover_placement`** to **`SearchForm::render()`** or filter **`bec_search_form_popover_placement`**.

Use **`daterange_format`** (PHP date format) or **`daterange_preset`** (`iso`, `short`, `medium`, `long`, `full`; default **`medium`**) on **`[bec_search]`** and **`[bec_booking_summary]`** to format the selected dates shown in the calendar footer before Cancel/Apply. Example: `[bec_search daterange_preset="long"]`.

= How do I filter units on a listing page? =

Place **`[bec_unit_filters]`** above your unit loop (or on the unit archive). The form submits filter GET parameters (`bec_filter_order`, `bec_filter_rooms_min`, `bec_filter_bathrooms_min`, `bec_filter_amenities[]`) and keeps current search params. Pair with an Elementor Loop Grid using Query ID **`bec_available_only`** or **`bec_filtered_units`**. Choose which amenities appear under **Booking Engine → Listing Filters**; tune appearance under **Design → Unit filters**.

Shortcode attributes include **`layout`** (`inline` or `stacked`), **`show_reset`**, **`hide_labels`** (default `1`: labels hidden, filter name shown inside each control until a value is chosen; set `hide_labels="0"` to show labels above fields), **`filters`**, **`amenities`**, **`amenities_limit`**, and **`action`**.

= How do I show how many units match the current search? =

Use **`[bec_available_units_count]`** anywhere on the results page (above an Elementor Loop Grid, in a heading, or on the native unit archive). It counts published units that match the **current listing query** (e.g. unit category archive), **`bec_filter_*`** params and, when dates and guests are in the URL, units that are **available** for that search—the same rules as Loop Grid Query ID **`bec_available_only`** / **`bec_filtered_units`**.

Examples: **`[bec_available_units_count]`** (number only), **`[bec_available_units_count format="text"]`** (default “%d available units” copy), **`[bec_available_units_count hide_without_search="1"]`** (empty until search params are complete), **`[bec_available_units_count zero_text="No units found"]`**, **`[bec_available_units_count category="villas"]`** (count only units in that unit category term). Custom text: **`singular`** / **`plural`** with **`%d`**, optional **`class`** for styling.

== Changelog ==

= 0.2.3 =
* **Multilingual (WPML / Polylang)**: Auto-create and update linked translation posts and unit category terms from provider locale maps on sync (`MultilingualBridge`, `UnitTranslationSync`, `CategoryTranslationSync`). Toggle on **Frontend** settings. Kross supplies unit/category strings; language-prefixed permalinks for directory-based multilingual URLs.
* **Unit categories**: Registry sync deduplicates provider descriptors; category translation sync on `bec_after_category_sync`.
* **Fallback**: Sanitize and escape fallback link URLs on save and render.
* **WPML**: Shipped `wpml-config.xml` for custom types, taxonomies, and meta copy/translate rules.
* **i18n**: Regenerated translation template and recompiled Italian MO.

= 0.2.2 =
* **Shortcodes (`[bec_available_units_count]`)**: Count respects the current listing query (e.g. unit category archives) instead of always returning the site-wide total. Optional **`category`** attribute (term slug) scopes the count to a specific unit category.
* **i18n**: Regenerated translation template and recompiled Italian MO.

= 0.2.1 =
* **Units — permalinks (`UnitPermalinkRouter`)**: Fix 404s on unit category archive pagination, feeds, and embed endpoints for custom URL formats (`/{unit slug}/{term}`, `/{term}`).
* **Frontend — public assets (`PublicAssets`)**: Load shortcode CSS/JS wherever tracked shortcodes render — including Elementor Theme Builder taxonomy/archive templates — via runtime hooks (`do_shortcode_tag`, `elementor/frontend/before_get_builder_content`) and `ensureEnqueued()`. Pre-detection no longer treats taxonomy term IDs as post IDs; term descriptions are scanned for early enqueue.

= 0.2.0 =
* **i18n (Italian)**: Regenerate the translation template and complete Italian admin strings for the Dashboard, settings pages, and related wp-admin UI.

= 0.1.47 =
* **Booking summary (mobile)**: Portal the fixed bottom bar to `document.body` alongside the drawer/backdrop so the bar also escapes nested stacking contexts and z-index traps.

= 0.1.46 =
* **Booking summary (mobile)**: Portal the slide-in drawer and backdrop to `document.body` (same pattern as date/guest popovers) so fixed positioning and z-index stack correctly inside nested layouts. Configurable `--bec-bsummary-drawer-z-index` (default 10040, below popovers at 10050).

= 0.1.45 =
* **Unit filters UI (`[bec_unit_filters]`)**: Theme-safe button styling extended to generic picker controls; reset link hover no longer picks up a stray background from theme rules.

= 0.1.44 =
* **Unit filters UI (`[bec_unit_filters]`)**: Theme-safe button styling for the amenities picker and **Apply filters** submit — scoped CSS resets so global theme button rules no longer leak into the filter form.

= 0.1.43 =
* **Admin redesign**: New **Dashboard** with setup/health status cards and quick actions. Reordered menu: **Connection**, **Frontend** (search guest fields and single-unit content injection — moved from Connection), **Sync & Import**, **Units**, **Listing Filters**, **Design**, **Checkout & Fallback**, **Tools & Logs**. Shared admin layout/styles (`assets/admin.css`); sync handlers, form IDs, and AJAX contracts unchanged.

= 0.1.42 =
* **Units — permalinks**: Admin-selectable URL structures for single units and unit category archives while keeping existing slug fields. Validation blocks ambiguous combinations; WPML/Polylang language prefixes are supported.

= 0.1.41 =
* **Shortcodes (`[bec_quote]`)**: Add **`no-results`** class on the quote paragraph when there is no availability for the selected dates.

= 0.1.40 =
* **Units (core fields)**: New **`bec_core_city`** field synced from Kross **`city`**; editable in the unit admin meta box. City remains in **`bec_core_address_full`**.
* **Search — guest popover**: Fix guest picker not opening on some Elementor pages (duplicate **`[bec_search]`** embeds or late DOM). Forms init on DOM ready, re-scan on Elementor frontend init, unique **`form_id`** per shortcode render, scoped guest panel lookup.
* **i18n**: POT / Italian updates for the City field label.

= 0.1.39 =
* **Booking summary**: Align headline, search date, and rate list colors with booking-summary CSS tokens; selected rate name and price use the primary color.

= 0.1.38 =
* **Booking summary — mobile drawer**: Search, rates, accordions, and price breakdown scroll inside the slide-in panel when content exceeds the viewport; back header and enquiry/checkout actions stay fixed at top and bottom.

= 0.1.37 =
* **Search — mobile overlay**: Fix backdrop blur remaining after Apply on the guest drawer following date selection; shared overlay dismisses when any mobile drawer closes.
* **Search — date range placement**: Desktop calendar popover with **`popover_placement="auto"`** opens above or below based on viewport space; guest popover uses the same rule.

= 0.1.36 =
* **Search (`[bec_search]`)** and **Booking summary (`[bec_booking_summary]`)**: **`daterange_format`** and **`daterange_preset`** control the calendar footer date readout; default preset **`medium`**. New **`MomentFormatMapper`** and filter **`bec_search_form_daterange_format`**.
* **Search — date picker UI**: Mobile footer grid with centered selected dates and full-width Cancel/Apply buttons.
* **Booking summary**: Accordion chevron styling scoped to booking summary accordions.

= 0.1.35 =
* **Units (core fields)**: Derived **`bec_core_lat_lng`** stores latitude and longitude as a comma-separated pair (`lat,lng`); updated on sync and when core fields are saved. Read-only in the unit admin meta box.
* **Search (`[bec_search]`)**: **`popover_placement`** attribute (`auto`, `top`, `bottom`) controls where date and guest popovers open on desktop/tablet; filter **`bec_search_form_popover_placement`**.

= 0.1.34 =
* **Shortcodes (`[bec_unit_info key="amenities_grid"]`)**: New **`columns_mobile`** attribute (1–6, default `1`) for grid layout below 640px; desktop still uses **`columns`** (default `2`).
* **Shortcodes (`[bec_unit_info key="bedroom_arrangements"]`)**: Section title hidden by default; set **`show_title="1"`** to show it.
* **Shortcodes (`[bec_dates]`)**: Default display uses long date preset and “from … to …” label style (override with **`preset`** / **`label_style`**).
* **Shortcodes (`[bec_quote]`)**: Default price formatting uses currency symbol after the amount and EU number style; rate list hidden unless **`show_rates="always"`** or **`show_rates="auto"`**.
* **Booking summary**: Improved rate-select styling (background, spacing, typography).

= 0.1.33 =
* **Shortcodes (`[bec_available_units_count]`)**: Display the number of units matching current unit filters and (when search context is complete) provider availability—works on Elementor results pages, unit archives, and regular pages without relying on Loop Grid render order.
* **Unit listings**: Shared **`UnitListingAvailability`** helpers for Elementor availability filtering and the count shortcode (per-request caching).

= 0.1.32 =
* **Unit filters UI**: Amenities multi-select with chip trigger on desktop, compact trigger and bottom drawer on mobile; order, rooms, and bathrooms use the same popover pattern (no Clear on single-select pickers).
* **Assets**: New **`public-unit-filters.js`**; filter styles in **`public.css`** using global BEC styling tokens (hover/focus on buttons and field openers).
* **Amenities**: Search box removed from the amenities panel.

= 0.1.31 =
* **Shortcodes (`[bec_unit_filters]`)**: Filter units by sort order, rooms, bathrooms, and amenities via sharable GET URLs; preserves search context. Elementor Query IDs **`bec_available_only`** / **`bec_filtered_units`**; native unit archive support.
* **Admin**: **Unit filters** page to enable/order/relabel amenity checkboxes; amenity index taxonomy synced from **`bec_core_amenities`**.
* **Styling**: Unit filter design tokens and optional extra CSS on the Styling screen.
* **i18n**: Italian strings for the filter UI.

= 0.1.30 =
* **Fix — amenities labels (`u0027`)**: Repair corrupted amenity labels on load; safer JSON meta save during sync and unit admin (single WordPress meta sanitize pass).
* **Shortcodes (`[bec_unit_gallery]`)**: JSON array of gallery images from **`bec_core_gallery`** (`limit`, `offset`, `size`, `unit_id`, `default`). Filters **`bec_unit_gallery_attachment_ids`**, **`bec_unit_gallery_items`**, **`bec_unit_gallery_json`**.
* **Elementor**: Dynamic tag **Unit gallery** (group **Booking Engine**) for Gallery widgets — **Image limit**, **Offset**, optional **Unit ID**; filters **`bec_unit_gallery_elementor_rows`**, **`bec_unit_gallery_elementor_value`**.

= 0.1.29 =
* **i18n**: Italian translation for `[bec_quote]` multi-rate “From %s” price label.

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
