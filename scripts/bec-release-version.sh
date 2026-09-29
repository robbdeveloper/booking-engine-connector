#!/usr/bin/env bash
# Bump Booking Engine Connector version strings and compile Italian MO.
# Does not edit CHANGELOG.md or run git.
#
# Usage:
#   ./scripts/bec-release-version.sh X.Y.Z
#
# See .cursor/skills/bec-release-version/SKILL.md

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

NEW="${1:-}"
if [[ -z "$NEW" ]]; then
	echo "Usage: $0 X.Y.Z" >&2
	exit 1
fi

if [[ ! "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Version must be MAJOR.MINOR.PATCH (got: $NEW)" >&2
	exit 1
fi

MAIN="$ROOT/booking-engine-connector.php"
if [[ ! -f "$MAIN" ]]; then
	echo "Not in plugin root: missing booking-engine-connector.php" >&2
	exit 1
fi

OLD="$(php -r "
\$c = file_get_contents('$MAIN');
if (preg_match(\"/define\\('BEC_VERSION',\\s*'([^']+)'/\", \$c, \$m)) {
	echo \$m[1];
}
")"

if [[ -z "$OLD" ]]; then
	echo "Could not read BEC_VERSION from $MAIN" >&2
	exit 1
fi

if [[ "$OLD" == "$NEW" ]]; then
	echo "BEC_VERSION is already $NEW" >&2
	exit 1
fi

echo "Bumping $OLD → $NEW"

replace_in_file() {
	local file="$1"
	if [[ ! -f "$file" ]]; then
		echo "Skip missing: $file" >&2
		return 0
	fi
	if ! grep -q "$OLD" "$file"; then
		echo "Warning: $OLD not found in $file (skipped sed)" >&2
		return 0
	fi
	if [[ "$(uname -s)" == "Darwin" ]]; then
		sed -i '' "s/$OLD/$NEW/g" "$file"
	else
		sed -i "s/$OLD/$NEW/g" "$file"
	fi
}

replace_in_file "$MAIN"
replace_in_file "$ROOT/README.md"
replace_in_file "$ROOT/languages/booking-engine-connector.pot"
replace_in_file "$ROOT/languages/booking-engine-connector-it_IT.po"

PO="$ROOT/languages/booking-engine-connector-it_IT.po"
MO="$ROOT/languages/booking-engine-connector-it_IT.mo"

if [[ ! -f "$PO" ]]; then
	echo "Missing $PO" >&2
	exit 1
fi

if command -v msgfmt >/dev/null 2>&1; then
	msgfmt -o "$MO" "$PO"
	echo "Compiled $MO"
elif command -v wp >/dev/null 2>&1; then
	wp i18n make-mo "$ROOT/languages"
	echo "Compiled MO via wp i18n make-mo"
else
	echo "msgfmt not found; install gettext or run: msgfmt -o $MO $PO" >&2
	exit 1
fi

VERIFY="$(grep -E "define\('BEC_VERSION'" "$MAIN" | sed -E "s/.*'([^']+)'.*/\1/" | tail -1)"

if [[ "$VERIFY" != "$NEW" ]]; then
	echo "Verify failed: expected BEC_VERSION $NEW" >&2
	exit 1
fi

echo "Done. Update CHANGELOG.md, then commit and tag v$NEW when ready."
