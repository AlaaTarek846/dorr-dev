# User — Data Model

## Table: `users`

Migrations:
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `Modules/User/database/migrations/2026_09_15_130000_update_users_table.php`
- `Modules/User/database/migrations/2026_09_15_160000_add_gender_and_country_id_to_users_table.php`
- `Modules/User/database/migrations/2026_09_23_100000_add_phone_code_to_users_table.php`
- `database/migrations/2026_09_15_150000_make_users_password_nullable.php`

Key fields:
- name, email, password (nullable)
- phone, phone_code (country dial code, e.g. `+20`)
- email_verified_at
- status (UserStatus)
- gender (Gender)
- country_id → countries

## Related Tables

- `social_accounts` — polymorphic to User
- `verification_codes` — polymorphic to User
- `personal_access_tokens` — Sanctum
- `ai_conversations` — user_id FK

## Relationships

- belongsTo Country
- morphMany SocialAccount, VerificationCode
- hasMany AiConversation
