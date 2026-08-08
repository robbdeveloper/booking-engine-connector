# Booking Engine Connector

Connect WordPress to external booking engines (Kross first) with structured sync, search context via URL parameters, checkout links, and contact fallback.

| Field | Value |
|---|---|
| **Contributors** | robbdev |
| **Tags** | booking, kross, hospitality, availability |
| **Requires at least** | 6.4 |
| **Tested up to** | 6.7 |
| **Requires PHP** | 8.0 |
| **Stable tag** | 0.4.0 |
| **License** | GPLv2 or later |
| **License URI** | https://www.gnu.org/licenses/gpl-2.0.html |

> **Note:** [`CHANGELOG.md`](CHANGELOG.md) is the single source of truth for release notes. This `README.md` is the GitHub overview (description, install, FAQ). [`readme.txt`](readme.txt) supplies WordPress Plugin Update Checker metadata only; the updater reads `CHANGELOG.md` for in-admin version details when `readme.txt` has no changelog section.

## Description

Booking Engine Connector (BEC) links your site to external reservation APIs in a modular way: provider abstraction, logging, WP-Cron sync, and shortcodes. See `docs/` for full specifications.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/booking-engine-connector/` or install the zip from the WordPress admin.
2. Activate the plugin through the **Plugins** screen.
3. Open **Booking Engine** in the admin menu and configure the connection (provider credentials).

## Frequently Asked Questions

### Which PHP and WordPress versions are supported?

PHP 8.0+ and WordPress 6.4+ are required (see header above).

### Where is search context stored?

Search context uses **GET query parameters** prefixed with `bec_` (e.g. check-in/out). See `docs/SEARCH-CONTEXT.md` when available.

### How do I submit `[bec_search]` from one page to another?

Use the **`redirect_url`** attribute so the form posts (GET) to your results page, e.g. `[bec_search redirect_url="/availability-results/"]`. The same **`bec_*`** query parameters are appended. If **`redirect_url`** is omitted, submissions go to the **units archive** (or **`home_url`** if the archive link is unavailable).

Use **`popover_placement`** to control where the date and guest popovers open relative to the search field on desktop/tablet: **`auto`** (default — opens below and flips above when there is not enough space), **`top`**, or **`bottom`**. On mobile, popovers still use the bottom sheet layout. Example: `[bec_search popover_placement="top"]`. You can also pass **`popover_placement`** to **`SearchForm::render()`** or filter **`bec_search_form_popover_placement`**.

Use **`daterange_format`** (PHP date format) or **`daterange_preset`** (`iso`, `short`, `medium`, `long`, `full`; default **`medium`**) on **`[bec_search]`** and **`[bec_booking_summary]`** to format the selected dates shown in the calendar footer before Cancel/Apply. Example: `[bec_search daterange_preset="long"]`.

### How do I filter units on a listing page?

Place **`[bec_unit_filters]`** above your unit loop (or on the unit archive). The form submits filter GET parameters (`bec_filter_order`, `bec_filter_rooms_min`, `bec_filter_bathrooms_min`, `bec_filter_amenities[]`) and keeps current search params. Pair with an Elementor Loop Grid using Query ID **`bec_available_only`** or **`bec_filtered_units`**. Choose which amenities appear under **Booking Engine → Listing Filters**; tune appearance under **Design → Unit filters**.

Shortcode attributes include **`layout`** (`inline` or `stacked`), **`show_reset`**, **`hide_labels`** (default `1`: labels hidden, filter name shown inside each control until a value is chosen; set `hide_labels="0"` to show labels above fields), **`filters`**, **`amenities`**, **`amenities_limit`**, and **`action`**.

### How do I show how many units match the current search?

Use **`[bec_available_units_count]`** anywhere on the results page (above an Elementor Loop Grid, in a heading, or on the native unit archive). It counts published units that match the **current listing query** (e.g. unit category archive), **`bec_filter_*`** params and, when dates and guests are in the URL, units that are **available** for that search—the same rules as Loop Grid Query ID **`bec_available_only`** / **`bec_filtered_units`**. On Elementor results pages the shortcode resolves the Loop Grid’s base query from the page document so the number matches the filtered grid, not the provider’s bulk availability total alone.

Examples: **`[bec_available_units_count]`** (number only), **`[bec_available_units_count format="text"]`** (default “%d available units” copy), **`[bec_available_units_count hide_without_search="1"]`** (empty until search params are complete), **`[bec_available_units_count zero_text="No units found"]`**, **`[bec_available_units_count category="villas"]`** (count only units in that unit category term), **`[bec_available_units_count query_id="bec_available_only"]`** (target a specific Loop Grid Query ID when multiple grids exist). Custom text: **`singular`** / **`plural`** with **`%d`**, optional **`class`** for styling.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release notes.
