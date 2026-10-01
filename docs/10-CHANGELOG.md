# Changelog

All notable changes to this project should be documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/).

---

## [Unreleased]

### Added
- `DELETE /api/mobile/v1/profile/avatar` (`MobileProfileController::deleteAvatar`) and a delete-photo button with confirmation on the Android Personal Data screen
- **Legal pages** replace the Privacy Policy catalog: `legal_pages` / `legal_page_translations` with a `type` (`privacy` | `term`), optional `service_id`, `General/LegalPage{Controller,Service,Repository}`, admin `/api/admin/v1/legal-pages*` (`legal-page.*` permissions), admin SPA `legal-page` views, `LegalPageSeeder`, `LegalPageManagementTest`; mobile `GET /api/mobile/v1/legal-pages` (replaces `/privacy-policy`); Android `PrivacyPolicyScreen` reads it
- Service categories: `audiences` JSON (`ServiceAudience`: admin, user, provider, driver, backfilled from the legacy flags), translatable `description`, drag-and-drop ordering (`PUT /api/admin/v1/service-categories/reorder`, `ServiceCategoryReorderPanel.vue`)
- FAQ drag-and-drop ordering per service (`GET faqs/ordered`, `PUT faqs/reorder`, `FaqReorderPanel.vue`); `sort_order` no longer comes from the form
- Rich-text catalog editor and renderer (`CatalogRichTextEditor`, `CatalogRichTextContent`, `config/richTextEditor.js`, `RichTextSanitizationTest`)
- Android: `ServiceDetailScreen`, `HtmlText`, services section and Home/Services screen updates, language and Type (font) changes
- Live location routes for the mobile chat app — `GET live-locations`, `PUT messages/{m}/live-location`, `POST messages/{m}/live-location/stop`, registered in `Modules/Chat/routes/customer.php` (the `MessageExtrasController` methods existed but nothing routed to them)
- Public mobile catalog content under `Modules/User` — `GET /api/mobile/v1/faqs` (all active general FAQs, `service_id IS NULL`) and `GET /api/mobile/v1/privacy-policy` (the single active general policy), localized via the `locale` middleware
- Chat message extras wired end to end: `poll` / `money_request` / `bill_split` / `gif` / `sticker` types, `MessageResource` `poll` / `payment` / `view_once` / `view_once_opened` / `live_location` / `link_preview` fields, link cards cached on send, view-once files purged by `chat:purge` once everyone opened them
- Group join approval: `approve_joins` setting, `202` pending invites, `groups/{c}/join-requests` queue for admins, and `group.pending_join_requests` on the conversation
- Admin sticker pack routes at `/api/admin/v1/chat-sticker-packs*` (`chat-stickers.*` permissions) plus the Giphy / sticker picker endpoints for the app
- `TransferRecipientResolver::tokenFor()` — a transfer token for someone already known, so a chat money request or split is a normal wallet transfer
- `ch_live_*` Android strings (en/ar) for the live location card, share sheet and foreground notification
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
- Android: the system Back button now matches the in-app back — non-Home tabs return to Home, service pages close, and profile sub-screens, personal-data edit pages and the address form step back one level (Home still closes the app)
- The Privacy Policy catalog and `GET /api/mobile/v1/privacy-policy` are removed (see Legal pages); older entries about them are historical
- README updated with documentation index (project-specific section)
- Privacy policies: a service can now back at most one policy (`service_id` unique among
  non-deleted records; general policies with a null `service_id` stay unlimited). The admin
  modal hides services that already have a policy and surfaces the validation error

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
