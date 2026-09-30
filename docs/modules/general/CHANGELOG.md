# General — Changelog

## [Unreleased]

### Changed
- FAQ ordering is per service (general FAQs are one group): new FAQs go last in their group,
  moving a FAQ to another service puts it last there, and `sort_order` is no longer accepted
  from the create/edit form. The admin FAQ list is ordered newest first and no longer shows
  the sort column; ordering is done from a drag-and-drop panel with a service select
  (default "General"), like service categories.

### Added
- `GET /api/admin/v1/faqs/ordered` and `PUT /api/admin/v1/faqs/reorder`, `FaqReorderPanel.vue`
- `FaqSeeder` and `PrivacyPolicySeeder` (bilingual content, `sort_order`, optional
  `service_id` linkage, idempotent upsert on the English translation) plus registration in
  `DatabaseSeeder` after `ServiceCategoriesSeeder`
- `syncTranslationFields()` in `SyncsSeedTranslations` for catalogs with more than one
  translated field (the name-only `syncTranslations()` is unchanged)
- `CatalogContentSeederTest` covering locale completeness, ordering, service linkage,
  idempotency, and allow-list compliance of seeded rich text
- FAQ catalog: `faqs` / `faq_translations` tables, model, repository, resource, request,
  service, controller, and admin CRUD/trash/status/bulk routes with `faqs.*` permissions
- Privacy Policy catalog: `privacy_policies` / `privacy_policy_translations` tables, model,
  repository, resource, request, service, controller, and admin routes with
  `privacy-policy.*` permissions
- Admin frontend: FAQ and Privacy Policy list pages, create/edit modals, stores, composables,
  routes, sidebar entries, and `ar` / `en` locale keys
- `ResolvesTranslatableFields` concern; generalized translation sync in
  `SyncsTranslations`, `HasTranslations`, and `FormatsTranslations` so a catalog can expose
  more than one translatable field (name-only behavior unchanged)
- `translationSearchColumns()` override in `SearchFilterTrait`
- Feature coverage in `FaqManagementTest`, `PrivacyPolicyManagementTest`, and
  `CatalogTranslationsTest`

### Fixed
- FAQ and Privacy Policy modals: pass the required `translationTabFeedback` prop to
  `CatalogTranslationTabs` (the tabs call it in their template, so a missing prop broke the
  language tab bar)
- `useCatalogTranslationFields`: default the active translation tab to the current UI locale
  when it is a storable language, instead of always the first one
- FAQ and Privacy Policy modals: guard the form with `v-if="activeLocale"` and show a spinner
  while languages load. The `v-model="form.translations[activeLocale]…"` bindings rendered
  before the async language fetch resolved and threw
  `Cannot read properties of undefined (reading 'content')`

### Changed
- Documented General namespace structure
- Privacy policies: a service can now back at most one policy. `service_id` is validated
  as unique among non-deleted records (general policies with a null `service_id` remain
  unlimited, since no service is involved); soft-deleted policies free their service for
  reuse. The admin create/edit modal hides services that already have a policy and shows
  the `service_id` validation error.

## [2026-09-17]

### Changed
- Refactored shared catalog code into `app/.../General/` namespaces
- Updated route imports to `App\Http\Controllers\General\*`

### Documented
- Initial module documentation created

## Historical

See git history for catalog entity additions prior to General refactor.
