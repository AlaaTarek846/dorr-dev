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
| POST | `/auth/verify` | guest:user_api | MobileAuthController::verifyOtp |
| POST | `/auth/resend` | guest:user_api | MobileAuthController::resendOtp |
| GET | `/auth/me` | auth:user_api + ensure-phone-verified | MobileAuthController::me |
| POST | `/auth/logout` | auth:user_api + ensure-phone-verified | MobileAuthController::logout |

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
- `POST /auth/otp` → `{ masked_phone, is_new_user, resend_cooldown_seconds }`
- `POST /auth/verify` → `{ user, token, token_type: "Bearer" }`
- `POST /auth/resend` → `{ masked_phone, resend_cooldown_seconds }`
- `GET /auth/me` → `UserResource`
- `POST /auth/logout` → `{}`

## Admin-managed Users

See [../admin/API.md](../admin/API.md) — `/api/admin/v1/users/*`

## AI Chat

See [../ai/API.md](../ai/API.md) — `/api/user/v1/ai-chat/*`

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)
