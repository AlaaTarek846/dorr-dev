# Admin — Data Model

## Table: `admins`

Created: `Modules/Admin/database/migrations/2026_09_14_075327_create_admins_table.php`  
Updated: `Modules/Admin/database/migrations/2026_09_23_100000_add_phone_code_to_admins_table.php`

Key columns (see migration for full schema):
- id, name, email, password
- phone, phone_code (country dial code, e.g. `+20`)
- status
- country_id → countries
- timestamps

## Relationships

- `belongsTo` Country
- Referenced by platform (delete blocks country removal)

## Media

- Avatar collection via Spatie Media Library

## Sanctum

- Tokens in `personal_access_tokens` with admin tokenable type
