#!/usr/bin/env bash
# freelanceApp → freelanceAppIOS sync check: lists freelanceApp files changed (committed or not) since the last
# commit that touched freelanceAppIOS/. Each one needs its iOS twin updated, or a Parity-table note in
# freelanceAppIOS/README.md.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

base="$(git log -1 --format=%H -- freelanceAppIOS/ 2>/dev/null || true)"
if [[ -z "$base" ]]; then
  echo "freelanceAppIOS/ has no commits yet — showing uncommitted freelanceApp changes only."
  changed="$(git status --porcelain -- freelanceApp/ | awk '{print $NF}')"
else
  echo "freelanceApp changes since $(git log -1 --format='%h %s' "$base"):"
  changed="$( { git diff --name-only "$base" -- freelanceApp/; git status --porcelain -- freelanceApp/ | awk '{print $NF}'; } | sort -u)"
fi

changed="$(echo "$changed" | grep -E '\.(kt|kts|xml)$' | grep -v '/build/' || true)"
if [[ -z "$changed" ]]; then
  echo "  none — iOS is in step."
  exit 0
fi
echo "$changed" | sed 's/^/  /'
echo
echo "Strings XML are synced by freelanceAppIOS/scripts/sync_strings.py; port the rest (see freelanceAppIOS/README.md)."
