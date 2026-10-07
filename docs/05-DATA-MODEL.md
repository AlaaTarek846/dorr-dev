# Data Model

> Derived from migrations and Eloquent models. Do not treat undocumented columns as confirmed.

---

## Entity Relationship Overview

```
flags ──┬── flag_translations
        ├── languages
        └── countries

languages ── language_translations

currencies ──┬── currency_translations
               └── countries

countries ──┬── country_translations
            ├── admins (country_id)
            ├── users (country_id)
            └── providers (country_id)

service_categories (self-referential parent_id)
  └── service_category_translations
  └── provider_services

providers ── provider_services ── service_categories

users ──┬── ai_conversations ── ai_messages
        ├── social_accounts (polymorphic)
        └── verification_codes (polymorphic)

ai_providers (standalone config)

platform_settings (singleton-style usage)

media (polymorphic, Spatie)

permissions / roles / model_has_* (Spatie, unused in routes)
personal_access_tokens (Sanctum)
```

---

## Core Tables

### `users`

| Column | Notes |
|--------|-------|
| id | PK |
| name, email | email unique |
| password | nullable (registration flow) |
| phone | nullable |
| email_verified_at | nullable |
| status | UserStatus enum |
| gender | Gender enum (added via migration) |
| country_id | FK → countries, nullable |
| remember_token, timestamps | |

**Relationships:** `belongsTo` Country; morphMany SocialAccount, VerificationCode; HasRoles (Spatie); `hasMany` SupportTicket.

### `support_tickets`

| Column | Notes |
|--------|-------|
| id | PK |
| user_id | FK → users, cascade on delete |
| title | string |
| body | text |
| image_path | nullable, public disk `support-tickets/{userId}` |
| status | default `open` |
| timestamps | |

`status` is `opened` / `reopened` / `resolved` / `closed` (`SupportTicketStatus`); `admin_id` (nullable FK → admins) is the agent who took it; `last_message_at` orders the lists. Opened from the Android app via `POST /api/mobile/v1/support-tickets`; the dashboard answers and moves the status (`support-tickets.*` permissions, module `system_users`). The history of status moves is in `support_ticket_activities` (`support_ticket_id`, `admin_id` nullable, `actor` user|support, `status`, timestamps).

### `ratings`

| Column | Notes |
|--------|-------|
| id | PK |
| author_type, author_id | morph (the user who rated) |
| rateable_type, rateable_id | nullable morph; null = the app itself. Allowed aliases: `service` (ServiceCategory), `provider` |
| unique_key | char(64) unique — sha256 of author + target, prevents duplicate ratings (NULL morphs cannot be unique) |
| stars | decimal(3,2), 1–5 in 0.25 steps |
| type | `feedback` (< 4) or `review` (≥ 4) |
| comment | nullable text |
| timestamps | |

Admin: view/delete only (`ratings.*` permissions, module `system_users`).

### `referral_codes`

| Column | Notes |
|--------|-------|
| id | PK |
| code | unique, format `DORRFC-` + 6 A–Z/0–9 |
| referrable_type, referrable_id | owner alias (`user` / `provider`; later `driver`) — not a class name, not `Relation::morphMap()` |
| is_active | bool; deactivating does not delete history |
| timestamps | |

One active code per owner is created on first `GET /api/mobile/v1/referrals/my-code`.

### `referrals`

| Column | Notes |
|--------|-------|
| id | PK |
| referrer_type, referrer_id | owner of the code used |
| referred_type, referred_id | unique pair — one referral per referred entity |
| referral_code_id | FK → referral_codes, restrict on delete |
| status | `registered` / `completed` / `cancelled` |
| registered_at, completed_at, cancelled_at | nullable |
| timestamps | |

Mobile: `GET/POST /api/mobile/v1/referrals/*`. Admin: `/api/admin/v1/referral-codes*`, `/api/admin/v1/referrals*` (`referral-codes.view|change-status`, `referrals.view`).

Completion (wallet rewards) is a later hook — v1 stores `registered` only.

### `support_messages`

| Column | Notes |
|--------|-------|
| id | PK |
| user_id | FK → users, cascade on delete (the customer of the ticket) |
| admin_id | nullable FK → admins (the agent who wrote it) |
| support_ticket_id | FK → support_tickets |
| sender | `user` or `support` |
| body | nullable text (a message can be a photo only) |
| image_path | nullable, public disk |
| timestamps | |

Mobile: `GET/POST /api/mobile/v1/support-tickets/{id}/messages`; dashboard: `GET/POST /api/admin/v1/support-tickets/{id}/messages`. Live on the Pusher channels via `SupportRealtimeEvent`. The old general (ticket-less) chat no longer exists.

### `admins`

| Column | Notes |
|--------|-------|
| id | PK |
| name, email | |
| password | |
| phone | nullable |
| status | |
| country_id | FK → countries |
| timestamps | |

**Relationships:** `belongsTo` Country; media (avatar).

### `flags` + `flag_translations`

- `flags`: code, status, timestamps
- `flag_translations`: flag_id, locale, name

**Relationships:** Flag hasMany translations, languages, countries.

### `languages` + `language_translations`

- `languages`: code, direction (TextDirection), status, stores_translation, is_default_dashboard, is_default_website, flag_id, timestamps
- `language_translations`: language_id, locale, name

### `translation_files`

- Interface translation file metadata per language: language_id (FK cascade), platform (`backend`/`vue`/`android`), group, status (`draft` = pending draft exists, `published`), checksum (sha256 of the draft), version, published_at, created_by / updated_by (FK `admins`, null on delete), timestamps
- Unique `(language_id, platform, group)`
- File contents live in Spatie media collections `draft` and `published` (single file each, disk `config('translations.disk')`, default private `local`); no file paths stored on the row
- `ar` / `en` never get rows — they stay in `lang/*` and `resources/js/locales/*.json`

### `currencies` + `currency_translations`

- `currencies`: code, symbol, exchange_rate, is_default, status, timestamps
- `currency_translations`: currency_id, locale, name

### `countries` + `country_translations`

- `countries`: code, code_alpha3, dial_code, phone_starts_with, phone_length, is_default, status, flag_id, currency_id, timestamps
- `country_translations`: country_id, locale, name
- `country_service_category`: pivot (country_id, service_category_id unique) — which marketplace services an admin assigned to that country (dashboard only; public `/services` is unfiltered)

**Delete block:** admins relation blocks country delete.

### `service_categories` + `service_category_translations`

- `service_categories`: parent_id (self FK), module_name (unique, nullable), is_login_dashboard, is_auto_assign, requires_provider, status, sort_order, timestamps
- `service_category_translations`: service_category_id, locale, name

**Relationships:** parent/children self-reference; media collection `image`.

### `platform_settings`

- Singleton row pattern via repository `instance()`
- `app_name` + media collections for branding assets

### `verification_codes`

- Polymorphic: `authenticatable_type`, `authenticatable_id`
- code, type (VerificationType), purpose (AuthFlowPurpose), expires_at, attempts, etc.

### `social_accounts`

- Polymorphic authenticatable
- provider (SocialProvider), provider_id, token fields

### `providers`

- Business provider profile (table name `providers`)
- country_id, status, phone, contact fields (see migration)
- media support

### `provider_services`

- provider_id → providers
- category_id → service_categories (ServiceCategory model)
- Pivot-like service offerings per provider

### `ai_providers`

- key (AiProviderKey enum: openai, anthropic, google, groq)
- api_key, model, enabled, is_default
- live_models_cache (JSON, added via migration)

### `ai_conversations`

- user_id → users
- title, timestamps

### `ai_messages`

- conversation_id → ai_conversations
- role, content, metadata

---

## Spatie / Laravel Infrastructure Tables

| Table | Purpose |
|-------|---------|
| `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` | Spatie Permission |
| `media` | Spatie Media Library |
| `personal_access_tokens` | Sanctum |
| `cache`, `cache_locks` | Cache driver |
| `jobs`, `job_batches`, `failed_jobs` | Queue |
| `sessions` | Session driver |
| `password_reset_tokens` | Laravel password broker table |

---

## Relationship Types (Confirmed)

| Type | Example |
|------|---------|
| One-to-Many | Country → CountryTranslations |
| One-to-Many | Country → Admins |
| Self-referential | ServiceCategory parent/children |
| Many-to-Many-like | Provider → ProviderService → ServiceCategory |
| Polymorphic | SocialAccount → User/Admin |
| Polymorphic | VerificationCode → User |
| Polymorphic | Media → various models |

**No polymorphic relations documented beyond above.**

---

## Soft Deletes

**UNKNOWN / Not found** — no `SoftDeletes` trait observed on core models during analysis. Treat as **hard delete** unless model explicitly uses soft deletes.

---

## Indexes & Constraints

Refer to individual migration files in:
- `database/migrations/`
- `Modules/*/database/migrations/`

Foreign keys defined in create migrations (e.g., `country_id`, `flag_id`, `parent_id`).

---

## Important Database Rules (from code)

1. Catalog translation locales must match languages where `stores_translation=true` and `status=true`
2. Disabling language translation storage purges related catalog translations for that locale
3. Exclusive defaults: `is_default_dashboard`, `is_default_website` on languages (only one true)
4. Delete blocked when `deleteBlockRelations` non-empty (e.g., country → admins)
5. User password nullable until registration flow completes

---

## Seeders

- `database/seeders/General/` — Country, Flag, Language, Currency seeders
- Module seeders: `Modules/*/database/seeders/`

See seeder files for initial data conventions.

---

## Chat (`Modules/Chat/database/migrations`)

| Table | Purpose |
|---|---|
| `chat_settings` | One row of admin limits (group size, file size, edit and delete windows…) |
| `chat_conversations` | `type` direct\|group, `status` pending\|accepted\|rejected (message requests), `direct_key` unique, `last_message_id/at`, `disappearing_seconds` |
| `chat_groups` | Name, description, avatar (media), `invite_token`, admin-only switches |
| `chat_participants` | One person in one chat, plus that person's own settings: role, read and delivered marks, unread, pin, archive, lock, mute, cleared, deleted, theme |
| `chat_messages` | `uuid` (idempotent), `type`, `body`, `meta` (location, contact, voice, wallet card, call, system), `reply_to_id`, forwarding, `mentions`, `edited_at`, `deleted_for_everyone_at`, `expires_at`. Files are in media collection `attachments` |
| `chat_message_receipts` | Group only: per member `delivered_at` and `read_at` |
| `chat_message_reactions` / `chat_message_user_states` / `chat_pinned_messages` | Reactions, per-person star or delete-for-me, and pins |
| `chat_contacts` / `chat_blocks` / `chat_privacy_settings` | Address book (E.164 phone matched to an account), blocks, privacy, QR token, last seen |
| `chat_folders` / `chat_folder_conversations` | Personal chat folders |
| `chat_calls` / `chat_call_participants` | LiveKit call log and ringing state |

Participant columns (`*_type`) hold an alias (`user`, `provider`) and never a class name (see `Modules\Chat\Support\ParticipantType`).
