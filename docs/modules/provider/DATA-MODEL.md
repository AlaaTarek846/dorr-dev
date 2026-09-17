# Provider — Data Model

## providers

Migration: `Modules/Provider/database/migrations/2026_09_17_100000_create_provider_profiles_table.php`

Key fields (see migration):
- country_id → countries
- status
- contact/business fields
- timestamps

## provider_services

Migration: `Modules/Provider/database/migrations/2026_09_17_100100_create_provider_services_table.php`

- provider_id → providers
- category_id → service_categories

## Relationships

```
Country ──< Provider ──< ProviderService >── ServiceCategory
```
