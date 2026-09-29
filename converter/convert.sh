#!/bin/sh
# Converts /in/source.pdf or /in/source.pptx into /out/page-N.png.
# /in is read-only, /out and /tmp are the only writable locations.
# Exit codes: 0 ok, 2 invalid input, 3 too many pages, 4 conversion failed.
# Never prints document content; the daemon records only the exit code.
set -eu

MAX_PAGES="${MAX_PAGES:-300}"
# longest side of each rendered page in pixels (bounds memory for oversized page boxes)
SCALE_TO="${SCALE_TO:-1920}"
export HOME=/tmp

set -- /in/source.*
[ "$#" -eq 1 ] && [ -f "$1" ] || exit 2
SRC="$1"
mkdir -p /tmp/work

case "$SRC" in
  /in/source.pdf)
    PDF="$SRC"
    ;;
  /in/source.pptx)
    cp -R /opt/tutora/lo-profile /tmp/lo-profile
    soffice --headless --norestore --nologo --nolockcheck --nodefault \
      "-env:UserInstallation=file:///tmp/lo-profile" \
      --convert-to pdf --outdir /tmp/work "$SRC" >/dev/null 2>&1 || exit 4
    PDF=/tmp/work/source.pdf
    [ -f "$PDF" ] || exit 4
    ;;
  *)
    exit 2
    ;;
esac

PAGES="$(pdfinfo "$PDF" 2>/dev/null | awk '/^Pages:/ {print $2}')"
case "$PAGES" in
  ''|*[!0-9]*) exit 4 ;;
esac
[ "$PAGES" -ge 1 ] || exit 4
[ "$PAGES" -le "$MAX_PAGES" ] || exit 3

pdftoppm -png -scale-to "$SCALE_TO" "$PDF" /out/page >/dev/null 2>&1 || exit 4
exit 0
