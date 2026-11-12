#!/usr/bin/env bash
# Admin screen standard (docs/screens/README.md) - PostToolUse on Edit|Write|MultiEdit.
# When an admin screen, its API, the sidebar or a module changes, remind the agent of the mandatory checklist.
# The rule itself is enforced by tests/Feature/AdminScreenStandardTest.php (composer test).
# Pure bash on purpose: no python/jq needed (on Windows `python3` is often only the Store stub).

INPUT="$(cat)"

# "file_path":"C:\\laragon\\www\\dorr\\x" -> C:/laragon/www/dorr/x
FILE="$(printf '%s' "$INPUT" | grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed -E 's/^"file_path"[[:space:]]*:[[:space:]]*"//; s/"$//; s#\\\\#/#g; s#\\#/#g')"
[ -z "$FILE" ] && exit 0

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
command -v cygpath >/dev/null 2>&1 && ROOT="$(cygpath -m "$ROOT")"
command -v cygpath >/dev/null 2>&1 && FILE="$(cygpath -m "$FILE")"

# Path relative to the project, case-insensitive on the root (Windows drive letters).
shopt -s nocasematch
if [[ "$FILE" == "$ROOT"/* ]]; then REL="${FILE:${#ROOT}+1}"; else REL="$FILE"; fi
shopt -u nocasematch

case "$REL" in
  */modules/admin/guide/*) exit 0 ;; # the guide itself
  resources/js/modules/admin/routes.js|resources/js/components/layout/admin/Sidebar.vue) KIND="screen" ;;
  resources/js/modules/admin/themes/*/views/*) KIND="screen" ;;
  routes/admin.php|Modules/*/routes/admin.php|Modules/*/app/Http/Controllers/Admin/*|app/Http/Controllers/General/*) KIND="api" ;;
  Modules/*/module.json|modules_statuses.json) KIND="module" ;;
  *) exit 0 ;;
esac

if [ "$KIND" = "module" ]; then
  MSG="Admin screen standard (docs/screens/README.md): $REL changed. A NEW module needs its map in this task: resources/js/modules/admin/guide/services/<module>.js with module: '<Name>' (and registered in guide/index.js); every admin screen it adds follows the screen checklist. Check: php artisan test --filter=AdminScreenStandardTest."
else
  MSG="Admin screen standard (docs/screens/README.md) - $REL changed. In this same task, for every admin screen added or changed: (1) PERMISSION: meta.permission on the route, the group and actions in AdminPermissionSeeder plus a migration granting super-admin, the backend guard (admin.screen:<group> or the controller catalog permissions), sidebar v-show can(), buttons through useCatalogPermissions; (2) MAP: the screen on guide/services/<module>.js; (3) GUIDE: guide/screens/<module>/<route>.js written from the code, ar + en; (4) CRUD built like countries: CatalogTranslationTabs for names and descriptions (translation table + TranslatableRepository), status filters with counts, search, trash / restore / force delete, bulk delete, .catalog-scroll-form for long forms; (5) every string in both locales/ar.json and locales/en.json. Never add to tests/Fixtures/admin-screens/baseline.json. Check: php artisan test --filter=AdminScreenStandardTest."
fi

printf '{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"%s"}}\n' "$MSG"
