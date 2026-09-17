# General — Testing

## Current Coverage

**No dedicated tests** for General catalog endpoints.

Related: `tests/Feature/ApiExceptionRendererTest.php` covers generic API error behavior.

## Recommended Tests

### Platform Settings
- [ ] GET branding returns 200 without auth
- [ ] POST platform-settings validates app_name
- [ ] Media upload/remove flags

### Catalog CRUD (per entity)
- [ ] List paginated
- [ ] Create with valid translations
- [ ] Create fails without translations
- [ ] Delete blocked (country with admins)
- [ ] Bulk delete
- [ ] Status change
- [ ] Dropdown format

### Currencies
- [ ] sync-exchange-rates endpoint
- [ ] ExchangeRateService cache behavior (unit test)

### Service Categories
- [ ] tree structure
- [ ] leaf-options excludes non-leaf

### Languages
- [ ] storableLocaleCodes affects validation
- [ ] Disabling stores_translation purges translations

## Test Location

Prefer `tests/Feature/General/` (create when implementing).

Use Sanctum:
```php
Sanctum::actingAs($admin, ['*'], 'admin_api');
```
