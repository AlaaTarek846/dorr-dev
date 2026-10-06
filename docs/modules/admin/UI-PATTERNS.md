# Admin SPA — UI Patterns (theme-1)

**Last updated:** 2026-10-04
**Reference pages:** `resources/js/modules/admin/themes/theme-1/views/` → `country/` (catalog list + modal), `user/` (list + modal with avatar, password, phone), `profile/` (two-card settings page).

New or restyled admin pages must match these. Copy the structure; do not invent new layouts.

---

## 1. List page (`index.vue`)

Root `<div>` with, in order:

1. **Header** — `div.d-md-flex … my-4 page-header-breadcrumb`: `h1.page-title.fw-semibold.fs-18` with the title and a `badge bg-primary-transparent` total from `pagination.total`; breadcrumb `Dashboard › Title` using `router-link :to="{ name: 'admin.dashboard' }"`.
2. **Card** — `.row > .col-xl-12 > .card.custom-card`:
   - `.card-header` (flex, wrap, `gap-3 py-3`): left = search `input-group input-group-sm` (search icon, clear button) + status filter buttons with counts; right = bulk-delete button (only when rows are selected) and the primary "Add" button (`btn btn-primary btn-sm btn-wave`, `ri-add-line`).
   - `.card-body.p-0 > .table-responsive > table.table.text-nowrap.table-striped.table-hover.mb-0`.
   - `.card-footer.border-top-0`: entries label + arrow icon (flips with `ar`), per-page `select` (15/25/50), `pagination-style-4` nav (`previous`, up to 5 page numbers, `next`).
3. `<ModalCreateAndUpdate>` (props `show`, `type`, `record`; emits `close`, `saved`) and `<ConfirmDeleteModal>` driven by `useConfirmDelete()`.

**Table rules**
- Optional first column checkbox (`canMultipleDelete`), last column actions (`showActionsColumn`, `text-end pe-4`).
- Loading → `<TableSkeleton :rows="8" :columns="tableColumnCount" />`; empty → centered avatar icon + title + text + Add button (`border-0` cell with `colspan`).
- Row `class="crm-contact"`. Name cell: avatar/flag + a `btn btn-link` name (opens edit when `canUpdate`) and `#id` in `text-muted fs-11`.
- Status: a `toggle toggle-success` switch (`catalog-status-toggle`) when `canChangeStatus`, else a `badge …-transparent`. Users use a status `<select>` instead (several states).
- Dates: `formatCatalogDate` with an "updated" sub-line `text-muted fs-11`.
- Actions: `btn btn-sm btn-info-light btn-icon` (`ri-pencil-line`) and `btn-danger-light` (`ri-delete-bin-line`); trashed rows get restore / force-delete.
- Badges: `bg-primary-transparent`, `bg-secondary-transparent`, `bg-success-transparent`, `bg-danger-transparent`.

**Script rules**: `useCatalogPermissions('<resource>')`, an entity composable (`useCountries`, `useUsers`) + Pinia store for counts, `useCatalogTrashActions` for delete/restore/force delete, `storeToRefs`, `tableColumnCount` computed. Catalog filters use `catalog-filter-btn--*` classes (`styles/catalog-list.css`); users still use `users-filter-btn--*`.

## 2. Create/edit modal (`ModalCreateAndUpdate.vue`)

- Bootstrap modal controlled with `window.bootstrap.Modal`; `modal-dialog modal-dialog-centered modal-lg`. Header `catalog-modal-header` (title + `btn-close`), body `px-4 pb-2`, footer `catalog-modal-footer` (Close `btn-light`, Save `btn-primary btn-wave`, disabled + "saving" text while submitting).
- Wiring via `setupCatalogModalWatcher({ props, fillForm, resetForm, openModal, closeModal, resourceUri, onOpen })`; emit `close` / `saved`.
- Translatable fields: `CatalogTranslationTabs` + `useCatalogTranslations`.
- Each field: `label.form-label` (+ `<span class="text-danger">*</span>`), `input-group` with `input-group-text.bg-light` icon (`ri-*`), input with `is-invalid` / `is-valid` classes from `fieldFeedback`, `FormFieldFeedback`, and an `invalid-feedback d-block` message. Server errors go through `applyApiErrors(serverErrors, …)`.
- Validation: Vuelidate (`useVuelidate` with `$autoDirty`) + `useValidation` helpers (`requiredField`, `stringFieldRules`, `digitsBetween`); on invalid show `toast.validation_error`.
- Selects: PrimeVue `Select` / `MultiSelect` with `append-to="self"`, `class="w-100"`, option templates with `FlagImage`; shared selects `FlagSelect`, `CurrencySelect`, `CountrySelect`, `PhoneCountryInput`.
- Booleans: `toggle toggle-primary|toggle-success` + `catalog-modal-toggle`, `role="button"`, Enter/Space handlers — not Bootstrap `form-switch`. Plain `form-check-input` checkboxes are for table row selection and per-row enable flags.
- Entities with several translated fields (name + description…) use `useCatalogTranslationFields` (`required: false` for optional ones) with `CatalogTranslationTabs`; modals live in their own `ModalCreateAndUpdate.vue` next to `index.vue` (see `wallet/payment-methods` and `wallet/fee-rules`).
- Feedback: `useToast` (`showSuccess`, `showError`, `showWarning`, `extractApiMessage`, `extractApiErrorMessage`); 422 → field errors.
- Passwords (`user/`): show/hide button, `calculatePasswordStrength` bar, `generateSecurePassword`, required only on create or when typed.

## 3. Settings/profile page (`profile/index.vue`)

- Header: `page-header-breadcrumb` with title (`fw-semibold fs-18`) and muted subtitle.
- `div.row.g-4` of `col-xl-6` cards: `.card.custom-card > .card-header > .card-title` + `.card-body > form`. Sections separated by `h6.fw-semibold.mb-3`.
- Avatar block: `avatar avatar-xxl avatar-rounded` with a camera badge file input, "Change photo" (`btn-primary`) and "Remove photo" (`btn-light`) in a `btn-group`; allowed types jpeg/jpg/png/webp.
- Fields use the same icon `input-group` + inline `invalid-feedback d-block` pattern; gender via PrimeVue `Select`; phone via `PhoneCountryInput`.

## 4. PrimeVue controls (mandatory)

Every select, multiselect, date and time control in the admin SPA uses PrimeVue — never a native `<select>`, `<input type="date|time|datetime-local">`:

| Need | Component |
|------|-----------|
| Single choice / filter | `primevue/select` |
| Several choices | `primevue/multiselect` (`display="chip"`) |
| Date, time, date-time | `components/ui/AdminDatePicker.vue` (wraps `primevue/datepicker`; `show-time` / `time-only`) |

Rules: `option-label` / `option-value`; `append-to="self"` inside Bootstrap modals; filters that include an "All" choice put it first in the options with value `""`; bind `:invalid` and show an `invalid-feedback d-block` message for validation; build option lists as `computed` so labels follow the locale. `AdminDatePicker` keeps the string formats pages already send (`YYYY-MM-DD`, `YYYY-MM-DDTHH:mm` with `show-time`, `HH:mm` with `time-only`), so v-models and payloads stay strings. Filter selects use the `wallet-filter-select` class (min-width, `styles/catalog-list.css`) instead of Bootstrap `w-auto`.

Pages migrated: every page under `views/wallet/` (`wallets`, `fee-rules`, `financial-entries`, `online-transactions`, `payment-methods`, `withdrawals`, `pin-recovery`, `settings`). Any other admin page that still has native controls must be migrated when touched.

## 5. Form validation (mandatory)

Every admin form validates on the client the same way as the catalog modals — and only with the rule kinds those pages already use:

- Vuelidate (`useVuelidate(rules, form, { $autoDirty: true })`) with `required`, string `minLength` / `maxLength`, `integer`, `minValue` / `maxValue`, `email`, `sameAs` (password confirmation) and `regex` (format). Helpers in `composables/useValidation.js`: `requiredField`, `stringFieldRules`, `minString` / `maxString`, `digitsBetween`, `numberRules(fieldKey, { min, max, integerOnly })` (integer + min/max with the standard messages) and `moneyFormat` (regex for amounts typed in major units, up to 2 decimals).
- Do **not** add rule kinds the other pages do not have (cross-field comparisons, date-after-date, file type/size, conditional required, per-row limits). Anything else is enforced by the backend Form Request and its 422 errors are shown inline.
- `composables/useFormFields.js` gives the template `feedbackOf`, `classOf`, `invalidOf`, `messageOf` and `onInput` (pass `serverKeys` when the API error key differs from the form key). Each input: `input-group` with `FormFieldFeedback`, `:class="classOf(key)"`, and an `invalid-feedback d-block` line with `messageOf(key)`.
- Submit with `await v$.value.$validate()`; when invalid show `toast.validation_error`, open the tab that holds the problem, and stop. 422 responses go through `applyApiErrors(serverErrors, errors)`.
- Messages come from `validation.*` in both locale files; never hard-code them.

## 6. Always

- Strings via `t()` in **both** `locales/ar.json` and `locales/en.json`; RTL-safe (use `ms-*`/`me-*`, `text-start`/`text-end`).
- Routes registered in `modules/admin/routes.js` with `meta.permission`; sidebar entry in `components/layout/admin/Sidebar.vue`.
- Run `npm run build` after changes.
