# Provider — Data Model

**Last updated:** 2026-09-17

---

## providers

Migration: `Modules/Provider/database/migrations/2026_09_17_100000_create_provider_profiles_table.php`

Key fields (see migration):

- `country_id` → `countries`
- `name`, `email`, `phone`, `gender`, `password`
- `status` (`UserStatus` enum)
- `email_verified_at`, `phone_verified_at`
- `remember_token`, timestamps, soft deletes

### Authenticatable concerns

`Provider` extends `Authenticatable` and uses:

| Trait / interface | Purpose |
|-------------------|---------|
| `HasApiTokens` | Sanctum personal access tokens (`provider_api` guard) |
| `HasSocialAccounts` | Polymorphic `social_accounts` (Google/Apple) |
| `HasVerificationCodes` | Email OTP for registration |
| `HasMediaTrait` | Profile / business media |
| `SoftDeletes` | Admin trash + restore |

---

## provider_services

Migration: `Modules/Provider/database/migrations/2026_09_17_100100_create_provider_services_table.php`

- `provider_id` → `providers`
- `service_category_id` → `service_categories`

---

## Polymorphic auth tables (shared)

### social_accounts

- `authenticatable_type` / `authenticatable_id` → `Provider` or `User`
- Unique on (`provider`, `provider_id`) for OAuth provider key

### verification_codes

- Linked via `HasVerificationCodes` on `Provider`

### personal_access_tokens

- Sanctum tokens for `Provider` model (guard `provider_api`)

---

## Relationships

```
Country ──< Provider ──< ProviderService >── ServiceCategory
                │
                ├── social_accounts (morph)
                ├── verification_codes (morph)
                └── personal_access_tokens (Sanctum)
```
