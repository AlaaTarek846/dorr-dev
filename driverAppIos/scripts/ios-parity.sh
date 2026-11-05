#!/usr/bin/env bash
# driverApp → driverAppIos sync check: lists driverApp files changed (committed or not) since the last
# commit that touched driverAppIos/. Each one needs its iOS twin updated, or a Parity-table note in
# driverAppIos/README.md.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

base="$(git log -1 --format=%H -- driverAppIos/ 2>/dev/null || true)"
if [[ -z "$base" ]]; then
  echo "driverAppIos/ has no commits yet — showing uncommitted driverApp changes only."
  changed="$(git status --porcelain -- driverApp/ | awk '{print $NF}')"
else
  echo "driverApp changes since $(git log -1 --format='%h %s' "$base"):"
  changed="$( { git diff --name-only "$base" -- driverApp/; git status --porcelain -- driverApp/ | awk '{print $NF}'; } | sort -u)"
fi

changed="$(echo "$changed" | grep -E '\.(kt|kts|xml)$' | grep -v '/build/' || true)"
if [[ -z "$changed" ]]; then
  echo "  none — iOS is in step."
  exit 0
fi
echo "$changed" | sed 's/^/  /'
echo
echo "Strings XML are synced by driverAppIos/scripts/sync_strings.py; port the rest (see driverAppIos/README.md)."
