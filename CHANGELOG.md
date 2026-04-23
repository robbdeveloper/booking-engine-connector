# Changelog

## Unreleased

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
