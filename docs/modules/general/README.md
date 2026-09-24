# General Module (Shared Catalog)

**Location:** `app/.../General/` (not a nwidart module — shared application layer)  
**Purpose:** Cross-audience platform catalog and settings.

---

## Scope

Shared entities used by Admin (full CRUD) and partially by User (read-only dropdowns):

| Entity | Controller | Service | Repository |
|--------|------------|---------|------------|
| Country | `General\CountryController` | `General\CountryService` | `General\CountryRepository` |
| Currency | `General\CurrencyController` | `General\CurrencyService` | `General\CurrencyRepository` |
| Flag | `General\FlagController` | `General\FlagService` | `General\FlagRepository` |
| Language | `General\LanguageController` | `General\LanguageService` | `General\LanguageRepository` |
| ServiceCategory | `General\ServiceCategoryController` | `General\ServiceCategoryService` | `General\ServiceCategoryRepository` |
| PlatformSetting | `General\PlatformSettingController` | `General\PlatformSettingService` | `General\PlatformSettingRepository` |

---

## Base Classes (outside General/)

- `CatalogController`, `CatalogService`, `TranslatableRepository`
- Concerns: `HasCatalogRules`, `FormatsTranslations`, `ManagesCatalog`, `ManagesBulkAndStatus`, `SyncsTranslations`
- `ExchangeRateService` — currency exchange rate sync

---

## Routes

- **Admin:** `routes/admin.php` (included from `Modules/Admin/routes/admin.php`) — full CRUD
- **User:** `Modules/User/routes/dashboard.php` — `countries/dropdown` and `countries/detect` (public, pre-login)
- **Provider:** `Modules/Provider/routes/dashboard.php` — `countries/dropdown` only
- **Web:** `routes/web.php` — branding via repositories for Blade SPA shells

---

## Frontend (Admin)

- Views: `resources/js/modules/admin/views/{flag,country,currency,language,service-category,platform-settings}/`
- Composables: `useFlags`, `useCountries`, `useCurrencies`, `useLanguages`, `useServiceCategories`
- Stores: count stores via `createCatalogStore.js`

---

## Related Docs

- [TECHNICAL-SPECIFICATION.md](./TECHNICAL-SPECIFICATION.md)
- [API.md](./API.md)
- [DATA-MODEL.md](./DATA-MODEL.md)
- [../../05-DATA-MODEL.md](../../05-DATA-MODEL.md)
