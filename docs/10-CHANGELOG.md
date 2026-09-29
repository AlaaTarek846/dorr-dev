# Changelog

All notable changes to this project should be documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/).

---

## [Unreleased]

### Added
- FAQ catalog (`faqs` / `faq_translations`) and Privacy Policy catalog (`privacy_policies` / `privacy_policy_translations`) in the shared `General/` namespace — optional `service_id` → `service_categories.id`, `status`, `sort_order`, soft deletes, multilingual fields
- Admin CRUD/trash/status/bulk APIs at `/api/admin/v1/faqs*` (`faqs.*` permissions) and `/api/admin/v1/privacy-policies*` (`privacy-policy.*` permissions)
- Admin SPA pages for both catalogs: list views, create/edit modals, Pinia stores, composables, routes, sidebar entries, and `ar` / `en` locale keys
- `ResolvesTranslatableFields` concern plus generalized translation sync/response/formatting and `translationSearchColumns()` in `SearchFilterTrait`, enabling catalogs with more than one translatable field
- Feature tests: `FaqManagementTest`, `PrivacyPolicyManagementTest`, `CatalogTranslationsTest`
- `Modules/Sms`: 4Jawaly (4jawaly.com) SMS provider adapter — key `four_jawaly`, HTTP Basic auth, send/senders/packages endpoints
- `Modules/Chat`: WhatsApp-style chat backend (conversations, groups, messages, wallet cards, contacts, privacy, presence, LiveKit calls, push, admin `chat-settings`). API in `docs/modules/chat/API.md`.
- Professional documentation system under `docs/`
- Module documentation under `docs/modules/`
- `AI-INSTRUCTIONS.md` for AI coding assistants
- `AGENTS.md` — agent entry point for all AI tools
- `.cursor/rules/` — Cursor rules for automatic workflow enforcement
  - `dorr-ai-workflow.mdc` (always apply)
  - `dorr-laravel-backend.mdc` (PHP files)
  - `dorr-vue-frontend.mdc` (Vue/JS files)

### Changed
- README updated with documentation index (project-specific section)

---

## [Documentation Initial] — 2026-09-17

### Added
- Initial Documentation System
- Product requirements, specification, technical spec
- Architecture, data model, API specification
- Implementation plan, roadmap, decisions, checkpoint
- Testing, security, deployment, contributing, style guide guides
- AI instructions for consistent AI-assisted development

### Documented (pre-existing implementation)
- General/ namespace refactor for shared catalog code
- Dual SPA architecture (admin + user)
- Modules: Admin, User, AI, Provider
- Sanctum dual-guard authentication
- Catalog entities with translation pattern
- AI chat and provider configuration
- Provider management

---

## Historical Changes

**TODO:** Prior git history not summarized in this initial changelog.  
Use `git log` to backfill significant releases when needed.

---

## Categories

- **Added** — new features
- **Changed** — changes in existing functionality
- **Deprecated** — soon-to-be removed
- **Removed** — removed features
- **Fixed** — bug fixes
- **Security** — security fixes
- **Breaking Changes** — incompatible API or schema changes
