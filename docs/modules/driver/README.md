# Driver Module (`Modules/Driver`) and the driver app (`driverApp/`)

A driver is **their own account** — not a `Provider` (owner's decision, 2026-10-13; Providers get a separate third app later). One driver account serves every DORR service they work in; each service has its own eligibility. Ride-specific data (categories, Wasl, earnings, points) lives in [Modules/Rides](../rides/README.md). The plan is [../../rides-plan.md](../../rides-plan.md) §4.1, the tables [../../rides-structure.md](../../rides-structure.md) §1.

## Account and sign-in
- **Guard:** `driver_api` (Sanctum, provider `drivers`). Morph alias `driver` (registered by `DriverServiceProvider`, like `user` / `provider`), so tokens, OTP codes and notification devices store `driver`.
- **Same phone, two accounts:** a person can be a rider in `users` and a driver in `drivers`; the two tokens never open each other's API.
- **Sign-in:** phone + OTP, the same flow and fixed demo code as the rider app. An unknown phone becomes a new driver in `pending_documents`, in the country of its dial code. Only a `blocked` driver is turned away; `suspended` / `restricted` drivers sign in and see `status_reason` (D-9).
- **Wiring done in phase 0:** `config/auth.php`, the wallet's and the referral's alias maps (`driver`), and `Broadcast::routes` + channel `Modules.Driver.Models.Driver.{id}`. The chat's `driver` participant is switched on in phase 1 (ride chat).

## Tables (phase 0)
`drivers`; `driver_document_types` (+ translations) and `driver_document_requirements` (per country, service and residency — `null` = citizens and residents, or one of them); `drivers.residency` (`citizen` / `resident`, set by the admin, any country); `vehicle_makes` / `vehicle_models` / `vehicle_colors` (+ translations) and `vehicles` (plate letters / number / type and sequence number, the way Wasl wants them); `driver_documents` (files: media `front` / `back`); `driver_service_eligibility`; `driver_presence` (one row per driver: state and position); `driver_presence_sessions`; `driver_location_batches` + `driver_location_log` (the 30-second log, append-only). Coordinates are plain decimals — no spatial SQL, because the tests run on SQLite.

`DriverDatabaseSeeder`: 9 document types (4 required for Saudi passenger rides) and a starting vehicle catalog. Safe to run again; it leaves deleted rows deleted.

## API: `driver/v1/*`
Middleware: `locale`. Signed-in routes: `auth:driver_api`, `ensure-phone-verified:driver_api`, `throttle:60,1`, `remember-locale`.

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `auth/otp` | `dial_code`, `phone` | `{masked_phone, is_new_driver, resend_cooldown_seconds}` (guest, `throttle:10,1`) |
| POST | `auth/resend` | `dial_code`, `phone` | `{masked_phone, resend_cooldown_seconds}` |
| POST | `auth/verify` | `dial_code`, `phone`, `code` | `{driver, token, token_type}` — a new sign-in ends the old token |
| GET | `auth/me` | | `Driver`: `uuid, name, phone, email, gender, date_of_birth, country_id, city_id, locale, status, status_reason, registered_by, activated_at, onboarding_ends_at, avatar_url` |
| POST | `auth/logout` | `player_id?` | Ends the token and unregisters that phone's push id |

## Signing up in the app (`driver/v1`)
| Method | Path | Notes |
|---|---|---|
| GET | `onboarding` · `onboarding/options` | Steps done, `required_documents` (country + citizen/resident + service, each with the driver's latest upload and its status / rejection reason), the vehicle, `missing`, `can_submit`, `work_problem` · the pickers (vehicle catalog, nationalities, cities) |
| PUT · POST | `profile` · `profile/avatar` | Name, email, city; identity fields (ID, birth date, nationality) only until the account is active. Residency is the admin's |
| POST · PUT | `vehicles` · `vehicles/{id}` | A pending vehicle; an approved one can't be edited from the app (`409 driver_vehicle_locked`) |
| POST | `documents` | Multipart, waits for review; an expired document is refused; a vehicle document goes to the vehicle being signed up with |
| POST | `onboarding/submit` | `pending_documents → under_review` once nothing is missing (`422 driver_onboarding_incomplete` with `missing`) |
| POST · DELETE | `notifications/devices` | The phone's OneSignal player id — ride requests reach a closed app through it (same endpoints as the rider app, `routes/notifications.php`) |

`DocumentRequirements` is the one place that says which types a driver needs (the work gate uses it too); `DriverRecords` writes vehicles and documents for both the app and the dashboard.

## Admin: `admin/v1/drivers*` and `admin/v1/driver-document-types*`
Permissions `drivers.{view,create,update}` and `driver-document-types.{view,create,update,delete}` (module `passenger_ride`); every change is audited (`audit_logs`). Screens: Admin → Rides → Drivers / Document types (shown when the header service is passenger rides).
| Method | Path | Notes |
|---|---|---|
| GET | `drivers` · `drivers/options` | Filters `search` (name / phone / ID), `status`, `residency`, `country_id`, `registered_by`, `pending_documents`; the options are everything the forms pick from |
| POST · PUT | `drivers` · `drivers/{id}` | Register / edit: name, local phone + country (stored `+<dial><local>`), `residency` (citizen / resident), ID, birth date, nationality… `registered_by = admin` |
| GET · PATCH | `drivers/{id}` · `drivers/{id}/status` | The page (documents with their files, vehicles) · status with a reason (anything but active needs one); not active → offline at once; blocked → signed out |
| POST · PUT · PATCH · POST | `drivers/{id}/vehicles` · `…/{vehicle}` · `…/{vehicle}/status` · `…/{vehicle}/activate` | Plate unique per country; the first approved vehicle becomes the one in use |
| POST · PATCH · DELETE | `drivers/{id}/documents` (multipart `front`, `back`) · `…/{document}/review` · `…/{document}` | Uploaded by the admin = approved unless `approve=0`; approving replaces the older approved one; rejecting needs a reason the driver sees |
| GET · POST · PUT · PATCH · DELETE | `driver-document-types…` | A type with its names, instructions and requirements (country, service, residency); off = required from nobody; a type already used can't be deleted (`409 driver_document_type_in_use`) |
| GET · POST · POST | `drivers/{id}/regulator` · `…/register` · `…/check` | (Modules/Rides) the Wasl box on the driver's page |

## The driver app (`driverApp/`)
A separate Gradle project (not a module of `androidApp/`): `:app` (package `com.dorr.driver`, store name proposed "DORR Captain" / «دُرر كابتن») and `:core`, which holds what both apps can share — the API envelope and `ApiResult`, `CoreHttp` (headers, Bearer, 401 hook, `Idempotency-Key`), `TokenStore` (one session file per app) and `DorrTheme` (logo navy and orange, a dark scheme for night driving). `androidApp` doesn't use `:core` yet; it can include it with `project(":core").projectDir = file("../driverApp/core")`.

Screens (phase 1): phone → code → **home** (today's net, trips and online time; the big online switch that breathes while online; "last ride, then offline"; and when the driver can't work, the exact reason with "Complete sign-up") → **sign-up** (details, vehicle, the documents required from this driver with upload from the gallery, Wasl register / check, send for review) → **ride** (one big action at a time: arrived → code → start → stops → end; navigation opens in Google Maps; free-waiting and no-show countdowns; chat with ready-made messages; releasing the ride with a reason; cash collection with the change to the rider's wallet; rating the rider) → **earnings** (today / week / month, last 7 days as bars, pending, wallet, points, rating). Backend host: `driverApp/local.properties` (`dorr.apiHost`, `dorr.apiScheme`), same as androidApp.

**The shift (`duty/DriverDutyService`, a location foreground service, alive while online — even with the app closed):**
- The position every 30 s (owner's decision), every 5 s on a ride, batched to `locations/batch` (every 30 s, 10 s on a ride); points wait and are retried while there's no network.
- Requests: `GET offers` every 4 s; a new one rings as a full-screen, ringtone notification (channel `ride_requests`) with **Accept / Decline** on it (`OfferActionReceiver` calls the API without opening the app; accepting then opens the ride). Inside the app the same request shows as a card with a countdown ring, ringtone and vibration (`OfferOverlay`).
- **Bidding requests (`BidSection`):** the rider's price and "Accept for X", or +5 / +10 / +15 and a stepper up to the cap with "you earn" updating live (`BidCardDto.netAt`, the server's own sum); then "your offer is with the rider", or "declined / no answer — offer again?". When the rider takes the offer the service opens the ride.
- No OneSignal SDK in the driver app yet: the service's polling is what delivers requests; a push (server already sends `ride.offer`) can be added on top.

Build check (no APK): from `driverApp/`, `gradlew :app:compileDebugKotlin --console=plain -Pkotlin.compiler.execution.strategy=in-process`, and look for `BUILD SUCCESSFUL`. Note: in Kotlin a `/*` inside a comment opens a nested comment — write paths like `/api/driver/v1/…`.

## Tests
`DriverOnboardingTest` (2): signing up step by step to review (a citizen never sees a residents-only document; expired uploads refused), and registering the phone for pushes.

`DriverAdminTest` (4): a resident registered by the admin and the residents-only document deciding if they work, a rejected document's reason and replacing an older approval, blocking signs out and the list filters, permissions.

`DriverAuthTest` (6).
