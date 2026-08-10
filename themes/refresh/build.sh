#!/usr/bin/env bash
#
# Compile the theme stylesheet.
#
#   themes/refresh/src/theme.scss  ->  themes/refresh/public/css/theme.css
#
# The compiled CSS is committed, so deploying the theme needs no build step —
# copy the directory into themes/ and set APP_THEME=refresh. This script is only
# needed when editing src/.
#
# Uses the sass binary from the project's node_modules if present, otherwise
# falls back to npx, otherwise runs it inside the project's node container.

set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$THEME_DIR/src/theme.scss"
OUT="$THEME_DIR/public/css/theme.css"
STYLE="${1:-compressed}"

mkdir -p "$(dirname "$OUT")"

if [ -x "$THEME_DIR/../../node_modules/.bin/sass" ]; then
    SASS="$THEME_DIR/../../node_modules/.bin/sass"
    "$SASS" "$SRC" "$OUT" --style="$STYLE" --no-source-map
elif command -v npx >/dev/null 2>&1; then
    npx --yes sass "$SRC" "$OUT" --style="$STYLE" --no-source-map
else
    ( cd "$THEME_DIR/../.." && docker compose run --rm --entrypoint sh node -c \
        "npx --yes sass themes/refresh/src/theme.scss themes/refresh/public/css/theme.css --style=$STYLE --no-source-map" )
fi

echo "Built $(basename "$OUT") ($(wc -c < "$OUT" | tr -d ' ') bytes, style=$STYLE)"
