#!/usr/bin/env bash
# Android → iOS sync check: lists androidApp files changed (committed or not) since the last
# commit that touched iosApp/. Each one needs its iOS twin updated, or a Parity-table note in
# docs/modules/ios/README.md.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

base="$(git log -1 --format=%H -- iosApp/ 2>/dev/null || true)"
if [[ -z "$base" ]]; then
  echo "iosApp/ has no commits yet — showing uncommitted androidApp changes only."
  changed="$(git status --porcelain -- androidApp/ | awk '{print $NF}')"
else
  echo "Android changes since $(git log -1 --format='%h %s' "$base"):"
  changed="$( { git diff --name-only "$base" -- androidApp/; git status --porcelain -- androidApp/ | awk '{print $NF}'; } | sort -u)"
fi

changed="$(echo "$changed" | grep -E '\.(kt|kts|xml)$' | grep -v '/build/' || true)"
if [[ -z "$changed" ]]; then
  echo "  none — iOS is in step."
  exit 0
fi
echo "$changed" | sed 's/^/  /'
echo
echo "Strings XML are synced by iosApp/scripts/sync_strings.py; port the rest (see docs/modules/ios/README.md)."
