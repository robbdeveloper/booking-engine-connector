Booking Engine Connector — translations
========================================

This folder holds gettext templates and compiled catalogs for the plugin text domain
`booking-engine-connector`.

Regenerate the POT template (needs WP-CLI with the `i18n` command):

  cd /path/to/wp-content/plugins/booking-engine-connector

  wp i18n make-pot . languages/booking-engine-connector.pot \
    --domain=booking-engine-connector \
    --exclude=vendor,node_modules,.git,.cursor,tools

After the POT changes, refresh an existing locale (example: Italian) and fill new strings:

  msgmerge --update languages/booking-engine-connector-it_IT.po languages/booking-engine-connector.pot

Compile binary MO files for WordPress runtime loading:

  wp i18n make-mo languages

Or compile a single file:

  msgfmt -o languages/booking-engine-connector-it_IT.mo languages/booking-engine-connector-it_IT.po

New locale from the template:

  msginit --locale=YOUR_LOCALE -i languages/booking-engine-connector.pot -o languages/booking-engine-connector-YOUR_LOCALE.po

The plugin loader uses `languages/` relative to the plugin root (Domain Path).

Verification (manual)
---------------------

English: default site language or `en_US`; confirm admin labels and front shortcodes use English
source strings from POT (or from MO if a catalog is loaded).

Italian: set Site Language to Italiano (`it_IT`) in Settings → General (or use Loco Translate /
a shipped MO). Spot-check: Booking Engine admin screens, `[bec_search]`, `[bec_booking_summary]`,
`[bec_unit_filters]`, `[bec_quote]`, `[bec_fallback]`, checkout CTA labels, and the date picker buttons/month names
(`includes/Front/PublicAssets.php` + bundled moment locale).

WPML / Polylang: with a non-default language active, confirm gettext strings switch. For stored
fallback “link text” and inline HTML, translate entries registered under context
“Booking Engine Connector” (WPML String Translation) or group `booking-engine-connector` (Polylang),
including keys `fallback_link_text` and `fallback_inline_content` — see
`includes/Integrations/Multilingual.php`.

The `bec_provider_locale` filter can align `determine_locale()` with a provider/API locale (see
`Multilingual::filteredSiteLocale()` and Kross checkout `lang`).
