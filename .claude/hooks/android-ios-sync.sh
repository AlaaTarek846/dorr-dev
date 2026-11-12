#!/usr/bin/env bash
# Android → iOS sync rules (docs/modules/ios/README.md, freelanceAppIOS/README.md, driverAppIos/README.md).
# PostToolUse on Edit|Write|MultiEdit: when a file under androidApp/, freelanceApp/ or driverApp/ changes,
#   - Android strings XML → regenerate the iOS twin's Localizable.strings right away;
#   - anything else      → tell the agent to port the same change to the iOS twin in this task.
# Reading the hook input and writing the answer is pure bash (like admin-screen-standard.sh). Python is only needed to run
# <twin>/scripts/sync_strings.py, and is looked up for real: on Windows `python3` is often the Microsoft Store stub, which
# exists on PATH but never runs - so every candidate is tried and must actually run Python 3.

INPUT="$(cat)"

# "file_path":"C:\\laragon\\www\\dorr\\x" -> C:/laragon/www/dorr/x
FILE="$(printf '%s' "$INPUT" | grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed -E 's/^"file_path"[[:space:]]*:[[:space:]]*"//; s/"$//; s#\\\\#/#g; s#\\#/#g')"
[ -z "$FILE" ] && FILE="$(printf '%s' "$INPUT" | grep -o '"filePath"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed -E 's/^"filePath"[[:space:]]*:[[:space:]]*"//; s/"$//; s#\\\\#/#g; s#\\#/#g')"
[ -z "$FILE" ] && exit 0

case "$FILE" in
  */androidApp/*) APP=androidApp; IOS=iosApp; GUIDE=docs/modules/ios/README.md ;;
  */freelanceApp/*) APP=freelanceApp; IOS=freelanceAppIOS; GUIDE=freelanceAppIOS/README.md ;;
  */driverApp/*) APP=driverApp; IOS=driverAppIos; GUIDE=driverAppIos/README.md ;;
  *) exit 0 ;;
esac

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# Path relative to the project, whatever form the root takes (C:/…, /c/…, /Users/…).
REL="$APP/${FILE#*/"$APP"/}"

json_escape() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\t'/ }"
  s="${s//$'\r'/ }"
  s="${s//$'\n'/ }"
  printf '%s' "$s"
}

say() {
  printf '{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"%s"}}\n' "$(json_escape "$1")"
}

# The first command that really runs Python 3: `py -3` (Windows launcher), `python`, then `python3`.
find_python() {
  local candidate
  for candidate in "py -3" python python3; do
    # shellcheck disable=SC2086 # "py -3" must split into command + flag
    command -v ${candidate%% *} >/dev/null 2>&1 || continue
    # shellcheck disable=SC2086
    if $candidate --version >/dev/null 2>&1 && $candidate -c 'import sys; sys.exit(0 if sys.version_info[0] >= 3 else 1)' >/dev/null 2>&1; then
      PYTHON="$candidate"
      return 0
    fi
  done
  return 1
}

if [[ "$FILE" == */$APP/app/src/main/res/values*/*.xml ]]; then
  if ! find_python; then
    say "WARNING: Android strings changed ($REL) but $IOS Localizable.strings were NOT regenerated: no working Python 3 found (tried py -3, python, python3; on Windows python3 is often only the Microsoft Store stub). Install Python 3, then run: python $IOS/scripts/sync_strings.py"
    exit 0
  fi
  # shellcheck disable=SC2086
  if OUT="$($PYTHON "$ROOT/$IOS/scripts/sync_strings.py" 2>&1)"; then
    MSG="Android strings changed ($REL): $IOS Localizable.strings were regenerated automatically. Use the same key with L(\"key\") in the iOS twin screen if this string is new."
  else
    MSG="WARNING: Android strings changed ($REL) but $IOS/scripts/sync_strings.py failed ($PYTHON): $(printf '%s' "$OUT" | tail -n 3 | tr '\r\n' '  '). Fix it and run it again before finishing."
  fi
elif [[ "$FILE" == *.kt || "$FILE" == *.kts || "$FILE" == *AndroidManifest.xml ]]; then
  MSG="Android → iOS sync rule: $REL changed. Port the same behavior to its twin in $IOS/ in this task (see $GUIDE), or note it there if that area is not on iOS yet. Then rebuild $IOS."
else
  exit 0
fi

say "$MSG"
