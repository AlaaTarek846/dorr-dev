# Dorr — Agent Instructions

This repository uses a documentation-driven development standard. **All AI agents and developers must follow it**, even when the user prompt is informal or incomplete.

---

## Read First (mandatory before code changes)

1. [docs/AI-INSTRUCTIONS.md](docs/AI-INSTRUCTIONS.md) — full workflow (9 steps)
2. [docs/11-CHECKPOINT.md](docs/11-CHECKPOINT.md) — current project state
3. [docs/04-ARCHITECTURE.md](docs/04-ARCHITECTURE.md) — system structure
4. [docs/16-STYLEGUIDE.md](docs/16-STYLEGUIDE.md) — coding standards

Then read the relevant module doc under [docs/modules/](docs/modules/).

---

## Source of Truth

The **existing codebase** is authoritative. Do not invent:

- Features, APIs, tables, relationships, permissions, or business rules

If unclear, mark **NEEDS-DECISION** and ask — do not guess.

---

## Project Quick Reference

| Item | Value |
|------|-------|
| Stack | Laravel 12, PHP 8.2, Vue 3, Pinia, Sanctum |
| Admin SPA | `/admin` → `/api/admin/v1/*` (guard: `admin_api`) |
| User SPA | `/user` → `/api/user/v1/*` (guard: `user_api`) |
| Provider SPA | `/provider` → `/api/provider/v1/*` (guard: `provider_api`) — mirrors User auth; **no AI chat** |
| Driver app | `driverApp/` (separate Gradle project) → `/api/driver/v1/*` (guard: `driver_api`, `Modules/Driver`) — a driver is **not** a Provider |
| Freelancer app | `freelanceApp/` (separate Gradle project, `com.dorr.freelance`, shares `driverApp/core`) → `/api/freelance/v1/*` (guard: `freelance_api`, `Modules/Freelance`) — guide [docs/modules/freelance/APP.md](docs/modules/freelance/APP.md) |
| Freelancer iOS app | `freelanceAppIOS/` (SwiftUI, XcodeGen target `DorrPro`, `com.dorr.freelance`) — the iOS twin of `freelanceApp/`, same API — guide [freelanceAppIOS/README.md](freelanceAppIOS/README.md) |
| Driver iOS app | `driverAppIos/` (SwiftUI, XcodeGen target `DorrCaptain`, `com.dorr.driver`) — the iOS twin of `driverApp/`, same `/api/driver/v1/*` API — guide [driverAppIos/README.md](driverAppIos/README.md) |
| Rides | `Modules/Rides` — plan [docs/rides-plan.md](docs/rides-plan.md), tables [docs/rides-structure.md](docs/rides-structure.md), **tasks [docs/rides_tasks.md](docs/rides_tasks.md)** (reserve a task with your name before starting; tick it when done) |
| Group types (chat) | Educational + work groups = the existing chat group with a `kind` and extra features, inside `Modules/Chat` — **tasks [docs/groups_tasks.md](docs/groups_tasks.md)** (draft; open decisions first; reserve a task before starting) |
| Shared catalog | `app/Http/Controllers/General/`, `app/Services/General/`, etc. |
| Modules | `Modules/Admin`, `User`, `AI`, `Provider`, `Driver`, `Rides`, … |
| API envelope | `App\Support\Api\ApiResponse` |
| Locales | `ar`, `en` |

---

## Backend Pattern

```
Route → Controller → Service → Repository → Model
         Form Request          API Resource
```

- **Catalog entities** → `General/` namespace + `CatalogController` / `CatalogService` / `TranslatableRepository`
- **Module features** → `Modules/{Name}/app/...`

---

## Frontend Pattern

- Admin: `adminAxios`, `crudStructure.js`, PrimeVue, `resources/js/modules/admin/`
- User: `userAxios`, `resources/js/modules/user/`
- Provider: `providerAxios`, `resources/js/modules/provider/`, entry `resources/js/apps/provider/provider-app.js`
- i18n: update **both** `locales/ar.json` and `locales/en.json`

---

## Admin screen standard — map, guide, permissions, languages (mandatory)

Owner's standing rule for **every new module and every admin screen added or changed**. Full checklist: [docs/screens/README.md](docs/screens/README.md).

1. **Map** — every module has its map `resources/js/modules/admin/guide/services/<module>.js` (`module: '<Name>'`); every screen is on it. New module → new map in the same task.
2. **Guide** — every screen has `resources/js/modules/admin/guide/screens/<module>/<route>.js` (Arabic + English), written from the code: what it does, who uses it, every field, step-by-step tasks, what it affects, warnings. Change a screen → update its guide (and `updated`).
3. **Permission** — `meta.permission` on the route; the group and actions in `AdminPermissionSeeder` (+ a migration that creates them and grants `super-admin`, because re-seeding wipes roles); the backend guard (`admin.screen:<group>` on the route group, or the catalog controller's permissions); sidebar `v-show="can()"`; every button through `useCatalogPermissions`.
4. **CRUD = like countries** — language tabs (`CatalogTranslationTabs`) for names/descriptions (translation table + `TranslatableRepository`), status filters with counts, search, trash / restore / force delete, bulk delete, `.catalog-scroll-form` for long forms. A CRUD with no text to translate says why in its guide (`tech.noTranslatableText`).
5. **Strings** in both `locales/ar.json` and `locales/en.json`; mobile API keys unchanged, text in the caller's `X-Locale`.

Enforced: `tests/Feature/AdminScreenStandardTest.php` fails `composer test` on any screen/module that misses it. The legacy list `tests/Fixtures/admin-screens/baseline.json` only shrinks — **never add to it**; finish a screen and delete its line. Claude Code also gets the checklist from the `PostToolUse` hook `.claude/hooks/admin-screen-standard.sh`; other agents follow this section by hand.

---

## Modern, event-driven apps — no polling

Owner's standing rule: mobile/web screens never ask the server on a timer (`while(true){delay(...)}`, Timer, periodic refresh). Use real-time (Pusher/Reverb events → one reload), push (OneSignal, high priority + ttl for urgent), the device clock for times already known, and one catch-up request on open / reconnect. Connection settings come from the server (`realtime-config`). Details: [docs/rides_tasks.md](docs/rides_tasks.md).

---

## After Implementation

1. Run `composer test` (and `npm run build` if frontend changed) — includes `AdminScreenStandardTest`
2. Update docs if API, schema, or architecture changed; update the screen's guide and its module map if a screen changed
3. Report: files changed, tests run, remaining TODO, NEEDS-DECISION items

---

## Documentation Index

See [README.md](README.md#documentation) for the full docs list.

---

## Plan First, Size the Model

Every project starts with a plan. Choose model and effort by task size (table in [docs/AI-INSTRUCTIONS.md](docs/AI-INSTRUCTIONS.md#model-and-effort-selection)): trivial → Haiku/low, normal → Sonnet/medium, large → Opus (Fable for the hardest)/high.

---

## Android App

Native app in `androidApp/` (Kotlin + Compose, `/api/mobile/v1/*`). Read [docs/modules/android/README.md](docs/modules/android/README.md) before touching it.

---

## iOS App — Android → iOS sync rule (mandatory)

Native twin in `iosApp/` (Swift + SwiftUI, same API). Read [docs/modules/ios/README.md](docs/modules/ios/README.md) before touching it.

**Any change to `androidApp/` must be made in `iosApp/` in the same task.** Android is the reference.

- **Strings** sync automatically: edit only the Android XML; `iosApp/scripts/sync_strings.py` regenerates `Localizable.strings` (on every Xcode build and through the Claude Code hook). Never edit the generated files.
- **Code:** port the same behavior to the twin file (table in the iOS doc), keeping names (screens, APIs, DTO fields, store keys).
- **Not on iOS yet?** Update the Parity table in the iOS doc instead of silently skipping. Mark platform-only features "Android only".
- **Check:** `bash iosApp/scripts/ios-parity.sh` lists Android files changed since `iosApp/` was last updated.
- **Build check:** `cd iosApp && xcodegen && xcodebuild -project Dorr.xcodeproj -scheme Dorr -destination 'generic/platform=iOS Simulator' -derivedDataPath build CODE_SIGNING_ALLOWED=NO build`.

Claude Code enforces this with a `PostToolUse` hook (`.claude/settings.json` → `.claude/hooks/android-ios-sync.sh`). Other agents (Cursor, etc.) must follow this section by hand.

---

## Freelancer iOS App — freelanceApp → freelanceAppIOS sync rule (mandatory)

Native twin in `freelanceAppIOS/` (Swift + SwiftUI, same `/api/freelance/v1/*` API). Read [freelanceAppIOS/README.md](freelanceAppIOS/README.md) before touching it.

**Any change to `freelanceApp/` must be made in `freelanceAppIOS/` in the same task.** Android is the reference.

- **Strings** sync automatically: edit only the `freelanceApp` XML; `freelanceAppIOS/scripts/sync_strings.py` regenerates `Localizable.strings` on every Xcode build. Never edit the generated files.
- **Code:** port the same behavior to the twin file (same file name: `ui/screens/OfferScreens.kt` → `DorrPro/UI/Screens/Work/OfferScreens.swift`, `net/WalletApi.kt` → `DorrPro/Net/WalletApi.swift`), keeping names (screens, API functions, DTO fields, store keys).
- **Platform-only differences** (no system back key, Face ID, App Store links…) are listed in the README; don't silently skip anything else.
- **Check:** `bash freelanceAppIOS/scripts/ios-parity.sh` lists freelanceApp files changed since `freelanceAppIOS/` was last committed.
- **Fast check:** `cd freelanceAppIOS && scripts/typecheck.sh <changed paths>`.
- **Build check:** `cd freelanceAppIOS && xcodegen && xcodebuild -project DorrPro.xcodeproj -scheme DorrPro -destination 'generic/platform=iOS Simulator' -derivedDataPath build CODE_SIGNING_ALLOWED=NO build`.

---

## Driver iOS App — driverApp → driverAppIos sync rule (mandatory)

Native twin in `driverAppIos/` (Swift + SwiftUI, same `/api/driver/v1/*` API). Read [driverAppIos/README.md](driverAppIos/README.md) before touching it.

**Any change to `driverApp/` must be made in `driverAppIos/` in the same task.** Android is the reference.

- **Strings** sync automatically: edit only the `driverApp` XML; `driverAppIos/scripts/sync_strings.py` regenerates `Localizable.strings` on every Xcode build (and through the Claude Code hook). Never edit the generated files.
- **Code:** port the same behavior to the twin file (same file name: `ui/TripScreen.kt` → `DorrCaptain/UI/Screens/TripScreen.swift`, `net/DriverApi.kt` → `DorrCaptain/Net/DriverApi.swift`), keeping names (screens, API functions, DTO fields, store keys).
- **Platform-only differences** (background location instead of a foreground service, the request notification instead of a full-screen ring, no system back key…) are listed in the README; don't silently skip anything else.
- **Check:** `bash driverAppIos/scripts/ios-parity.sh` lists driverApp files changed since `driverAppIos/` was last committed.
- **Fast check:** `cd driverAppIos && bash scripts/typecheck.sh` (whole app minus the SDK files) or `--only <changed paths>`.
- **Build check:** `cd driverAppIos && xcodegen && xcodebuild -project DorrCaptain.xcodeproj -scheme DorrCaptain -destination 'generic/platform=iOS Simulator' -derivedDataPath build CODE_SIGNING_ALLOWED=NO build`.
