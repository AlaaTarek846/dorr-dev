#!/usr/bin/env bash
# Android → iOS sync rule (docs/modules/ios/README.md).
# PostToolUse on Edit|Write|MultiEdit: when a file under androidApp/ changes,
#   - Android strings XML → regenerate iosApp Localizable.strings right away;
#   - anything else      → tell the agent to port the same change to iosApp in this task.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

FILE="$(python3 -c 'import json,sys; d=json.load(sys.stdin); i=d.get("tool_input") or {}; r=d.get("tool_response") or {}; print(i.get("file_path") or r.get("filePath") or "")')"
case "$FILE" in
  */androidApp/*) ;;
  *) exit 0 ;;
esac

REL="${FILE#"$ROOT"/}"

if [[ "$FILE" == */androidApp/app/src/main/res/values*/*.xml ]]; then
  python3 "$ROOT/iosApp/scripts/sync_strings.py" >/dev/null 2>&1
  MSG="Android strings changed ($REL): iosApp Localizable.strings were regenerated automatically. Use the same key with L(\"key\") in the iOS twin screen if this string is new."
elif [[ "$FILE" == *.kt || "$FILE" == *.kts || "$FILE" == *AndroidManifest.xml ]]; then
  MSG="Android → iOS sync rule: $REL changed. Port the same behavior to its twin in iosApp/ in this task (see the twin table in docs/modules/ios/README.md), or update the Parity table there if that area is not on iOS yet. Then rebuild iosApp."
else
  exit 0
fi

python3 -c 'import json,sys; print(json.dumps({"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":sys.argv[1]}}))' "$MSG"
