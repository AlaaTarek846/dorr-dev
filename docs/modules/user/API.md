# User — API

Base: `/api/user/v1` (middleware: `locale`)

## Guest

| Method | Endpoint | Controller |
|--------|----------|------------|
| POST | `/login` | UserAuthController |
| POST | `/check-token` | UserAuthController |
| POST | `/register` | UserRegistrationController |
| POST | `/verify-email` | UserRegistrationController |
| POST | `/resend-verification` | UserRegistrationController |
| POST | `/create-password` | UserRegistrationController |
| POST | `/forgot-password` | UserPasswordResetController |
| POST | `/reset-password` | UserPasswordResetController |
| GET | `/countries/dropdown` | CountryController |
| GET | `/countries/detect` | CountryController |

## Authenticated (auth:user_api)

| Method | Endpoint |
|--------|----------|
| GET | `/me` |
| POST | `/logout` |
| POST | `/profile` |
| PUT | `/profile/password` |

## Mobile (mobile-only APIs)

Base: `/api/mobile/v1` (middleware: `locale`, guard: `user_api`)

Dedicated mobile authentication flow — combined login/register by phone number
only. If the phone has no user it is created on the fly (login + register in one
endpoint). OTP is a fixed demo code (`config('auth_flow.phone_otp_fixed')`,
default `123456`) stored in `verification_codes`; the general send helper lives
in `App\Traits\SendsPhoneOtp`. A user cannot reach authenticated endpoints until
`users.phone_verified_at` is set (`EnsurePhoneVerified` middleware).

| Method | Endpoint | Auth | Controller |
|--------|----------|------|------------|
| POST | `/auth/otp` | guest:user_api | MobileAuthController::requestOtp |
| POST | `/auth/otp/restore` | guest:user_api | MobileAuthController::requestRestoreOtp |
| POST | `/auth/verify` | guest:user_api | MobileAuthController::verifyOtp |
| POST | `/auth/resend` | guest:user_api | MobileAuthController::resendOtp |
| GET | `/auth/me` | auth:user_api + ensure-phone-verified | MobileAuthController::me |
| POST | `/auth/logout` | auth:user_api + ensure-phone-verified | MobileAuthController::logout |
| POST | `/profile/phone/request` | auth:user_api + ensure-phone-verified | MobileProfileController::requestPhoneChange |
| POST | `/profile/phone/confirm` | auth:user_api + ensure-phone-verified | MobileProfileController::confirmPhoneChange |
| PUT | `/profile/identity` | auth:user_api + ensure-phone-verified | MobileProfileController::updateIdentity |
| POST | `/profile/avatar` | auth:user_api + ensure-phone-verified | MobileProfileController::updateAvatar |
| DELETE | `/profile/avatar` | auth:user_api + ensure-phone-verified | MobileProfileController::deleteAvatar |
| GET | `/addresses?search=` | auth:user_api + ensure-phone-verified | AddressController::index |
| POST | `/addresses` | auth:user_api + ensure-phone-verified | AddressController::store |
| GET | `/addresses/{id}` | auth:user_api + ensure-phone-verified | AddressController::show |
| PUT/PATCH | `/addresses/{id}` | auth:user_api + ensure-phone-verified | AddressController::update |
| DELETE | `/addresses/{id}` | auth:user_api + ensure-phone-verified | AddressController::destroy |
| PATCH | `/addresses/{id}/set-default` | auth:user_api + ensure-phone-verified | AddressController::setDefault |
| POST | `/profile/email/request` | auth:user_api + ensure-phone-verified | MobileProfileController::requestEmailChange |
| POST | `/profile/email/confirm` | auth:user_api + ensure-phone-verified | MobileProfileController::confirmEmailChange |
| GET | `/faqs` | public | FaqController::index |
| GET | `/legal-pages` | public | LegalPageController::show |

Public catalog content for the app: `/faqs` returns every **active general** FAQ
(`faqs.service_id IS NULL`) ordered by `sort_order`, then `id`; `/legal-pages?type=privacy|term&service_id=` (`type` required, `service_id` optional)
returns the active legal page of that type for the service, or the general one when no
service is given (`data` is `null` when none exists). Service-linked
rows stay admin-only — the mobile app only ever sees the general ones. Both are
localized through the `locale` middleware (`X-Locale` header, `?lang=`, or
`Accept-Language`) and shape their payloads (`FaqResource` for FAQs; legal pages return just `{ content }`).

Request payload (otp / verify / resend): `dial_code` (e.g. `+966`), `phone` (local
digits); verify also sends `code` (6 digits). The user is matched/stored by the
canonical full phone `+<dial><phone>` (same format the dashboard stores).

Phone validation is country-aware (`ValidatesCountryPhone`): the country is
resolved by `dial_code`, then `phone` must match its `phone_length` (exact digits)
and start with `phone_starts_with`. Error keys: `dial_code` →
`phone_invalid_country`; `phone` → `phone_invalid_length` / `phone_invalid_start`.

Request payload (otp / verify / resend): `dial_code` (e.g. `+966`), `phone` (local
digits); verify also sends `code` (6 digits). The user is matched/stored by the
canonical full phone `+<dial><phone>` (same format the dashboard stores).

Responses:
- `POST /auth/otp` → `{ masked_phone, is_new_user, account_state: "active", resend_cooldown_seconds }`.
  For a soft-deleted (restorable) account: `{ masked_phone, account_state: "deleted" }` — **no OTP is sent and
  nothing is restored**; the app must offer the restore step.
- `POST /auth/otp/restore` → `{ masked_phone, account_state: "restore", resend_cooldown_seconds }` (deleted accounts
  only; active accounts get `phone` → `account_not_deleted`). Still does not restore — the code must be verified first.
- `POST /auth/verify` → `{ user, token, token_type: "Bearer", is_restored }`. `is_restored: true` means the account
  was previously soft-deleted and `deleted_at` was set back to `null` on this successful verify.
- `POST /auth/resend` → `{ masked_phone, resend_cooldown_seconds }`
- `GET /auth/me` → `UserResource`
- `POST /auth/logout` `{ player_id? }` → `{}` (the given OneSignal player id is unlinked from the account, so the signed-out phone gets no more messages or calls)
- `POST /profile/phone/request` → `{ masked_phone, resend_cooldown_seconds }`
- `POST /profile/phone/confirm` → `UserResource` (number swapped, re-verified)
- `PUT /profile/identity` (`name`, `gender: male|female`) → `UserResource`
- `POST /profile/avatar` (multipart `avatar`: jpeg/jpg/png/webp ≤ 2MB) → `UserResource` (old file removed)
- `DELETE /profile/avatar` → `UserResource` with `avatar: null` (idempotent)
- `GET /addresses?search=` → `AddressResource[]` (own addresses, newest first, paginated: `page`/`per_page` max 50, meta has `current_page`/`has_more_pages`; `all=1` returns everything)
- `POST /addresses` (`type: home|work|other`, optional title/building/floor/details/landmark/lat/lng/is_default) → `AddressResource` (201)
- `GET /addresses/{id}` → `AddressResource` (own only, else 404)
- `PUT/PATCH /addresses/{id}` → `AddressResource`
- `DELETE /addresses/{id}` → `{}` (soft delete)
- `PATCH /addresses/{id}/set-default` (`is_default: bool`) → `AddressResource` (pinning clears other defaults in a transaction)
- `POST /profile/email/request` → `{ masked_email, resend_cooldown_seconds }`
- `POST /profile/email/confirm` → `UserResource` (address swapped, verified)
- `GET /faqs` → `FaqResource[]` (active general FAQs only, no pagination)
- `GET /legal-pages` → `{ content }` only (the page text in the request locale, falling back to an available language), or `null` when none is published

Phone/email changes are two-step: nothing on the user row changes until the
`confirm` call verifies the code. The pending value lives in Cache with the OTP
expiry TTL; an expired/missing pending value returns `change_request_expired`.

## Admin-managed Users

See [../admin/API.md](../admin/API.md) — `/api/admin/v1/users/*`

## AI Chat

See [../ai/API.md](../ai/API.md) — `/api/user/v1/ai-chat/*`

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)
