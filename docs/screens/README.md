# Admin screen standard

**Mandatory** for every new module and every admin screen added or changed (owner's rule, 2026-11-10).
Enforced by `tests/Feature/AdminScreenStandardTest.php` (part of `composer test`). Claude Code also gets this checklist
from the `PostToolUse` hook `.claude/hooks/admin-screen-standard.sh`.

Reference implementation: the AI service (`Modules/AI`) — `admin.ai-plans.index` is the full CRUD example,
`admin.ai-settings` a settings screen, the log screens (`admin.ai-requests.index`…) read-only ones.

---

## The checklist (every screen)

| # | What | Where |
|---|------|-------|
| 1 | **Map**: the screen is on its module's map | `resources/js/modules/admin/guide/services/<module>.js` |
| 2 | **Guide**: what it does, who uses it, every field, tasks, what it affects, warnings — Arabic + English, written from the code | `resources/js/modules/admin/guide/screens/<module>/<route without "admin.">.js` |
| 3 | **Permission** on the route | `meta: { middleware: [auth], permission: '<group>.view' }` in `resources/js/modules/admin/routes.js` |
| 4 | **Permission** declared | `AdminPermissionSeeder::permissionGroups()` (a module may keep its list in a class, e.g. `Modules\AI\Support\AiAdminScreens`) **and** a migration that creates the rows and grants `super-admin` (re-running the seeder wipes every role) |
| 5 | **Backend guard** | `'admin.screen:<group>[,<readers>]'` on the screen's route group, or a `CatalogController` (`adminPermissionGroup()`) |
| 6 | **Frontend guard** | sidebar `v-show="can('<group>.view')"`; every button via `useCatalogPermissions('<group>')` (`canCreate`, `canUpdate`, `canDelete`, `canChangeStatus`, `canMultipleDelete`) |
| 7 | **Strings** | both `resources/js/locales/ar.json` and `en.json`; backend messages in `lang/ar` + `lang/en` |
| 8 | **Docs** | the module's `docs/modules/<module>/API.md` / `DATA-MODEL.md` / `CHANGELOG.md` |

New **module** → its map file in the same task (`module: '<Name>'` in the file; registered in `guide/index.js`).

## CRUD screens — built like countries

A CRUD screen (a modal of its own that saves a record) follows the countries CRUD, front and back:

**Backend** — `CatalogController` + `CatalogService` + `TranslatableRepository`:
- translation table `<entity>_translations` (`<entity>_id`, `locale`, the translated columns; unique per locale), model `HasTranslations` + `translations()` / `translationModel()`;
- `status` boolean column (filters, toggle, counts) and `SoftDeletes` (trash);
- routes: index, store, show, update, destroy, `delete-multiple`, `{id}/restore`, `{id}/force`, `{id}/status`, and `dropdown` when other screens pick from it;
- request rules per action (`store/update` + `translations.*`, `changeStatus`, `deleteMultiple`) — one translation per stored language (`LanguageRepository::storableLocaleCodes()`);
- resource: the current-language values + `translations[]` (admin only); **mobile API keys never change** — the text simply arrives in the caller's `X-Locale`.

**What to translate** — the names and descriptions people read (in the panel or the apps). Technical values (patterns,
prompts, JSON settings, codes) stay as they are. A CRUD with nothing to translate states why in its guide:
`tech.noTranslatableText: '<reason>'`.

**Frontend** — `useCatalog` composable + a counts store (`createCatalogStore`), the countries list (search, All / Active /
Inactive / Trash filters with counts, bulk select, restore / force delete, `useCatalogTrashActions`), and a modal with
`CatalogTranslationTabs` + `useCatalogTranslations` (one field) or `useCatalogTranslationFields` (several fields),
`FormFieldFeedback`, `setupCatalogModalWatcher`. A long form uses `<form class="catalog-scroll-form">` inside
`modal-dialog-scrollable` (only the body scrolls; header and save button stay), and its selects open on `body`.

**Migration** — copy existing text into every stored language, rename `is_active` → `status` if needed (keep an
`is_active` accessor and output for old code / the apps), add `softDeletes`; keep relations that must still see a
trashed row `withTrashed()`.

## The guide file

```js
export default {
    route: 'admin.<route>',
    updated: 'YYYY-MM-DD',             // the day it was last checked against the code
    ar: {
        summary, audience,                 // one line each
        purpose: [ ... ],                  // what it is for
        sections: [{ title, items: [{ name, desc }] }],   // every element on the screen
        tasks: [{ title, steps: [ ... ] }],               // step by step
        affects: [{ text, route? }],                      // other screens / apps it changes
        warnings: [{ level: 'danger'|'warning'|'info', text }],
    },
    en: { ...same keys },
    tech: { files: [], api: [], tables: [], permissions: [], decisions: [{ ar, en }], noTranslatableText?: '' },
};
```

Write it **from the code**, not from the field labels: what the backend really does with each value (empty vs 0, limits,
who reads it, what happens on delete). The header comment lists the files you checked.

## The baseline (legacy screens)

`tests/Fixtures/admin-screens/baseline.json` lists the screens and modules that existed before this standard. It **only
shrinks**: bring a screen up to the standard, then delete its line (the test tells you when). Never add to it — the test
also caps its size.

## In the panel

- **"?" in the header** opens the current screen's guide (tabs: overview, on this screen, step by step, warnings, developers). A green dot = the guide is written. The panel follows the admin from screen to screen.
- **Service map** — `/admin/guide/<service>` (sidebar → the service → "دليل الخدمة"): every screen by group, documented or not, first-run steps, the path a request takes. Search and "documented only".

## Files

```
resources/js/modules/admin/guide/
├── index.js                      registry: services, hasScreenGuide, loadScreenGuide, findScreenPlace
├── services/<module>.js          the map: module, groups → screens (route + i18n title key), firstRun, flow
└── screens/<module>/<route>.js   one guide per screen, lazy-loaded
resources/js/components/admin/guide/ScreenGuideDrawer.vue         side panel (mounted once in AdminShell)
resources/js/modules/admin/themes/theme-1/views/guide/index.vue   map page
app/Http/Middleware/AdminScreenPermission.php                     admin.screen:<group> route guard
tests/Feature/AdminScreenStandardTest.php                         the enforcement
```
