# Provider — Technical Specification

## Models

### Provider
- Table: `providers`
- `belongsTo` Country
- `hasMany` ProviderService (services relationship)
- Media via HasMediaTrait

### ProviderService
- Table: `provider_services`
- `belongsTo` Provider
- `belongsTo` ServiceCategory (category)

## CRUD Stack

`ProviderController` → `ProvidersService` → `ProviderRepository`

Same entity CRUD pattern as Admin/User modules (not Catalog base).

## Frontend

- Uses `crudStructure.js` via `useProviders.js`
- Custom status filters (includes blocked count in store)
- Modal: `ModalCreateAndUpdate.vue` with service category selection (leaf options from General API)

## Dependencies

- General `ServiceCategoryController@leafOptions` for category picker
- General Country data for country linkage
