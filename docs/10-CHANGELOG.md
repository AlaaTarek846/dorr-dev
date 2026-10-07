# Changelog

All notable changes to this project should be documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/).

---

## [Unreleased]

### Added
- **Referral (v1):** `referral_codes` + `referrals` (owner/referred aliases `user`/`provider`, not FQCN). Server-generated unique `DORRFC-XXXXXX`. Mobile `GET /api/mobile/v1/referrals/my-code` and `POST /api/mobile/v1/referrals/track` (code only; idempotent retry; rejects invalid/inactive/self/second code). Admin list/show codes (deactivate) and referrals (`referral-codes.view|change-status`, `referrals.view`). Android Invite screen loads the code, share URL includes Play `referrer=`, optional apply-code field; pending code from Play Install Referrer is submitted after login. No wallet rewards. Tests: `ReferralTest`.
- Wallet / Android: opening the wallet asks for the fingerprint / face right away when it is set up (a cancel leaves the PIN pad and the fingerprint button; skipped while the PIN is temporarily locked); the wallet bar no longer says "where you are now" (only the "you are in X now" note when away)
- Wallet / Android: Settings no longer lists the wallet (its PIN, recovery and fingerprint settings live in the wallet's own Settings, now titled "Wallet settings"); on the transfer screen the "inside <country> only" note moved to the QR scan screen, the wallet bar no longer shows the currency next to "where you are now", and the phone/wallet-number field is drawn without a card, with a border on the number box
- Wallet / Android polish: the recovery date of birth is typed (day / month / year boxes, digits only, auto-advance, live per-field validation messages) instead of drop-downs; the notifications list no longer draws a dot above the icon; the wallet change/pick sheet and the home "add to status" sheet keep the app language (`LocaleAwareBottomSheet` — sheet windows otherwise use the phone language); the sandbox checkout and result pages (WebView) are in the app language: the app adds `lang` to the checkout URL, `PageTheme::passThrough` carries it checkout → decision → result, and the sandbox page texts moved to `wallet.sandbox_page.*` (en/ar). Test: `WalletSandboxTest`
- **Ratings:** polymorphic `ratings` table (`author` morph = user, nullable `rateable` morph = the app or a `service`/`provider`, one rating per author+target via `unique_key`). Mobile `GET /api/mobile/v1/ratings/mine` and `POST /api/mobile/v1/ratings` (stars are decimal in quarter steps 1–5, e.g. 3.25 or 4.5: below 4 = internal `feedback`, 4 and up = `review` with `prompt_store_review: true`; a second rating is refused with 422). Admin `/api/admin/v1/ratings` (index/show/destroy/delete-multiple, `ratings.view|delete|multiple-delete`) and admin SPA page `rating/` (search, type/stars filters, details modal). Android: `RateAppScreen` saves through the API, shows the existing rating, and launches Google Play In-App Review (`com.google.android.play:review-ktx`) only for saved 4–5 star ratings; feedback is never sent to the store. Tests: `RatingTest`
- **Support tickets, live (like Lee-Taxi):** a ticket is now one conversation between the customer and the support team. Statuses `opened`, `reopened`, `resolved`, `closed` (`SupportTicketStatus`), assigned agent (`admin_id`, taken by the first agent to answer or move it), status history (`support_ticket_activities`), messages with an optional photo and the answering agent. Mobile `GET/POST /api/mobile/v1/support-tickets`, `GET support-tickets/{id}`, `GET/POST support-tickets/{id}/messages` (text and/or photo; `order=desc` = newest page first), `PATCH support-tickets/{id}/status` (customer: close / reopen own ticket). Dashboard `/api/admin/v1/support-tickets*` (list with status / search / mine filters, conversation, activities, reply, change status) behind `support-tickets.view|reply|change-status`, and the admin SPA page `support/` (status cards, live list, conversation modal with photo attach and status control). **Real-time:** `SupportRealtimeEvent` (`support.ticket.created`, `support.message`, `support.ticket.updated`) on the customer's and the admins' private Pusher channels; **notifications** through `NotificationCenter` (in-app list + live toast + OneSignal push in every language, texts `support_ticket_*` in `lang/{en,ar}/notifications.php`) with `data.type = support` + `ticket_id` so a tapped push opens the ticket. Android: `SupportTicketScreen` (live list with status colours and unread dot, create form) and `SupportChatScreen` (conversation, photos, close / reopen, live updates). **Removed:** the general (ticket-less) support live chat — `support-chats` routes, `SupportChatController`, its Android entry and strings; existing general messages are deleted by the migration. Migration `2026_10_07_100000`; tests: `SupportTicketTest`
- Countries can be assigned marketplace services from the admin country modal (`country_service_category` pivot, `service_ids` on create/update). Dashboard only — the public services list is unchanged.
- Uploaded JPEG/PNG images are stored as WebP (`WebpUploadConverter`) so the original file is not kept; GIF, SVG, ICO, existing WebP, fonts, translation JSON, and chat stickers are unchanged
- Dynamic Android translations (ADR-011): public `GET /api/general/v1/translations/{code}/android` (published Android groups merged, `version` hash, `ETag` + `304`) and `GET /api/general/v1/translations/languages?platform=android` (ar/en + languages with published Android `strings`, `android_version`); the list without `platform` is unchanged. Android app: `LanguageApi` uses the new endpoints, `DownloadedTranslations` (one file in `filesDir/translations`, atomic download, metadata in `dorr_app_prefs`), `DynamicResources` (string/plural lookups by entry name, English fallback), layout direction from the saved language, download-then-switch in the Profile and Login language pickers, update check on launch / dialog open, `bundle { language { enableSplit = false } }`. New string `language_download_failed`. Tests added to `TranslationManagementTest`
- Interface translation management (ADR-011): `translation_files` table + `TranslationFile` media model, admin endpoints under `/api/admin/v1/languages/{language}/translations` (overview, CSV/JSON export, validate, import as draft, publish, discard, Android XML ZIP), public `GET /api/general/v1/translations/languages` and `GET /api/general/v1/translations/{code}/vue`, Translations modal on the admin Languages page, runtime loading of published locales (backend loader with `en` fallback, Vue messages without rebuild). Tests: `TranslationManagementTest`, `AndroidStringsXmlTest`
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
- Admin referral codes list (`/admin/referral-codes`) now follows the catalog list (flags) layout: breadcrumb, search with clear, active/inactive filter buttons with counts, PrimeVue owner-type MultiSelect (search + multiple), `crm-contact` rows with avatar, status toggle, catalog pagination, and a two-column details modal
- Admin referrals list (`/admin/referrals`) now follows the same catalog layout: breadcrumb, search with clear, registered/completed/cancelled filter buttons with counts, PrimeVue MultiSelect (search + multiple) for referrer and referred types, `crm-contact` rows with avatars and status badges, catalog pagination, and a two-column details modal
- Android wallet recovery screens: the date of birth is chosen from day / month (named) / year drop-downs inside a card that shows the chosen date in full (days follow the month, a 31 falls back when the month is shorter), and the e-mail method uses the personal-data look — a card with the label, a pill field with a brand icon and one line of help, a "Send code" button, then a code card with four rounded boxes, a 60 s resend timer and "Confirm"; new `WaRecoveryFields.kt` (`WaPillField`, `WaOtpBoxes`, `WaBirthPicker`), the forgot-PIN flow gets the same boxes and date picker; strings `wa_rec_birth_label`, `wa_rec_birth_pick`
- Android wallet: validation and request errors are notifications instead of text inside the page — `WalletHost.showError()` / `showToast(message, error = true)` show a red toast at the top (3.2 s), `WaError(message)` now reports through it (and draws nothing), the PIN pad reports a wrong PIN through it (the boxes still shake), the transfer field messages and the device-trust resend error use it; the toast host is mounted outside the lock check so the PIN gate can show it, and all wallet toasts now appear at the top
- Android wallet: the "PIN" action tile is now "Settings" (settings icon, and the settings menu title); the PIN pad keeps the four hollow dots and its digit keys are filled rounded boxes (field colour in light mode, card colour in dark) with the delete key as just an icon; the statement uses the same rows as the home list (amount with its currency, day and time under it) in one card
- Android wallet PIN entry is now a security menu of three pages, styled like the app settings: **Wallet PIN** (change it: current → new → confirm, with "forgot your PIN?"), **Change recovery method**, and **Unlock with fingerprint or face** (a themed switch; turning it on asks for the PIN once, then the system prompt; a note when the device has no biometrics). The first-PIN setup and the frozen-wallet pages still take over the screen. System back goes from a page to the menu, then out; new strings `wa_security_*`, `wa_pin_menu_*`, `wa_biometric_menu_*`, `wa_on`, `wa_off`, `wa_not_available` (en/ar)
- Android wallet icons all use the app colours: `WaIconWell` ignores the fixed pastel tones (green, amber, pink, blue, gray) and always draws the theme tint / accent glyph (dark-mode safe), and the green "your wallet in…" / "verified" badges are theme-tinted; status result circles (success / failure) keep their meaning colours
- Android wallet transaction sheet: every detail row (date, balance type, balance after, from/to, note, transaction number) has a brand-tinted icon (`WaKeyValue(icon = ...)`), the header well uses the theme colour like the list rows, icon wells are dark-safe in night mode (dark well, no pastel glare) and the ghost button (e.g. Close) is an outlined button in the theme colour
- Wallet colour audit: the payment result page (`payment-result.blade.php`, shown after the gateway decision and before the app's success screen) now follows the app theme like the sandbox checkout — shared `ModulesWalletSupportPageTheme` (six-hex-digit validation, defaults) and the `partials/theme-vars` stylesheet variables; the sandbox form and decision redirect carry the colours on to the result page; Android wallet screens no longer hard-code the old navy/pink/orange brand colours (shadows, glows, rings, selected-method tint, photo frame, scan line, confetti) or white card backgrounds — they use `Wa.Red` tints and `Wa.Surface`; 2 new tests in `WalletSandboxTest`
- Wallet sandbox payment page (`sandbox-checkout.blade.php`) now uses CSS variables instead of fixed red colours; `SandboxCheckoutController` reads `primary`, `bg`, `surface`, `ink`, `mut`, `soft`, `line`, `field` from the query string (exactly six hex digits each, anything else falls back to the default palette), and the Android top-up payment layer passes its theme colours for sandbox URLs only (real gateway URLs are untouched); 2 new tests in `WalletSandboxTest`
- Android wallet home redesigned to the new mock-up (still on the theme colours): outlined round header buttons (back, refresh, hide balance) with the title "My wallet" / "محفظتي" (also on the PIN gate), a balance card with the wallet icon, the big count-up total and two glass tiles (withdrawable / services only, with an info mark), an outlined wallet-number card with copy and QR, outlined action tiles with brand-coloured icons, and recent activity rows showing the amount, currency and day/time at the end; new `WaOutlineCircleButton` and `WaPage(outlined = true)` in `WaTheme.kt`
- Admin wallet forms (wallet adjustment, withdrawal approve/reject, PIN recovery reject, wallet settings, fee rules, payment methods) now use the same client validation as the catalog modals: Vuelidate with only the rule kinds the other pages use (required, string length, integer, min/max value, regex), field feedback icons and inline messages; new helpers `moneyFormat` and `numberRules` in `useValidation` and `composables/useFormFields.js`; anything beyond that stays server-side
- Admin `wallet/wallets` detail modal redesigned with the same hero / info tiles / sections as the other wallet detail modals (statement table as a hoverable section, adjustment form in its own section); `WalletSection` gained a `visible` prop so dropdown panels are not clipped
- Admin wallet detail modals (`online-transactions`, `withdrawals`, `pin-recovery`) redesigned: catalog-style modal header (`WalletModal`), a summary hero with the status badge, info tiles, titled sections with styled tables/forms; new shared components `WalletDetailHero`, `WalletInfoTile` and `WalletSection` in `resources/js/components/wallet/`; new keys `wallet.withdrawals.review` and `wallet.pinrec.review`
- Admin `wallet/settings`: the per-country edit form moved to its own `ModalCreateAndUpdate.vue` (catalog modal style, icon inputs, `toggle` for transfers enabled, PrimeVue `Select` for the fee payer)
- Admin `wallet/fee-rules`: same treatment as payment methods — create/edit form in its own `ModalCreateAndUpdate.vue` (language tabs for name/description, icon inputs, PrimeVue `Select` / `AdminDatePicker`, `toggle` for the status), checkbox column with bulk delete (existing `POST wallet-fee-rules/delete-multiple`, `wallet-fee-rules.multiple-delete`) and `ConfirmDeleteModal` instead of `window.confirm`
- Admin `wallet/payment-methods`: the create/edit form moved to its own `ModalCreateAndUpdate.vue` (catalog modal style, language tabs for name/description, icon inputs, `toggle` switches instead of Bootstrap switches), the list got a checkbox column with bulk delete (existing `POST payment-methods/delete-multiple`) and a `ConfirmDeleteModal` instead of `window.confirm`; `useCatalogTranslationFields` accepts `required: false` for optional translated fields
- All admin wallet pages (`fee-rules`, `financial-entries`, `online-transactions`, `payment-methods`, `withdrawals`, `pin-recovery`, `settings`) now follow the catalog list look (toolbar with search + clear, skeleton loading, icon empty state, `crm-contact` rows with avatar + name link, badges, actions column) and use PrimeVue for every select and date control; new `components/ui/AdminDatePicker.vue` wraps PrimeVue DatePicker with string models; `WalletPagination` footer matches the catalog pages
- Admin `wallet/wallets` page: native selects replaced by PrimeVue `Select` (owner filter, statement filters, adjustment direction/balance type), search clear button, icon empty state, icon input groups and an inline error for the balance type; PrimeVue is now mandatory for every select, multiselect, date and time control (UI-PATTERNS §4)
- `GET /api/mobile/v1/legal-pages` returns only `{ content }` in the request locale (no id, type, status, dates or the full `translations` list)
- SMS providers can support several countries: the admin modal uses a PrimeVue `MultiSelect`, `SmsProviderRequest` validates `countries[]`, `SmsProviderService` syncs the `sms_provider_countries` pivot (it was previously never saved) and `SmsProviderResource` returns `countries` and `priority`; covered by 3 new tests in `SmsModuleTest`
- Android: the system Back button now matches the in-app back — non-Home tabs return to Home, service pages close, and profile sub-screens, personal-data edit pages and the address form step back one level (Home still closes the app)
- `LocaleResolver` supports dynamically published locales and matches region tags (`fr-CA` → `fr`); language `code` must be 2–3 letters
- Vue locale switchers use the interface language list and `language.direction` instead of hardcoded `ar`/`en`
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
