#!/usr/bin/env bash
# Android → iOS sync rules (docs/modules/ios/README.md, freelanceAppIOS/README.md, driverAppIos/README.md).
# PostToolUse on Edit|Write|MultiEdit: when a file under androidApp/, freelanceApp/ or driverApp/ changes,
#   - Android strings XML → regenerate the iOS twin's Localizable.strings right away;
#   - anything else      → tell the agent to port the same change to the iOS twin in this task.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

FILE="$(python3 -c 'import json,sys; d=json.load(sys.stdin); i=d.get("tool_input") or {}; r=d.get("tool_response") or {}; print(i.get("file_path") or r.get("filePath") or "")')"
case "$FILE" in
  */androidApp/*) APP=androidApp; IOS=iosApp; GUIDE=docs/modules/ios/README.md ;;
  */freelanceApp/*) APP=freelanceApp; IOS=freelanceAppIOS; GUIDE=freelanceAppIOS/README.md ;;
  */driverApp/*) APP=driverApp; IOS=driverAppIos; GUIDE=driverAppIos/README.md ;;
  *) exit 0 ;;
esac

REL="${FILE#"$ROOT"/}"

if [[ "$FILE" == */$APP/app/src/main/res/values*/*.xml ]]; then
  python3 "$ROOT/$IOS/scripts/sync_strings.py" >/dev/null 2>&1
  MSG="Android strings changed ($REL): $IOS Localizable.strings were regenerated automatically. Use the same key with L(\"key\") in the iOS twin screen if this string is new."
elif [[ "$FILE" == *.kt || "$FILE" == *.kts || "$FILE" == *AndroidManifest.xml ]]; then
  MSG="Android → iOS sync rule: $REL changed. Port the same behavior to its twin in $IOS/ in this task (see $GUIDE), or note it there if that area is not on iOS yet. Then rebuild $IOS."
else
  exit 0
fi

python3 -c 'import json,sys; print(json.dumps({"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":sys.argv[1]}}))' "$MSG"
