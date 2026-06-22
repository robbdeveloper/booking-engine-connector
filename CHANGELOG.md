# Changelog

## 0.3.2 — 2026-06-22

- **Multilingual listings**: Fix search, BEC unit filters, and availability pruning on translated unit and category archives by using language-aware candidate discovery instead of canonical-only unit IDs; carry `lang` through Elementor current-query resolution and count cache keys; localize search/filter form actions on translated archives.

## 0.3.1 — 2026-06-19

- **Units — request-only pricing**: `[bec_quote]` starting-from output resolves currency from sync payload or defaults to EUR so formatted prices match normal quotes (e.g. `1.500,00 €`). Filters **`bec_core_starting_from_currency`**, **`bec_core_starting_from_currency_default`**.
- **Admin**: Units list table **Request only** column (Yes/No), sortable.

## 0.3.0 — 2026-06-19

- **Units — request-only booking**: Canonical core meta **`bec_core_only_request`** (boolean) and **`bec_core_starting_from`** (numeric), synced from Kross raw **`be_only_request`** and **`starting_from_price`**. Boolean support in the core-fields registry and admin metabox; WPML copy rules for both keys.
- **Frontend**: Request-only units render contact fallback for **`[bec_search]`**, auto-prepended search, and **`[bec_booking_summary]`**; **`[bec_quote]`** shows translated “From {starting_from}” without live quotes; **`[bec_checkout]`** and appended booking blocks skip online booking. **`QuoteService`**, **`CheckoutUrlService`**, and **`PublicContentBlocks`** backstop alternate paths. Helper **`UnitBookingMode`**; filter **`bec_core_starting_from_currency`** for optional currency display.

## 0.2.10 — 2026-06-19

- **Shortcodes (`[bec_available_units_count]`)**: Fix counts on unit category archives after **`bec_search`** — candidate discovery now preserves `taxonomy` / `term` (and BEC routing) scope instead of widening to all site-wide available units when availability pruning runs.

## 0.2.9 — 2026-06-19

- **Shortcodes (`[bec_available_units_count]`)**: Count now mirrors the Elementor Loop Grid query (same **`bec_filter_*`** + availability rules as Query ID **`bec_available_only`** / **`bec_filtered_units`**) by resolving the loop grid’s base query from the current Elementor document, not the bulk availability response alone. Native unit archives and taxonomy archives keep using the main query; optional **`query_id`** attribute targets a specific Loop Grid Query ID. Filters **`bec_unit_listing_query_for_count`**, **`bec_elementor_unit_listing_document_ids`**.

## 0.2.8 — 2026-06-19

- **Unit filters — Elementor Loop Grid**: Apply **`bec_filter_*`** constraints on Query IDs **`bec_available_only`** / **`bec_filtered_units`** even when no search dates are in the URL (availability pruning still runs only when search context is complete). Resolves filtered unit IDs to **`post__in`** so Elementor reliably narrows the loop without an active **`[bec_search]`** submission. New **Filter** hub on viewports ≤639px — a single trigger opens a body-mounted drawer listing each enabled filter; tapping a row opens the existing picker/amenities bottom sheet. **Done** (and close/backdrop/Escape) returns to the hub so visitors can adjust multiple filters before **Apply filters**. Hub footer places **Reset filters** on the left and **Apply filters** on the right; active-filter count badge on the trigger.
- **Unit filters UI — overlays**: Hub and per-field picker/amenities panels portal to `document.body` on mobile for reliable fixed positioning and z-index stacking. Native radio/checkbox controls stay visually hidden in portaled panels (custom option UI only). Desktop inline filter row layout preserved via `.bec-unit-filters__fields` flex wrapper.
- **i18n**: Italian translations for mobile hub strings (**Filters** → *Filtri*, **Close filters**, hub **Filter** trigger). Updated `languages/booking-engine-connector.pot`, merged `booking-engine-connector-it_IT.po`, recompiled `booking-engine-connector-it_IT.mo`.

## 0.2.7 — 2026-06-15

- **Booking summary — enquiry link**: **`[bec_booking_summary]`** Enquiry button uses **`FallbackSettings::getLocalizedLinkUrl()`** so the href follows per-language fallback URL settings (and String Translation fallback) instead of always reading the default-language option.

## 0.2.6 — 2026-06-15

- **Fallback — multilingual content**: Per-language tabs on **Checkout & Fallback** for fallback link URL, link text, and inline content when WPML or Polylang is active. Non-default languages are stored in `bec_fallback_translations`; default-language values remain in the existing options. Frontend fallback and booking-summary enquiry labels resolve localized values, with WPML/Polylang String Translation as fallback when a translation tab is left empty.
- **Fallback — link URL**: Preserve percent-encoded characters (e.g. `%3A` for Elementor popup triggers) when saving and rendering fallback link targets; avoid `esc_url()` rewriting encoded hash/query fragments.
- **i18n**: Regenerated `languages/booking-engine-connector.pot`, merged `booking-engine-connector-it_IT.po`, recompiled `booking-engine-connector-it_IT.mo`.

## 0.2.5 — 2026-06-15

- **Multilingual (WPML / Polylang) — unit categories**: Translated `bec_unit` posts are assigned translated `bec_unit_category` terms instead of default-language canonical terms. **`UnitCategorySync::onAfterUnitSync()`** ensures category translations exist before unit translation sync; **`UnitTranslationSync`** refreshes taxonomy assignment for all linked translation posts on each canonical unit sync (including existing translations without new locale strings).
- **Multilingual bridge (`MultilingualBridge`)**: **`resolveTranslatedCategoryTermId()`** resolves translated category terms via plugin translation maps, meta queries, and language-variant lookup before WPML/Polylang APIs. **`setObjectTermsPreservingIds()`** and **`getObjectTermIdsRaw()`** wrap taxonomy reads/writes with **`wpml_disable_term_adjust_id`** so WPML does not rewrite translated term IDs to canonical IDs on assignment. Improved WPML term translation linking when **`trid`** is not yet allocated.
- **Category translation sync (`CategoryTranslationSync`)**: Public idempotent **`syncTranslationsForCanonicalTerm()`** helper; dedupe guard no longer returns early without creating a missing translation term.

## 0.2.4 — 2026-06-12

- **Unit categories (`UnitCategorySync`)**: Harden canonical category lookup — exclude translation terms from provider-meta queries and SQL fallback; restrict term adoption to default-language (or unassigned) terms without conflicting provider meta. **`repairDuplicateCanonicalTerms()`** merges duplicate canonical provider category terms per `bec_provider_slug` + `bec_external_id`: reassign unit relationships, repoint linked translation terms, merge synced meta, delete duplicate canonicals only (never translation terms). Category registry priming via **`syncUniqueDescriptorsFromRows()`** now runs during single-unit sync as well as full sync.
- **Category translation sync (`CategoryTranslationSync`)**: **`stripProviderLookupMeta()`** removes `bec_external_id`, `bec_provider_slug`, and `bec_translation_term_ids` from managed translation terms so they cannot appear as duplicate canonicals. **`cleanupExistingTranslationProviderMeta()`** heals legacy pollution on existing translation terms.
- **WPML (`wpml-config.xml`)**: Removed copy rules for `bec_external_id`, `bec_provider_slug`, and plugin-owned translation-link term meta on translated category terms.

## 0.2.3 — 2026-06-12

- **Multilingual (WPML / Polylang) — translation sync**: New **`MultilingualBridge`** adapter plus **`UnitTranslationSync`** and **`CategoryTranslationSync`**. After each unit or category sync, linked translation posts and terms are created or updated from provider locale maps (title, content, localized names). Canonical posts stay on the default language; translation metadata uses **`bec_translation_*`** meta. Admin toggle on **Booking Engine → Frontend** (`bec_sync_translations_enabled`, default on when multilingual is active). Filters: **`bec_sync_translations_enabled`**, **`bec_unit_translation_strings`**, **`bec_category_translation_strings`**, **`bec_unit_translation_shared_meta_keys`**. Trash/delete cascades to linked translations.
- **Kross provider — translations**: **`KrossUnitTranslations`** and **`KrossCategoryTranslations`** supply locale strings from normalized room-type and category rows. **`UnitTranslationSync::buildTranslatedUnitPostSlug()`** builds language-specific unit slugs for translation posts.
- **Units — permalinks (`UnitPermalinkRouter`)**: Directory language-prefix URLs for WPML/Polylang — duplicate rewrite rules per active language, **`lang`** query var on prefixed routes, localized term archive links, rewrite flush when languages change.
- **Unit categories (`UnitCategorySync`)**: Registry sync deduplicates provider category descriptors by external ID before upsert; **`bec_after_category_sync`** hook drives category translation sync. Term translation metadata and language assignment aligned with unit flow.
- **Fallback (`FallbackSettings`, `FallbackRenderer`, `FallbackPage`)**: Sanitize and escape fallback link targets (relative paths, query strings, `mailto:`, `tel:`, `http(s):`); reject unsafe values on save and at render time. Updated link URL placeholder in admin.
- **WPML**: Shipped **`wpml-config.xml`** — translate **`bec_unit`** / **`bec_unit_category`** / **`bec_unit_amenity`**; copy vs translate rules for sync and translation meta; translate **`bec_core_name`** / **`bec_core_description`** on units.
- **i18n**: Regenerated `languages/booking-engine-connector.pot`, merged `booking-engine-connector-it_IT.po`, recompiled `booking-engine-connector-it_IT.mo`.

## 0.2.2 — 2026-06-01

- **Shortcodes (`[bec_available_units_count]`)**: Count respects the current listing query (e.g. unit category taxonomy archives) instead of always returning the site-wide unit total. Optional **`category`** attribute (term slug) scopes the count to a specific unit category on any page.
- **i18n**: Regenerated `languages/booking-engine-connector.pot`, merged `booking-engine-connector-it_IT.po`, recompiled `booking-engine-connector-it_IT.mo`.

## 0.2.1 — 2026-06-01

- **Units — permalinks (`UnitPermalinkRouter`)**: Fix 404s on unit category archives using `/{unit slug}/{term}` or `/{term}` URL formats when requesting pagination (`/page/N`), feeds, or embed endpoints. Custom rewrite rules now mirror WordPress core taxonomy archive endpoints. Bare `/{term}` pagination is also resolved in `parse_request` when core page rules claim the URL first.
- **Frontend — public assets (`PublicAssets`)**: Fix missing CSS/JS for `[bec_search]`, `[bec_unit_filters]`, and other tracked shortcodes on taxonomy term archives and other contexts where pre-detection at `wp_enqueue_scripts` fails (notably Elementor Theme Builder templates). Add idempotent `ensureEnqueued()` with runtime hooks on `do_shortcode_tag` and `elementor/frontend/before_get_builder_content`; call from `SearchForm::render()` for `bec_render_search_form()`. Pre-detection: probe queried object ID only on singular views; scan taxonomy term descriptions for early head enqueue.

## 0.2.0 — 2026-05-27

- **i18n (Italian)**: Regenerate `languages/booking-engine-connector.pot` from current source and complete Italian translations for wp-admin (Dashboard, Connection, Frontend, Sync & Import, Units, Listing Filters, Design, Checkout & Fallback, Tools & Logs). Recompiled `booking-engine-connector-it_IT.mo`.

## 0.1.47 — 2026-05-27

- **Booking summary (mobile)**: Portal the fixed bottom bar to `document.body` alongside the drawer and backdrop so the bar also escapes nested stacking contexts and z-index traps in theme/Elementor layouts.

## 0.1.46 — 2026-05-27

- **Booking summary (mobile)**: Portal the slide-in drawer and backdrop to `document.body` (mirrors guest/daterange popover mounts) so fixed positioning and z-index stack correctly inside nested theme/Elementor containers. New styling token `--bec-bsummary-drawer-z-index` (default `10040`, below popovers at `10050`). JS scope helpers keep rate switching, form sync, and check-availability behavior unchanged.

## 0.1.45 — 2026-05-27

- **Unit filters UI (`assets/public.css`)**: Extend theme-safe button styling to generic picker controls (`.bec-unit-filters__picker-wrap > button`, `.bec-unit-filters__picker-done`) alongside the amenities picker. Remove stray hover/focus background on the **Reset filters** link.

## 0.1.44 — 2026-05-26

- **Unit filters UI (`[bec_unit_filters]`)**: Scoped button resets in **`assets/public.css`** so theme global `button` / `[type=submit]` styles no longer override the amenities picker trigger, panel actions, and **Apply filters** submit control. Matches the search-form pattern (`.bec-unit-filters button.bec-unit-filters__*` selectors, shared `appearance` / `text-transform` reset).

## 0.1.43 — 2026-05-26

- **Admin redesign**: **Dashboard** with status cards (provider, credentials, sync health, unit count, checkout/fallback) and quick-action links. Menu reordered and renamed: **Connection** (credentials only), new **Frontend** page (search guest/child-age modes and single-unit auto content — same option keys as before), **Sync & Import**, **Units** (permalinks + links to units/listing filters), **Listing Filters**, **Design**, **Checkout & Fallback**, **Tools & Logs**. Shared `AdminPageLayout` + `assets/admin.css` on all Booking Engine screens. Existing sync `admin_post_*` / `wp_ajax_*` hooks, form IDs (`bec-sync-all-form`, `bec-sync-progress`, etc.), and localized `becSyncProgress` JS contract preserved. Design screen CodeMirror now includes unit-filters extra CSS.

## 0.1.42 — 2026-05-26

- **Units — permalinks**: Selectable URL structures for single units (`/{unit slug}/{unit name}`, `/{unit slug}/{category}/{unit name}`, `/{category}/{unit name}`) and unit category archives (`/{category slug}/{term}`, `/{unit slug}/{term}`, `/{term}`). Existing unit and category slug fields are preserved. Admin validation blocks ambiguous combinations; top-level category URLs defer to WordPress core content when slugs collide. Compatible with WPML/Polylang language prefixes. Filters: `bec_unit_url_structure`, `bec_unit_category_url_structure`, `bec_unit_permalink_primary_term`, `bec_unit_permalink_slug_conflicts_with_core`.

## 0.1.41 — 2026-05-25

- **Shortcodes (`[bec_quote]`)**: When there is no availability for the selected dates, the quote `<p>` includes an additional **`no-results`** CSS class for styling.

## 0.1.40 — 2026-05-25

- **Units (core fields) — City**: New canonical **`bec_core_city`** (`CoreUnitSemantic::CITY`) synced from Kross raw **`city`** on unit sync; editable in the unit admin meta box. City remains part of **`bec_core_address_full`** as before. Provider docblock updated for future providers.
- **Search — guest popover (`public-search.js`, `ShortcodeRegistry.php`)**: Fix guest picker not opening on some Elementor pages when the same **`[bec_search]`** template appears more than once (e.g. Theme Builder header plus page content) or when the form HTML loads after the script. Enhanced forms now initialize on **`DOMContentLoaded`** and re-scan on **`elementor/frontend/init`**; each shortcode render gets a unique **`form_id`** via **`wp_unique_id()`**; guest panel lookup is scoped to the form instance instead of the first matching **`getElementById`**.
- **i18n**: POT / Italian updates for the City field label.

## 0.1.39 — 2026-05-23

- **Booking summary (`booking-summary-default.css`)**: Color token alignment — search date day uses **`--bec-bsummary-color-text`**, headline uses **`--bec-bsummary-color-primary`** (was accent), rate names use text color with selected rate name/price highlighted in primary.

## 0.1.38 — 2026-05-22

- **Booking summary — mobile drawer (`BookingSummaryRenderer.php`, `booking-summary-default.css`)**: Slide-in panel uses a flex column layout with **`bec-booking-summary__drawer-body`** wrapping search through quote results (rates, accordions, breakdown). That middle section scrolls when content is taller than the viewport; the back header and bottom actions remain pinned. Replaces whole-drawer scroll and fixed action bar overlap on small screens.

## 0.1.37 — 2026-05-22

- **Search — mobile overlay (`public-search.js`, `public-search-daterange.js`)**: Fix shared **`.bec-search-form__backdrop`** staying visible after applying guest counts when the date picker hands off to the guest drawer. Backdrop is dismissed as soon as a mobile drawer starts closing; **`bec:search-overlay-closed`** keeps the date-range and guest scripts in sync. Guest-open detection checks the panel’s open state, not only **`aria-expanded`**. CSS **`[hidden]`** rule on the backdrop avoids theme overrides leaving the blur visible.
- **Search — date range placement (`public-search-daterange.js`, `public-search.js`)**: With **`popover_placement="auto"`**, desktop calendar popover flips above/below based on viewport space (repositions on scroll/resize while open). Guest popover placement uses the same above/below rule without a secondary height flip.

## 0.1.36 — 2026-05-22

- **Search (`[bec_search]`)** and **Booking summary (`[bec_booking_summary]`)**: **`daterange_format`** (PHP `date_i18n` format) and **`daterange_preset`** (`iso`|`short`|`medium`|`long`|`full`) configure the selected-date readout in the date range picker footer (`.drp-selected`, next to Cancel/Apply). New **`MomentFormatMapper`** (`includes/Formatting/MomentFormatMapper.php`) maps PHP presets/formats to Moment.js; enhanced search forms expose **`data-bec-daterange-format`**. Filters **`bec_search_form_daterange_format`**, **`bec_daterange_moment_format_presets`**, **`bec_php_date_format_to_moment`**. Default preset **`medium`** (e.g. `22 May 2026 – 25 May 2026`; use **`daterange_preset="iso"`** for ISO dates).
- **Search — date picker UI (`search-form-enhanced.css`)**: Mobile sheet footer uses a grid layout with centered selected dates and full-width Cancel/Apply buttons.
- **Booking summary (`booking-summary-default.css`)**: Accordion expand/collapse chevrons use a rotated border marker with transition; rules scoped to **`.bec-booking-summary__accordion`** (removed generic **`details>summary`** styling).

## 0.1.35 — 2026-05-21

- **Units (core fields)**: Derived **`bec_core_lat_lng`** (`CoreUnitSemantic::LAT_LNG`) combines **`bec_core_lat`** and **`bec_core_lng`** as `lat,lng` on provider sync and admin save; read-only in the unit meta box.
- **Search (`[bec_search]`)**: **`popover_placement`** attribute (`auto`, `top`, `bottom`) and filter **`bec_search_form_popover_placement`**; enhanced layout passes **`data-bec-popover-placement`** to **`public-search.js`** for desktop popover positioning.
- **i18n**: POT / Italian updates for coordinates field strings.

## 0.1.34 — 2026-05-21

- **Shortcodes (`[bec_unit_info key="amenities_grid"]`)**: New **`columns_mobile`** pass-through attribute (1–6, default `1`) sets grid columns below 640px; desktop layout still uses **`columns`** (default `2`). CSS variable **`--bec-amenities-cols-mobile`** in **`assets/public-amenities-kross.css`**.
- **Shortcodes (`[bec_unit_info key="bedroom_arrangements"]`)**: Section title hidden by default; pass **`show_title="1"`** to display it (previously shown unless **`show_title="0"`**).
- **Shortcodes (`[bec_dates]`)**: Built-in defaults changed to **`preset="long"`** and **`label_style="from_to"`** (was ISO dates with arrow).
- **Shortcodes (`[bec_quote]`)**: Built-in defaults changed to currency **symbol** after amount, **`number_style="eu"`**, and **`show_rates="never"`**; multi-rate list only when **`show_rates="always"`** or legacy **`show_rates="auto"`**.
- **Booking summary**: Rate-select control styling tweaks in **`assets/styling/booking-summary-default.css`**.

## 0.1.33 — 2026-05-21

- **Shortcodes (`[bec_available_units_count]`)**: Display the number of units matching current unit filters and (when search context is complete) provider availability—works on Elementor results pages, unit archives, and regular pages without relying on Loop Grid render order. Attributes: `format` (`number`|`text`), `hide_without_search`, `singular` / `plural` with `%d`, `zero_text`, `class`.
- **Unit listings**: Shared **`UnitListingAvailability`** and **`UnitResultCountService`** helpers for Elementor availability filtering and the count shortcode (per-request caching).

## 0.1.32 — 2026-05-20

- **Unit filters UI (`[bec_unit_filters]`)**: Progressive-enhancement pickers for all filter fields. **Amenities** — multi-select with checkbox list, chip/value trigger on desktop, mobile bottom sheet, Clear retained. **Order / rooms / bathrooms** — single-select popovers with the same desktop dropdown and mobile drawer (no Clear; use **Any** or **Reset filters**). New **`assets/public-unit-filters.js`**; styles in **`public.css`** wired to global BEC tokens (including hover/focus on submit, Done, triggers, and list rows).
- **Amenities panel**: Search input removed.

## 0.1.31 — 2026-05-20

- **Shortcodes (`[bec_unit_filters]`)**: GET filter form for unit listings — sort order, minimum rooms/bathrooms, and amenities. Preserves search context (`bec_checkin`, `bec_checkout`, guests, etc.). Attributes: `filters`, `layout` (`inline`|`stacked`), `show_reset`, `amenities` (`selected` or comma-separated keys), `amenities_limit`, `action`. Filters **`bec_unit_filter_definitions`**, **`bec_unit_filters_preserve_query_keys`**, **`bec_unit_filter_query_applied`**.
- **Unit filters — query integration**: Applies filters on Elementor Loop Grid Query IDs **`bec_available_only`** (default) and alias **`bec_filtered_units`**; availability pruning runs only when search context is complete. Native **`bec_unit`** archive main query supported via **`UnitFilterQueryHooks`**.
- **Amenity index**: Hidden taxonomy **`bec_unit_amenity`** synced from **`bec_core_amenities`** on unit sync/admin save; batched backfill for existing units. Admin **Unit filters** page curates which amenities appear in the shortcode (order, optional labels). Styling tokens and extra CSS under **Booking Engine → Styling**.
- **i18n**: POT / Italian updates for filter shortcode and admin strings.

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
