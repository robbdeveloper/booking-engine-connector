# Changelog

## 0.1.30 — 2026-05-19

- Version bump to 0.1.30.
- **Fix — amenities labels (`u0027`)**: Repair corrupted labels on load (`AmenityItem::repairLabelString`); use `decodeMetaJson` in amenities grid renderer; pass raw values into `update_post_meta` during sync/admin so WordPress meta sanitize runs once (pre-encoded JSON was corrupted by `update_metadata()`’s `wp_unslash`). Keep JSON hex encoding in `SyncPayloadEncoder` for payloads containing quotes.
- **Shortcodes (`[bec_unit_gallery]`)**: Provider-independent JSON gallery from canonical **`bec_core_gallery`** (attachment IDs). Attributes **`unit_id`**, **`limit`** (default `6`; `0` = full gallery), **`offset`**, **`size`** (WP image size, default `large`), **`default`** (JSON fallback, default `[]`). Each item includes **`id`**, **`url`**, **`alt`**, **`width`**, **`height`**. **`UnitGalleryReader`** / **`UnitGalleryItemResolver`** / **`UnitGalleryPresenter`** slice before URL resolution and batch-prime attachment caches. Filters **`bec_unit_gallery_attachment_ids`**, **`bec_unit_gallery_items`**, **`bec_unit_gallery_json`**. Does not enqueue public BEC assets.
- **Elementor — dynamic tag (`Unit gallery`)**: Gallery-category tag under **Booking Engine** for Gallery / carousel widgets — reads the same **`bec_core_gallery`** meta with panel controls **Image limit** (default `6`, `0` = all), **Offset**, and optional **Unit ID** (empty = current `bec_unit` / loop item). Returns attachment rows for Elementor (`id` only). Filters **`bec_unit_gallery_elementor_rows`**, **`bec_unit_gallery_elementor_value`**. Requires Elementor (free or Pro).

## 0.1.29 — 2026-05-19

- Version bump to 0.1.29.
- **i18n**: Italian translation for `[bec_quote]` multi-rate “From %s” price label (`Da %s`).

## 0.1.28 — 2026-05-19

- Version bump to 0.1.28.
- **Units (core fields) — CIN**: Sync Kross `cin` into canonical **`bec_core_cin`** (`CoreUnitSemantic::CIN`), editable in the unit admin meta box. Also readable via `[bec_unit_field field="cin"]` when not mapped to core meta.
- **Shortcodes (`[bec_quote]`)**: Configurable price display via attributes **`currency_display`** (`code`|`symbol`), **`currency_position`** (`before`|`after`), **`decimals`**, **`decimal_sep`**, **`thousands_sep`**, and **`number_style`** (`locale`|`eu`|`us`). New **`MoneyFormatter`** (`includes/Formatting/MoneyFormatter.php`). Filters **`bec_money_format_defaults`**, **`bec_currency_symbols`**, **`bec_format_money`**. Multi-rate “from” line uses a single formatted price string.
- **Shortcodes (`[bec_dates]`)**: Configurable date range display via **`date_format`**, **`preset`** (`iso`|`short`|`medium`|`long`|`full`), **`label_style`** (`arrow`|`from_to`|`from_to_lower`), and optional literal **`label`**. New **`DateFormatter`** (`includes/Formatting/DateFormatter.php`). Filters **`bec_date_format_defaults`**, **`bec_date_format_presets`**, **`bec_date_range_label_styles`**, **`bec_format_date`**, **`bec_format_date_range`**, **`bec_shortcode_dates_text`**, **`bec_shortcode_dates_html`**. Existing **`bec_shortcode_dates_format`** override unchanged. Default output remains ISO dates with an arrow.
- **Shortcodes (`[bec_unit_field]`)**: Read a scalar from the synced unit payload (`bec_sync_payload` → provider `raw`) using a dot path under `raw`. Attributes **`field`** (required, e.g. `cin`, `custom_fields.custom_1.it`, `size_sqm`), **`type`** (`string` default, `number` for numeric fields), **`unit_id`**, **`default`**. Locale maps require an explicit locale segment (no auto fallback). Provider API: **`ProviderInterface::getUnitFieldValue()`**; Kross implementation in **`KrossUnitFieldResolver`**. Filters **`bec_unit_field_value`**, **`bec_kross_unit_field_value`**. Examples: `[bec_unit_field field="cin"]`, `[bec_unit_field field="custom_fields.custom_1.it"]`, `[bec_unit_field field="size_sqm" type="number"]`.
- **i18n**: POT / **`it_IT`** updates for CIN label and date-format strings.

## 0.1.27 — 2026-05-19

- Version bump to 0.1.27.

## 0.1.26 — 2026-05-18

- Version bump to 0.1.26.
- **Shortcodes (`ShortcodeRegistry`)**: **`[bec_search]`** supports optional **`redirect_url`**; when unset, the form **`action`** defaults to the **unit archive** (fallback **`home_url`**), so e.g. a homepage search can submit **`bec_*`** params to another page.

## 0.1.25 — 2026-05-22

- **Frontend — Elementor (`PublicAssets`)**: Detect BEC shortcodes stored in Elementor **`_elementor_data`** (JSON), recurse into embedded **Library** templates via **`template_id`** / **`import_template_id`**, and scan **Elementor Pro Theme Builder** templates resolved for standard locations (extend via **`bec_elementor_theme_builder_locations_to_scan`**). Registers **`elementor/frontend/before_enqueue_scripts`** (with **`wp_enqueue_scripts`** at priority 20) so the active Elementor document is known when needed. Filter **`bec_public_assets_probe_post_ids`** adds post IDs to scan for edge cases.

## 0.1.24 — 2026-05-21

- **Frontend — public assets (`PublicAssets`)**: Enqueue search/booking-summary CSS and JS when any tracked BEC shortcode is present — including **`core/block`** reusable block refs, nested block markup, and common **widget** option blobs (`widget_block`, `widget_text`, `widget_custom_html`). Covers **`bec_search`**, **`bec_booking_summary`**, **`bec_dates`**, **`bec_checkout`**, **`bec_quote`**, **`bec_fallback`**, **`bec_unit_info`** (not plain‑text `bec_version` or URL-only `bec_unit_url`). Filter **`bec_shortcodes_requiring_public_assets`** extends the list.

## 0.1.23 — 2026-05-20

- **Admin — Unit editor (`CoreUnitFieldRegistry`)**: **`bec_core_gallery`** on **`bec_unit`** uses a **thumbnail grid** only; click opens the **Media Library** attachment modal (metadata including alt text). Same JSON **attachment-ID** meta; **`assets/admin-unit-gallery.js`** / **`admin-unit-gallery.css`** enqueued only on unit edit screens.
- **i18n**: POT / **`it_IT`** updates for the unit gallery UI.

## 0.1.22 — 2026-05-19

- **Sync — scalable manual run**: **`wp_ajax_bec_sync_start_all`** / **`bec_sync_step_all`** replace a single long **`bec_sync_run_all`** round-trip. **`SyncManualBatchState`** (non-autoloaded options) holds remote rows, cursor, counters, deferred gallery queue, and importer resume blobs. **`SyncLock`** distinguishes **cron** (`c`) vs **manual** (`m:{user}:{run}`), **refreshes TTL** on each step, and **reclaims** same-user locks when batch state is missing or idle past **`bec_sync_manual_lock_abandon_seconds`** (default 30 minutes). **`RemoteGalleryImporter`**: worker state + **`importFromRemotePayloadResumable()`**; **`syncGalleryFull`** delegates to batched download/finalize. **`CoreUnitFieldRegistry`**: **`deferGallery`** keeps existing **`bec_core_gallery`** until deferred imports finish. **`SyncService`**: **`normalizeRemoteUnitRows()`**, **`upsertRemoteRowForManualBatch()`**, **`syncAll($progress, $manualRunId)`**; cron still uses **`acquireCron()`**. **`assets/admin-sync-progress.js`**: start → step loop + progress poll. Filters **`bec_sync_manual_may_preempt_cron_lock`**, **`bec_sync_manual_gallery_batch_size`**, **`bec_sync_manual_lock_abandon_seconds`**.
- **Admin — Sync lock control**: **Clear sync lock** form on the Sync settings page (**`admin_post_bec_sync_clear_running_lock`**) with confirm dialog; **`SyncLock::forceReleaseAll()`**. Filter **`bec_sync_allow_admin_clear_lock`** (default true).
- **i18n**: POT / **`it_IT`** updates for the new admin and sync strings.

## 0.1.21 — 2026-05-18

- **Sync — manual AJAX run**: Raises the admin sync request memory/time allowance, keeps the run alive after client disconnects when supported, and returns a structured JSON failure (`Sync failed unexpectedly: …`) instead of falling through to the generic browser “unexpected response” message.
- **Gallery import**: Missing gallery images are downloaded in per-unit batches, then attached back in gallery order; image file names now prefer the remote URL extension or detected image MIME instead of a temporary `.tmp` extension that WordPress can reject during sideload.
- **Kross image mapping**: Accepts additional image payload shapes such as `images_full`, `room_type_images`, `gallery`, `photos`, string URLs, common URL fields (`image_url`, `full_url`, `src`), and nested `urls` objects.
- **Translations**: Updated POT / Italian PO/MO for the new sync failure message.

## 0.1.20 — 2026-05-19

- **Unit categories (`bec_unit_category`)**: Optional hierarchical taxonomy for synced inventory categories — **`UnitCategoryTaxonomy`** registers after units with configurable public slug (default **`unit-category`**), **`bec_unit_category_enabled`** / **`bec_unit_category_permalink_slug`** options, filters **`bec_unit_category_enabled`**, **`bec_unit_category_rewrite_slug`**, **`bec_unit_category_taxonomy_args`**. Disabled installs skip public rewrites and taxonomy UI while keeping stored terms (activation defaults categories **off**).
- **Admin — Units (`UnitPermalinkPage`)**: Checkbox + slug field alongside existing unit permalink settings; saves nonce-checked options and **`flush_rewrite_rules(false)`**.
- **Sync (`UnitCategorySync`)**: Hooks **`bec_after_unit_sync`**; filter **`bec_sync_unit_category`**; finds/creates term by **`bec_provider_slug`** + **`bec_external_id`** meta; stable initial slug **`{name}-{external_id}`** without changing slug after creation; **`wp_set_object_terms`** assigns one category per unit when a descriptor exists; no-op when disabled or missing data (existing assignments preserved).
- **Term meta / labels**: **`bec_category_names`** (localized map JSON), **`bec_category_normalized`**, **`bec_last_sync_at`**; **`resolveLocalizedLabelForTerm()`** and related helpers match Kross-style locale fallbacks; **`single_term_title`** uses the resolved label when the feature is enabled.
- **Kross (`KrossProvider`)**: When categories are enabled, calls **`get-room-types-categories`** (JSON body POST as with other logical GET v5 endpoints); filters **`bec_kross_room_type_categories_payload`**, **`bec_kross_room_type_categories`**, **`bec_kross_room_type_category_from_row`**; normalizes **`id_room_type_category`** / **`name_room_type_category`** / **`names`** and attaches **`unit_category`** on each normalized room-type row (sync payload / inspector include it).
- **Rewrites (`UnitPostType`)**: **`maybeFlushRewrites()`** on **`init` priority 100** replaces flushing inside **`onInit` (5)** so **`bec_needs_rewrite_flush`** runs after taxonomy registration (**priority 6**), keeping unit + category rules in sync.

## 0.1.19 — 2026-05-18

- **Internationalization — catalogs (`languages/`)**: Added **`languages/booking-engine-connector.pot`**, **`booking-engine-connector-it_IT.po`** / **`.mo`**, and **`languages/README.txt`** (how to regenerate the POT with WP‑CLI, merge POs with `msgmerge`, compile MOs).
- **Frontend — search & date picker (`PublicAssets`, `public-search-daterange.js`)**: Extended **`becSearchForm`** localization with **`customRangeLabel`**, **`dateRangeSeparator`**, gettext **`datePlaceholder`**, clearer **`/* translators: */`** notes on guest-count strings; daterangepicker reads those keys (English fallbacks only if stripped). Moment locale resolves via **`determine_locale()`** (fallback **`get_locale()`**), expanded WordPress locale → Moment slug map (e.g. **`it_IT`** → **`it`**), optional **`bec_moment_locale`** filter.
- **Admin — Sync UI (`SyncAdmin`, `admin-sync-progress.js`)**: **`wp_localize_script`** supplies translated strings for generic sync failure, the **created / updated / skipped** summary (PHP `sprintf`-style placeholders rendered in JS), and the unexpected-response network error message.
- **Quotes (`QuoteService`)**: Provider failures expose a **generic translatable user message** instead of raw API text; **`technical`** retains the exception message on the `WP_Error` for filters/logs.
- **Fallback (`Activator`, `FallbackRenderer`, `FallbackPage`)**: Default **fallback link text** is stored empty on new installs; empty means **`Contact us`** through gettext at render time. Fallback settings screen explains leaving the link text blank for locale-aware wording.

## 0.1.18 — 2026-05-17

- **Frontend — styling cascade (`PublicAssets`)**: Default **`--bec-*`** values output early as `:root { … }` on **`bec-public`**. Shared theme variables (“Design system”), search extra CSS, and summary extra CSS output **after** the search preset + booking-summary preset styles via a synthetic **`bec-styling-overrides`** handle with proper dependencies, so preset rules no longer mute admin/user tokens.
- **Admin — Booking Engine ▸ Styling (`StylingSettings`, `StylingPage`)**: Prefills a **short semantic token** block (`--bec-font-family`, core `--bec-color-*` including neutrals/overlays/status, `--bec-radius-*`). **`getInternalThemeAliasesInner()`** supplies the longer implementation map (preset compatibility, DRP aliases, panels, booking-summary aliases); **`bec_styling_admin_theme_variables_inner`** and **`bec_styling_default_theme_variables_inner`** filters still customize admin vs merged defaults respectively. Saves that **fingerprint-match** the historic full bundled blob are normalized to the short admin view and skip redundant duplicate injection. Plain textarea lines (**no** outer selectors/`{`) are emitted as **`:root { … }`** in late CSS so **`body`‑portaled** daterangepicker and overlays inherit overridden `--bec-*` values. **`assets/admin-styling.js`** / **`assets/admin-styling.css`**: optional **`wp.codeEditor`** (CSS) for the three code fields when available.
- **Preset CSS (`search-form-enhanced.css`, `booking-summary-default.css`, `public.css`)**: **Primary** actions (guest/calendar footer, booking-summary CTAs, daterangepicker Apply) use **`--bec-color-accent`** / **`--bec-color-accent-text`** (primary + contrast) instead of text/“range edge” grays. **Corner radii** tie **buttons** and small controls to **`--bec-radius-control`** (`--bec-radius-ui`, `--bec-radius-input`); **popover chrome** uses **`--bec-radius-panel`** (`--bec-drp-popover-radius`, panel radii). Classic submit uses **`--bec-radius-ui`**. Wide refresh of **default booking-summary** layout (desktop + mobile bar/drawer, embedded search triggers, readouts, loading, accordions) and **enhanced search** bar/panel polish.
- **Search — JS (`public-search-daterange.js`, `public-search.js`)**: Mobile daterangepicker **sheet enter/exit** animation; improved **panel closing** when switching controls. **`closeAll`** gains an option to **keep the selected date range** while dismissing popovers.
- **Booking summary — PHP/CSS (`BookingSummaryRenderer.php`)**: Handles **stale quote** UI and refined **incomplete** flow/markup aligned with mobile bottom bar + slide-in drawer work.
- **Admin — Unit — core fields (`CoreUnitFieldRegistry`)**: Drops the duplicated **raw `bec_sync_payload` JSON** block under the field table so editors rely on **Booking engine — synced data** for that inspection.

## 0.1.17 — 2026-05-14

- **Search (enhanced preset) — mobile date range picker**: Bottom sheet layout (`max-width: 639px`) with a scrollable middle region (`.bec-drp-scroll` wrapping both calendar panes) so long two-month views scroll while **Cancel / Apply** and the selected range stay pinned at the bottom. Desktop layout is unchanged: the wrapper uses `display: contents` so the vendor two-column calendar floats still apply.
- **Search (enhanced preset) — daterangepicker visibility**: Mobile styles used `display: flex` on `.daterangepicker`, which overrode the library’s `display: none` until jQuery set an inline `display` after the first close, so the footer could appear at page load. The picker container now gets **`bec-drp-is-open`** only while the popup is shown (`assets/public-search-daterange.js`); sheet rules are scoped to `.daterangepicker.bec-drp-is-open` with `display: flex !important` when open.

## 0.1.16 — 2026-05-14

- **Kross — booking engines (admin)**: Select which remote booking engine to sync against, refresh the list of engines from the API, and fetch room types for that engine. New persisted settings (`KrossBookingEngineSyncSettings`), provider wiring (`KrossProvider`), and unit behaviour (`UnitPostType`) for engine-scoped sync. **`SyncPayloadEncoder`** gains consistent JSON encode/decode options via **`JsonExtensionFlags`** (safe resolution / fallbacks when platform JSON constants vary).
- **Admin — manual sync progress**: **`SyncProgressReporter`** records phase and log lines per user; **`SyncService`** and **`RemoteGalleryImporter`** emit progress during a run. The sync screen polls via **`wp_ajax_bec_sync_progress_poll`** and **`assets/admin-sync-progress.js`** shows status and a rolling log while **Run full sync** executes.
- **Maintainer**: Restore **`.github/workflows/release.yml`** for GitHub release zip builds. **`.gitignore`** adds local paths (`docs`, `user-docs`, `CLAUDE.md`) so working copies stay clean.

## 0.1.15 — 2026-05-13

- **Booking summary (incomplete / check availability)**: The footer **Check availability** control listens on the summary root with native `input`/`change` handlers. Enhanced search’s daterange Apply only used jQuery `.trigger('change')` on the hidden date fields, which did not bubble to those listeners, so the button stayed disabled until a guest field fired a native event. `assets/public-search-daterange.js` now dispatches bubbling native `input` and `change` after updating `bec_checkin` / `bec_checkout`. **`public-booking-summary.js`** treats blank or invalid occupancy like the rest of the stack (default at least one adult / guest) when deciding if the search is complete.

## 0.1.14 — 2026-05-12

- **Search (enhanced preset)**: Guest popover was clipped by `.bec-search-form__bar { overflow: hidden; }` while the panel is absolutely positioned below the control. The bar now uses `overflow: visible` so the popover can display (date range still uses `parentEl: 'body'`).

## 0.1.13 — 2026-05-11

- **GitHub updater / versioning**: Plugin Update Checker fetches `booking-engine-connector.php` from the release ref and **`Version:` in that file overrides the tag** for comparisons. Releases whose tag says e.g. `v0.1.12` but whose header remains `0.1.10` will **not** show an update on sites running `0.1.10`. This release aligns the semantic version metadata after that pitfall (`docs/RELEASES.md`).

## 0.1.10 — 2026-05-11

- **Updates from GitHub releases**: `Update URI` plugin header, vendored **Plugin Update Checker** (checks public `robbdeveloper/booking-engine-connector` releases). WordPress shows update notices when a newer release tag exists and the release includes a matching `booking-engine-connector-{version}.zip` asset. Optional `BEC_GITHUB_UPDATER_TOKEN` in `wp-config.php` for private repo access. Maintainer workflow: [**docs/RELEASES.md**](docs/RELEASES.md). GitHub Actions **Release** workflow builds and uploads the zip on `v*` tags.

## 0.1.8 — 2026-04-24

- **Search (guest field mode)**: `SearchGuestFieldMode` (`breakdown` vs `total`) via `ProviderInterface::getSearchGuestFieldMode()` (Kross uses **total** / single pax). `SearchContext` supports `bec_total_guests` (round-trip with `toQueryArgs()`), optional `bec_rate_id`, and child ages; `toProviderSearchContext()` passes total `guests` and `rate_id` for quotes. Filter `bec_search_guest_field_mode`. **`SearchSettings`** — options `bec_search_guest_input_mode` (follow provider / force total / force breakdown) and `bec_search_child_ages_mode` (follow provider / always / never collect ages), wired through **Connection** admin (`ConnectionPage`) and filters `bec_provider_requires_children_ages`. `SearchForm` + `assets/public-search.js` updated for the two UX modes and guest-oriented strings (`bec_search_form_js_l10n`).
- **Booking summary service lines (Kross)**: v5 `calendar/book` rows expose `services_mandatory` and `services_included` (not `mandatory_services` from sync); line totals use `price_service` with `price_for_night`, `srv_nights`, `price_for_person`, and guest counts. Legacy `amount` on service rows is used as a final value. `name_service_t` / `name_service` labels. Rate labels: `name_rate.main` in `CanonicalQuote::pickLocalizedLabel()`.
- **Shortcode `[bec_booking_summary]`**: self-contained unit-page booking summary with search, optional multi-rate selection (`bec_rate_id`), Kross-specific mapping of `be_small_text` / `be_conditions` / `adv_*` / mandatory services, generic provider fallback, checkout via `CheckoutUrlService`, and enquiry link from fallback settings. Desktop card + mobile bottom bar and slide-in panel (`assets/public-booking-summary.js`, styles in `assets/public.css` under `.bec-booking-summary`). `PublicAssets` enqueues the booking-summary script and loads public assets when the post contains this shortcode. View model / filters: `bec_booking_summary_view_model`, `bec_booking_summary_html`, `bec_kross_booking_summary_view_model`, `bec_generic_booking_summary_view_model`, and related `bec_booking_summary_*` hooks.
- **Kross unit info: bedroom arrangements**: `[bec_unit_info key="bedroom_arrangements"]` — grid of per-room bed counts and labels from `raw.bedroom_details` in `bec_sync_payload` (Kross `with_bed_bath_details`); bed icons use the amenities font (`icon-{key}`) with a default map for Kross bed codes (e.g. `double_bed` → `queen_bed`). Label resolution: `raw.amenities` `name_amenity_translations` when `cod_amenity` matches, then gettext fallbacks, then `bec_kross_bedroom_label` / `bec_kross_bedroom_bed_map`. Pass-through: `font_pack`, `columns`, `title`, `show_title`. **`AmenitiesAssets::enqueueForKrossBedroomArrangements()`** + `assets/public-bedrooms-kross.css` (preloads when post content includes the shortcode, alongside the amenities flow). Dev doc: `docs/dev/UNIT-INFO-SHORTCODES.md`.

## 0.1.7 — 2026-04-23

- **Sync payload (`bec_sync_payload`)**: `SyncPayloadEncoder` normalises non-finite floats / edge cases so `wp_json_encode` never fails silently; payload is always written after sync. `sanitizeSyncPayload` no longer clears meta when `json_decode` fails (e.g. depth); invalid UTF-8 handled with `JSON_INVALID_UTF8_SUBSTITUTE` where supported.
- **Units (core fields)**: `CoreUnitSemantic`, `bec_core_*` meta, `ProviderInterface::extractCoreUnitFields()`, `KrossCoreUnitFields`, modular amenities (`AmenityItem`, `bec_provider_amenities_from_row`, `bec_kross_amenities_from_raw`). Admin meta box **Unit — core fields (canonical)**. Docs: `docs/CORE-UNIT-FIELDS.md`.
- **Gallery**: `CoreUnitSemantic::GALLERY` → `bec_core_gallery` (JSON attachment IDs). `RemoteGalleryImporter` sideloads remote URLs on sync (`_bec_source_url` dedupe), optional featured image from Kross main/first image. **`bec_sync_gallery_source_hash`** skips work when the ordered URL list is unchanged. Parallel **curl_multi** batches (filter `bec_gallery_download_concurrency`, default 8) for new URLs; one meta query maps known URLs to attachments. Filters `bec_sync_import_gallery_images`, `bec_sync_gallery_ignore_hash`, `bec_core_unit_gallery_before_save`, `bec_core_unit_gallery_remote_urls`.
- **Kross (`get-room-types` flags)**: Default request payload includes `with_images_full`, `with_amenities`, `with_mandatory_services`, `with_bed_bath_details`, `with_damage_deposit`, and related flags. **`KrossAmenitiesExtractor`** maps only **`amenities[]`** into `bec_core_amenities`; `mandatory_services` is not stored in that meta (it remains in `bec_sync_payload` / `raw`). `AmenityItem` supports optional `category`.
- **Units (title)**: Sync sets `post_title` from the canonical name (`bec_core_name` pipeline: `extractCoreUnitFields` → `bec_core_unit_fields` → `bec_sync_unit_title`). Duplicate names reuse the same title; WordPress uniquifies the slug.
- **Units (content)**: Sync sets `post_content` from the canonical description (`bec_core_description` pipeline + `bec_sync_unit_content`, then `wp_kses_post`).
- **Units (mapped fields)**: `ProviderInterface::getUnitSyncFieldDefinitions()` + `UnitSyncFieldRegistry` — optional extra meta per provider/client; Kross default list empty. Filters `bec_unit_sync_provider_slugs`, `bec_unit_sync_field_definitions`, `bec_sync_apply_mapped_unit_fields`. Admin meta box **Booking engine — unit fields**.
- **Units (admin)**: Each unit edit screen shows a **Booking engine — synced data** meta box (Classic Editor and block editor) with core `bec_*` meta and a pretty-printed JSON snapshot of the last remote row (`bec_sync_payload`), populated on each successful sync.
- **Kross auth**: `exchangeToken` uses **POST** for `/v5/auth/get-token` with the same JSON body. WordPress’s HTTP stack cannot attach a JSON string body to GET (it expects an array for query encoding), which caused `TypeError: http_build_query(): Argument #1 ($data) must be of type array, string given` when verifying the connection.
- **Kross API client**: Logical `GET` calls that use the JSON envelope (`auth_token` + `data`) are sent as **POST** for the same reason; fixes the same `http_build_query` fatal on sync (`get-room-types`, `calendar/book`, etc.).
- **Quotes (bulk)**: `BulkQuoteProviderInterface` — providers that implement it fetch one batch response per search context and derive per-unit quotes locally. `KrossProvider` implements bulk `calendar/book` caching (`getBulkQuoteCacheKey`, `fetchBulkQuotes`, `quoteFromBulk`); `QuoteService::getQuote()` uses this path when available (better behaviour on unit archives / many units with the same dates).
- **Shortcodes**: `[bec_unit_info]` — provider-specific unit output from `bec_sync_payload` via `ProviderInterface::getUnitInfoRenderers()`; resolves the unit from the loop or `unit_id`, uses `bec_provider_slug`, passes two-letter `locale` and extra attributes to renderers; filters `bec_unit_info_renderers`, `bec_unit_info_output`, `bec_kross_unit_info_renderers`. `[bec_unit_url]` — unit permalink with search context. `[bec_version]` — plugin version. Dev doc: `docs/dev/UNIT-INFO-SHORTCODES.md`.
- **Kross unit info: amenities grid**: `[bec_unit_info key="amenities_grid"]` — responsive grid of icon (`icon-{amenity_key}` from the amenities icon font) + localized label from `bec_core_amenities`, with fallback rebuilt from the sync row. Pass-through attributes: `font_pack`, `columns`, `limit`, `category`. **`AmenitiesAssets`** registers and enqueues `assets/public-amenities-kross.css` and the selected pack (default `assets/fonts/amenities/font-1/style.css`); filters `bec_amenities_font_packs`, `bec_kross_amenities_default_font_pack`, `bec_enqueue_kross_amenities_assets`; preloads on singular `bec_unit`, unit archive, or when post content includes the shortcode. The grid **omits** legacy mandatory-service rows still present in older `bec_core_amenities` JSON (`category: mandatory_service` or keys prefixed `mandatory_`) until the next sync overwrites meta.

## 0.1.6 — 2026-03-20

- **Wave 6 (CHK / FB / UI / SHO)**: `CheckoutUrlService`, `BookingCtaRenderer`, `PublicContentBlocks` (append CTA or fallback after unit content when GET search is complete), `PublicAssets` + `assets/public.css`. `ProviderInterface::buildCheckoutUrl()` + `KrossProvider` (base URL option + filters). `FallbackService` / `FallbackRenderer`, admin **Checkout & fallback** (`bec_kross_checkout_base_url`, modes, triggers by `ProviderErrorCategory`, empty-quote trigger). Shortcodes: `bec_search`, `bec_dates`, `bec_checkout`, `bec_quote`, `bec_fallback`. Uninstall options extended.

## 0.1.5 — 2026-03-20

- **SEA-002 / SEA-003**: `SearchForm` + `SearchValidator` (dates, min/max nights via `bec_search_min_nights` / `bec_search_max_nights`); `SearchTemplateHooks` — `bec_before_search_form` / `bec_after_search_form`, `bec_before_unit_archive_loop` / `bec_after_unit_archive_loop`, optional `bec_auto_append_search_form_on_single_unit` (default true); `QuoteService::getQuote()` with `bec_quote_cache_ttl` and filters `bec_quote_search_context`, `bec_quote_result`, `bec_quote_cached_result`, `bec_quote_provider_error`. Template helpers `bec_render_search_form()`, `bec_get_unit_quote()`. CPT: `has_archive` via filter `bec_unit_has_archive`.

## 0.1.4 — 2026-03-20

- **SYNC (E3)**: `SyncService` (full + single post), `SyncLock`, `SyncCron` (WP-Cron `bec_run_scheduled_sync`, custom interval), admin **Sync** page (schedule + run now), row action & bulk “Sync with provider”, hooks `bec_before_unit_sync` / `bec_after_unit_sync`, filters for remote row and post data. `docs/SYNC.md`.

## 0.1.3 — 2026-03-20

- **PROV-003 (Kross v5)**: `KrossAuthenticator` aligned to GET `…/auth/get-token` + JSON body (`data.auth_token`); username/password credentials; `KrossApiClient` envelope (`auth_token` + `data`); `KrossProvider::fetchRemoteUnits()` → `get-room-types`; `getQuoteForUnit()` → `calendar/book`; `KrossResponseParser` for nested/JSON-in-string values.
- Docs: `docs/KROSS-API.md` mapping table updated.

## 0.1.2 — 2026-03-20

- **AUTH-003**: Connection admin page — provider select (`bec_registered_providers`), dynamic credential fields, save + **Verify connection**; filter `bec_test_connection` for custom providers (`WP_Error` or success string).
- **KrossAuthenticator**: shared `exchangeToken()` + `probeTokenExchange()` (test does not invalidate cached token on failure).

## 0.1.1 — 2026-03-20

- **HTTP-002**: `HttpClient` accepts optional `AuthenticatorInterface`; adds `Authorization: Bearer` when not skipped; at most **one** 401/403 invalidate + retry per logical request; internal args `bec_skip_auth`, `bec_log_profile`, `bec_provider_slug`, `bec_unit_id` (stripped before `wp_remote_request`).
- **LOG-002 / LOG-003 (minimal)**: `ApiLogRepository` persists business calls; auth/token calls omitted unless filter `bec_log_auth_requests` is true; admin **API Log** page with provider + status filters.

## 0.1.0 — 2026-03-20

- Scaffold plugin bootstrap, PSR-4 autoload, admin menu shell.
- CPT `bec_unit` with `bec_*` meta and admin columns.
- Search context helper (`bec_*` GET parameters).
- HTTP client with 429 backoff + correlation id.
- Provider contracts, Kross authenticator/provider stubs, provider registry.
- DB migration for `bec_api_log` table; uninstall policy documented.
