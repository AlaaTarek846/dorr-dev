# Provider — Requirements

## Implemented

- Admin CRUD for provider profiles
- Status management + bulk delete
- Link providers to country
- Link providers to service categories via `provider_services`
- Media support on provider model
- Admin UI with CRUD composable pattern

## Business Rules (from code)

- Providers table name: `providers`
- ProviderService model links provider to ServiceCategory
- Managed only via admin API (`auth:admin_api`)

## NEEDS-DECISION

- Provider self-registration portal
- Provider visibility on public website
- Provider verification/approval workflow
- Provider user accounts (separate from end User)
