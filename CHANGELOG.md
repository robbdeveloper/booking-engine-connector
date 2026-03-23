# Changelog

## Unreleased

- **Sync payload (`bec_sync_payload`)**: `SyncPayloadEncoder` normalises non-finite floats / edge cases so `wp_json_encode` never fails silently; payload is always written after sync. `sanitizeSyncPayload` no longer clears meta when `json_decode` fails (e.g. depth); invalid UTF-8 handled with `JSON_INVALID_UTF8_SUBSTITUTE` where supported.
- **Units (core fields)**: `CoreUnitSemantic`, `bec_core_*` meta, `ProviderInterface::extractCoreUnitFields()`, `KrossCoreUnitFields`, modular amenities (`AmenityItem`, `bec_provider_amenities_from_row`, `bec_kross_amenities_from_raw`). Admin meta box **Unit — core fields (canonical)**. Docs: `docs/CORE-UNIT-FIELDS.md`.
- **Gallery**: `CoreUnitSemantic::GALLERY` → `bec_core_gallery` (JSON attachment IDs). `RemoteGalleryImporter` sideloads remote URLs on sync (`_bec_source_url` dedupe), optional featured image from Kross main/first image. **`bec_sync_gallery_source_hash`** skips work when the ordered URL list is unchanged. Parallel **curl_multi** batches (filter `bec_gallery_download_concurrency`, default 8) for new URLs; one meta query maps known URLs to attachments. Filters `bec_sync_import_gallery_images`, `bec_sync_gallery_ignore_hash`, `bec_core_unit_gallery_before_save`, `bec_core_unit_gallery_remote_urls`.
- **Kross**: `get-room-types` default payload includes `with_images_full`, `with_amenities`, `with_mandatory_services`, `with_bed_bath_details`, `with_damage_deposit`, and related flags. `KrossAmenitiesExtractor` maps `amenities` + `mandatory_services`; `AmenityItem` supports optional `category`.
- **Units (title)**: Sync sets `post_title` from the canonical name (`bec_core_name` pipeline: `extractCoreUnitFields` → `bec_core_unit_fields` → `bec_sync_unit_title`). Duplicate names reuse the same title; WordPress uniquifies the slug.
- **Units (content)**: Sync sets `post_content` from the canonical description (`bec_core_description` pipeline + `bec_sync_unit_content`, then `wp_kses_post`).
- **Units (mapped fields)**: `ProviderInterface::getUnitSyncFieldDefinitions()` + `UnitSyncFieldRegistry` — optional extra meta per provider/client; Kross default list empty. Filters `bec_unit_sync_provider_slugs`, `bec_unit_sync_field_definitions`, `bec_sync_apply_mapped_unit_fields`. Admin meta box **Booking engine — unit fields**.
- **Units (admin)**: Each unit edit screen shows a **Booking engine — synced data** meta box (Classic Editor and block editor) with core `bec_*` meta and a pretty-printed JSON snapshot of the last remote row (`bec_sync_payload`), populated on each successful sync.
- **Kross auth**: `exchangeToken` uses **POST** for `/v4/auth/get-token` with the same JSON body. WordPress’s HTTP stack cannot attach a JSON string body to GET (it expects an array for query encoding), which caused `TypeError: http_build_query(): Argument #1 ($data) must be of type array, string given` when verifying the connection.
- **Kross API client**: Logical `GET` calls that use the JSON envelope (`auth_token` + `data`) are sent as **POST** for the same reason; fixes the same `http_build_query` fatal on sync (`get-room-types`, `calendar/book`, etc.).

## 0.1.6 — 2026-03-20

- **Wave 6 (CHK / FB / UI / SHO)**: `CheckoutUrlService`, `BookingCtaRenderer`, `PublicContentBlocks` (append CTA or fallback after unit content when GET search is complete), `PublicAssets` + `assets/public.css`. `ProviderInterface::buildCheckoutUrl()` + `KrossProvider` (base URL option + filters). `FallbackService` / `FallbackRenderer`, admin **Checkout & fallback** (`bec_kross_checkout_base_url`, modes, triggers by `ProviderErrorCategory`, empty-quote trigger). Shortcodes: `bec_search`, `bec_dates`, `bec_checkout`, `bec_quote`, `bec_fallback`. Uninstall options extended.

## 0.1.5 — 2026-03-20

- **SEA-002 / SEA-003**: `SearchForm` + `SearchValidator` (dates, min/max nights via `bec_search_min_nights` / `bec_search_max_nights`); `SearchTemplateHooks` — `bec_before_search_form` / `bec_after_search_form`, `bec_before_unit_archive_loop` / `bec_after_unit_archive_loop`, optional `bec_auto_append_search_form_on_single_unit` (default true); `QuoteService::getQuote()` with `bec_quote_cache_ttl` and filters `bec_quote_search_context`, `bec_quote_result`, `bec_quote_cached_result`, `bec_quote_provider_error`. Template helpers `bec_render_search_form()`, `bec_get_unit_quote()`. CPT: `has_archive` via filter `bec_unit_has_archive`.

## 0.1.4 — 2026-03-20

- **SYNC (E3)**: `SyncService` (full + single post), `SyncLock`, `SyncCron` (WP-Cron `bec_run_scheduled_sync`, custom interval), admin **Sync** page (schedule + run now), row action & bulk “Sync with provider”, hooks `bec_before_unit_sync` / `bec_after_unit_sync`, filters for remote row and post data. `docs/SYNC.md`.

## 0.1.3 — 2026-03-20

- **PROV-003 (Kross v4)**: `KrossAuthenticator` aligned to GET `…/auth/get-token` + JSON body (`data.auth_token`); username/password credentials; `KrossApiClient` envelope (`auth_token` + `data`); `KrossProvider::fetchRemoteUnits()` → `get-room-types`; `getQuoteForUnit()` → `calendar/book`; `KrossResponseParser` for nested/JSON-in-string values.
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
