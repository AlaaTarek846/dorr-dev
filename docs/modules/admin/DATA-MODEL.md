# Admin — Data Model

## Table: `admins`

Created: `Modules/Admin/database/migrations/2026_09_14_075327_create_admins_table.php`

Key columns (see migration for full schema):
- id, name, email, password
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
