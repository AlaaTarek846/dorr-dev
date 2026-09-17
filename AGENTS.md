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
| Shared catalog | `app/Http/Controllers/General/`, `app/Services/General/`, etc. |
| Modules | `Modules/Admin`, `User`, `AI`, `Provider` |
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
- i18n: update **both** `locales/ar.json` and `locales/en.json`

---

## After Implementation

1. Run `composer test` (and `npm run build` if frontend changed)
2. Update docs if API, schema, or architecture changed
3. Report: files changed, tests run, remaining TODO, NEEDS-DECISION items

---

## Documentation Index

See [README.md](README.md#documentation) for the full docs list.
