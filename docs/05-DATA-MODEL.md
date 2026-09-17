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
| email_verified_at | nullable |
| status | UserStatus enum |
| gender | Gender enum (added via migration) |
| country_id | FK → countries, nullable |
| remember_token, timestamps | |

**Relationships:** `belongsTo` Country; morphMany SocialAccount, VerificationCode; HasRoles (Spatie).

### `admins`

| Column | Notes |
|--------|-------|
| id | PK |
| name, email | |
| password | |
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

### `currencies` + `currency_translations`

- `currencies`: code, symbol, exchange_rate, is_default, status, timestamps
- `currency_translations`: currency_id, locale, name

### `countries` + `country_translations`

- `countries`: code, code_alpha3, dial_code, phone_starts_with, phone_length, is_default, status, flag_id, currency_id, timestamps
- `country_translations`: country_id, locale, name

**Delete block:** admins relation blocks country delete.

### `service_categories` + `service_category_translations`

- `service_categories`: parent_id (self FK), requires_provider, status, sort_order, timestamps
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
- country_id, status, contact fields (see migration)
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
