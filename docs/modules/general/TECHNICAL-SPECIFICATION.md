# General — Technical Specification

## Namespace Map

```
App\Http\Controllers\General\{Entity}Controller
App\Services\General\{Entity}Service
App\Repositories\General\{Entity}Repository
App\Http\Requests\General\{Entity}Request
App\Http\Resources\General\{Entity}Resource
```

Models remain in `App\Models\` (not under General).

## Inheritance

```
CatalogController
  └── General\{Entity}Controller
        store, update, deleteMultiple, changeStatus

CatalogService + ManagesCatalog
  └── General\{Entity}Service

TranslatableRepository + ManagesBulkAndStatus + SyncsTranslations
  └── General\{Entity}Repository
```

`PlatformSettingController` extends base `Controller` (not CatalogController).  
`PlatformSettingService` extends `BaseService` (not CatalogService).

## Special Behaviors

### CountryRepository
- `$deleteBlockRelations = ['admins']`
- Custom `dropdown()` with flag data

### LanguageRepository
- `storableLocaleCodes()` static — used by validation
- `defaultDashboardLocale()` — used by Blade SPA boot
- Purges translations when `stores_translation` disabled

### ServiceCategoryService
- Image media via `mapImageMedia()` hook
- `tree()` and `leafOptions()` custom endpoints

### CurrencyService
- Injects `ExchangeRateService`
- Syncs rates before list/find

### PlatformSettingService
- Singleton via `PlatformSettingRepository::instance()`
- Media collections defined in `PlatformSettingUpdateRequest::MEDIA_COLLECTIONS`

## Models

| Model | Translation Model | Media |
|-------|-------------------|-------|
| Flag | FlagTranslation | No |
| Language | LanguageTranslation | No |
| Currency | CurrencyTranslation | No |
| Country | CountryTranslation | No |
| ServiceCategory | ServiceCategoryTranslation | Yes (`image`) |
| PlatformSetting | No | Yes (multiple collections) |

## Migrations

`database/migrations/` — see [DATA-MODEL.md](./DATA-MODEL.md)
