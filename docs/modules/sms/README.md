# SMS Module

**Path:** `Modules/Sms/`  
**Namespace:** `Modules\Sms\`  
**Purpose:** SMS provider registry, per-account credential management, and synchronous sending.

---

## Components

| Layer | Classes |
|-------|---------|
| Model | `SmsProvider`, `SmsAccount` |
| Controllers | `SmsProviderController`, `SmsAccountController` |
| Services | `SmsAdapterRegistry`, `SmsProviderService`, `SmsAccountService`, `SmsService`, `SmsAvailabilityService`, `SmsMessageHelper`, `PhoneNumberNormalizer` |
| Exceptions | `SmsException` (implements `App\Exceptions\ApiRenderable`) |
| Requests | `SmsProviderRequest`, `SmsAccountRequest`, `SmsSendTestRequest` |
| Resources | `SmsProviderResource`, `SmsAccountResource` |
| Adapters | `Adapters/BaseSmsAdapter` + 7 provider adapters |
| Routes | `routes/admin.php` |
| Translations | `lang/en/sms.php`, `lang/ar/sms.php` |
| Config | `config/config.php` (merged as `config('sms')`) |

---

## Architecture rules

**A provider holds no sending credentials.** The `SmsAccount` row is the single
source of truth for the credential blob used when sending — there is no
provider-level fallback at send time. The legacy `sms_providers.configuration`
column is now also persisted (encrypted, `encrypted:array` cast) as an *optional*
per-provider config: it seeds the form when adding an account and supports the
provider modal's `test-draft` live check before a provider is saved. Sending
still only ever reads account credentials.

**`SmsAdapterRegistry` is the only place provider keys are known.** Controllers
never hard-code a provider name. Adding a provider means writing an adapter and
registering it in the registry — no controller, model, or resource changes.
Supported keys: `twilio`, `sms_misr`, `four_jawaly`.

**Encryption happens once, in the model.** `SmsAccount` casts
`configuration` as `encrypted:array`. `SmsAdapterRegistry::prepareConfiguration()`
returns a *plaintext* array (normalised against the adapter schema, preserving
stored secrets on edit). If it encrypted as well, the blob would be
double-encrypted and every read would return ciphertext.

**Raw ids, not implicit model binding.** Module routes do not run Laravel's
`SubstituteBindings`, so a route-model-bound parameter arrives as an *empty
model* and `->save()` silently INSERTs. Controllers therefore take
`int|string $sms_account` and the service resolves it with `findOrFail()` —
the same convention as `CountryController`.

**Business errors are thrown, not returned.** `SmsException` implements
`ApiRenderable`, so `ApiExceptionRenderer` turns service-layer throws into the
standard error envelope with a machine-readable `error_code` and no try/catch in
controllers.

**Single default account.** `clearOtherDefaults()` enforces the invariant inside
a transaction on create, update, and `set-default`.

---

## Provider countries

A provider supports **many countries** through the `sms_provider_countries` pivot (`sms_provider_id`, `country_id`, `is_active`, unique pair). The admin API accepts `countries: [country ids]` on create/update (each id must exist and be distinct): an absent key leaves the mapping untouched, an empty array clears it, and the provider resource returns `countries` as a list of ids. The admin modal uses a PrimeVue `MultiSelect`. No schema change was needed — the pivot already allowed several countries.

---

## Usability rules

An account may send only when **all** hold:

1. `provider.is_active = true`
2. `account.is_active = true`
3. `account.test_status = 'passed'`

Editing credentials resets `test_status` to `never_tested`, so a stale pass can
never authorise a send with new secrets.

Phone numbers are normalised to E.164 against the **selected** country
(`SmsSendTestRequest` requires `country_id`), using `Country::dial_code`,
`phone_starts_with` and `phone_length` — `dorr` has no `PhoneNumberService` or
`libphonenumber`. A number belonging to a different country is rejected.

---

## Key Routes

Base: `api/admin/v1` · guard: `admin_api` · locale middleware applies

### Providers

| Method | URI | Action |
|---|---|---|
| GET | `/sms-providers` | index |
| POST | `/sms-providers` | store |
| GET | `/sms-providers/{id}` | show |
| PUT/PATCH | `/sms-providers/{id}` | update |
| DELETE | `/sms-providers/{id}` | destroy |
| PATCH | `/sms-providers/{id}/status` | toggleActive |
| POST | `/sms-providers/delete-multiple` | deleteMultiple |
| GET | `/sms-providers/dropdown` | dropdown |
| GET | `/sms-providers/types` | types (registry metadata for the form) |
| POST | `/sms-providers/{id}/test` | readiness (no live call) |
| POST | `/sms-providers/test-draft` | live test of unsaved provider config/when editing, merged with stored config |

### Accounts

| Method | URI | Action |
|---|---|---|
| GET | `/sms-accounts` | index |
| POST | `/sms-accounts` | store |
| GET | `/sms-accounts/{id}` | show |
| PUT/PATCH | `/sms-accounts/{id}` | update |
| DELETE | `/sms-accounts/{id}` | destroy |
| PATCH | `/sms-accounts/{id}/status` | toggleActive |
| POST | `/sms-accounts/{id}/set-default` | setDefault |
| POST | `/sms-accounts/delete-multiple` | deleteMultiple |
| GET | `/sms-accounts/dropdown` | usable sender options |
| GET | `/sms-accounts/providers-dropdown` | providers for the form |
| POST | `/sms-accounts/test-draft` | test unsaved credentials (never persists) |
| POST | `/sms-accounts/send-test` | send a test SMS |
| POST | `/sms-accounts/{id}/test` | live connection test |
| GET | `/sms-accounts/{id}/balance` | balance (capability-gated) |

---

## Permissions

Groups `sms-providers` and `sms-accounts`, each with
`view`, `create`, `update`, `delete`, `change-status`, `multiple-delete`,
`test`. Defined in `database/seeders/Admin/AdminPermissionSeeder.php`.

---

## Security

- `configuration` is in `SmsAccount::$hidden` and the **Resources** hand-map
  every field, so no secret can leak through raw model serialisation
  (`SmsProvider` encrypts its `configuration` via the `encrypted:array` cast).
- Resources expose per-field `has_value` / `is_set` metadata (no values) so a
  form can show "stored" without reading the secret back.
- Adapter `normalizeError()` strips credential-looking substrings from provider
  error messages before they are stored in `test_error` or returned to a client.

---

## Out of scope (deliberately)

No marketing/campaigns, no queue or jobs, and no `SmsSend` log table. Sending is
synchronous and deliberately leaves no send history. (The admin SPA pages under
`resources/js/modules/admin/themes/theme-1/views/sms-*/` are the module's
frontend; the user/provider SPAs have no SMS surface.)

---

## Testing

- `tests/Unit/SmsAdaptersTest.php` — registry, schemas, capabilities, config
  preparation, error scrubbing, message segmentation, phone normalisation.
- `tests/Feature/SmsModuleTest.php` — permissions, CRUD, credential
  encryption-at-rest, secret preservation, single default, readiness, sending.
- `tests/Feature/SmsProviderConfigTest.php` — provider-level config
  encryption/persistence, secret preservation on edit, no secret leakage via
  resource meta, `test-draft` success/merge/422/403.
