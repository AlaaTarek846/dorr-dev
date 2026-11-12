# providerApp — «شركاء دُرر» / Dorr Partners (Android)

The DORR **providers app**: one app for every service establishment, an individual or a company with branches and a team
(docs/moving_business.md §5, §12, §14, §17). First service: furniture moving. Tasks: [docs/moving_tasks.md](../docs/moving_tasks.md)
phase C: C-01 to C-06 are written; the rest of C-07 comes next.

- Package / applicationId `com.dorr.provider`, its own Gradle project (`rootProject.name = "DorrProvider"`).
- Shares `:core` from `../driverApp/core` (network base, API envelope, token store), exactly like `freelanceApp/`.
- Account: the server's `provider_api` guard, everything under `/api/provider/v1/*` (`Modules/Provider/routes/app.php`,
  `dashboard.php` for `me`, `Modules/Wallet/routes/provider.php` for the wallet, `routes/notifications.php`).
- **The look is copied, never designed:** `ui/theme`, `ui/kit`, `ui/components`, `ui/locale`, `ui/wallet` and the wallet,
  sign-in, OTP, splash, no-internet and language screens are copies of `freelanceApp/` (itself a 1:1 port of the DORR client
  app). Change them there first and copy, so every DORR app keeps one look.
- iOS twin: `providerAppIOS/` (Android is the reference; AGENTS.md: every change here must reach it in the same task).

## Run

`local.properties` (not in git): `sdk.dir=...`, and like the other apps `dorr.apiHost=...`, `dorr.apiScheme=https`,
`dorr.mapsKey=...` (the Google Maps key, same as driverApp's; used when the admin's map engine is Google).

```bash
cd providerApp
JAVA_HOME=~/.jdks/jbr-21.0.11 ./gradlew :app:compileDebugKotlin -Pkotlin.daemon.jvmargs=-Xmx3g
```

For another server without touching `local.properties`: `-Pdorr.apiHost=dorr-app.com -Pdorr.apiScheme=https`.
Trust only `BUILD SUCCESSFUL` (don't add `-q`: it hides resource errors). No build without the owner's go-ahead.

## The X-Establishment rule

One person (one phone number) can hold seats in several establishments (§14). The app keeps the chosen establishment's
**uuid** in `Session.establishment` (saved on the phone) and `ApiClient` sends it as `X-Establishment` on **every** request once
one is chosen (`EstablishmentAccess::HEADER`). With it the server answers for that establishment: its papers, my permissions in
it, and the wallet screens show **the establishment's wallet** (`WalletOwner`: `wallet.view` to see, `wallet.withdraw` to move
money). Switching establishment (Account tab) changes the uuid and every tab reloads for it.

Parts of the app show by my seat's `permissions` (`MembershipDto.can(...)`; the owner holds all): the Wallet tab and the header's
wallet button need `wallet.view`; the papers are the owner's. The server checks every permission again.

## No polling

Nothing asks the server on a timer. Live: Pusher with the settings from `GET realtime-config` on the private channel
`private-Modules.Provider.Models.Provider.{id}` (`net/Realtime.kt`); every event → one reload of what is on screen (home numbers,
lists, the open page). While a request or job page is open it also listens to `private-moving.{uuid}` (`Realtime.watchMove`):
`moving.changed` → one reload, `moving.driver_location` → the truck's last position. Push: OneSignal, app id from the same
endpoint, device registered on `notifications/devices`; a tapped notice opens its screen (`net/NoticeRoute.kt`). One catch-up
read on open and on reconnect (`collectReconnectTick`).

The one thing sent on a clock is the truck's position, and it only **sends**: see below.

## The location service (T10)

`duty/MoveTrackingService.kt`, the generic half of driverApp's `DriverDutyService`: a foreground service of type location with its own
notification channel, the fused provider every 5 s, and batches every 8 s to `POST moving/jobs/{uuid}/locations` (at most 100 points;
a batch that failed stays and goes with the next one). It runs **only** while the driver's job is `driver_on_way`, `arrived`, `loading`,
`in_transit` or `unloading`:

- on: the job page (`DriverDayPanel`) after set_out, or one catch-up on app open / reconnect (`MoveTracking.sync`);
- off: delivery, a `moving.changed` on the job's channel whose status left those states, a 403 / 404 on sending (the job isn't this
  driver's any more), or sign-out.

No background-location permission: while-in-use plus the foreground service. The phone only lets it start while the app is in front,
so a service the system killed comes back when the driver opens the app. The call names the job's own establishment, so switching
establishment mid-job doesn't stop the positions.

Money is always `*_minor` from the server, shown with the wallet's own formatter (`formatMinor`, currency code next to it).

## The map engine

The map comes from the admin's setting, exactly like the rider and captain apps: admin → Rides → Settings → map provider,
**Google or Mapbox** (`GET provider/v1/map-config`, the same `MapConfigController` payload as the captain's `driver/v1/map-config`:
`provider`, `ios_provider`, `mapbox_token`, `mapbox_style`). `map/DorrMap.kt` is driverApp's `map/DorrMap.kt` ported with the same
names: `MapEngine` (the saved choice read in `ProviderApp.onCreate` via `MapEngine.init`, the server's latest read **once per
sign-in** — and once per app start while signed in — and saved for the next start; no polling), `DorrCamera` /
`rememberDorrCamera`, `DorrMap(...)` drawing with `GoogleEngineMap` or `MapboxEngineMap` (the other engine is never started).
Additions over the captain's copy: `MapZone.stroke` / `strokePx` (an edge around a zone) and `fixedNorth` (no rotate / tilt);
the Mapbox engine also counts a pinch as the person's gesture. Google needs `dorr.mapsKey`; Mapbox needs only the admin's token
(SDK `com.mapbox.maps:android:11.8.0` from the public Mapbox Maven repo in `settings.gradle.kts`, no download token). Night: Mapbox's
dark style / `res/raw/map_night.json` for Google (copied from driverApp).

## Screens

| Screen | File | API |
|---|---|---|
| Splash | `ui/screens/SplashScreen.kt` (copied) | `general/v1/mobile-appearance-defaults` |
| Sign-in (phone + country) | `LoginScreen.kt` (copied) | `auth/otp` |
| OTP | `OtpScreen.kt` (copied) | `auth/verify`, `auth/resend` |
| Workspace gate | `WorkspaceScreens.kt` → `WorkspaceGateScreen` | `workspace` → home / invitations / onboarding |
| Onboarding (C-02): type + name, services as tiles (image + name, admin order), main branch on a real map + range (100 km to start) | `OnboardingScreen.kt`, `ServiceTile.kt`, `BranchMap.kt` | `workspace/options`, `POST establishments` |
| Onboarding step 4 when moving is chosen: the first vehicle (`Routes.FIRST_VEHICLE`), then home + papers | `VehicleScreens.kt` → `FirstVehicleScreen` | `vehicles` |
| Invitations (exclusivity shown before accepting) | `WorkspaceScreens.kt` → `InvitationsScreen` | `invitations/{uuid}/accept|decline` |
| Home: status, missing papers, send for review, «الطلبات» placeholder | `HomeScreen.kt` | `establishment`, `documents`, `establishment/submit` |
| Papers: status card, "3 of 5 ready" ring + state badges (missing / under review / approved / expires soon / rejected with reason / expired), a card per paper; the upload page shows each side's picture (tap to change, X to remove; camera or gallery), number, expiry (only after today: the picker starts tomorrow), fixed button with the upload's progress. The kit (`PaperScaffold`, `PaperCard`, `PaperProgressCard`, `DocumentForm`, `uploadPaper`…) is shared with the vehicle pages; strings in `papers_strings.xml` | `DocumentsScreen.kt` | `documents` (GET / multipart POST) |
| Wallet (same pages) | `WalletGate.kt`, `WalletScreens.kt`, `WalletSecurity.kt`, `WalletKit.kt` (copied); the PIN pages (setup with the 5 recovery methods, forgot PIN, change recovery, forced PIN change, new device, frozen) are the DORR app's `WaPinFlows.kt` ported to `ui/wallet/WaPinFlows.kt` — after creating the PIN the wallet opens directly (the server trusts the creating phone's `X-Device-Id`) | `wallet/*` |
| Account: profile, establishment switcher, invitations, leave, language, policies, about, sign out | `AccountScreens.kt` | `workspace`, `memberships/{uuid}/leave`, `auth/logout` |
| Notifications | `NotificationsScreen.kt` (copied) | `notifications/*` |
| **C-03** Branches (`branches.manage`): list, add / edit (place on the map, address from the map, range, on/off), delete | `BranchesScreen.kt` | `branches` |
| **C-03** Team (`members.manage`): list, invite by phone with templates + permission ticks (only keys I hold), branches, exclusivity, offer limit; edit, remove, change log | `TeamScreens.kt` | `members`, `members/events`, `workspace/options` |
| **C-03** Vehicles (`fleet.vehicles`): list with plate + status, add / edit in numbered cards (type images; the plate drawn live like the captain app's, letters **and** numbers required; make, model, colour, year; branch), page with a grid of photo slots first (plate photo leading, uploads in place with progress) then papers, deactivate. `FirstVehicleScreen(onDone)`: the onboarding's vehicle step (data → photos and papers; `onDone` only when `missing` is empty) | `VehicleScreens.kt` | `vehicles`, `documents` (subject=vehicle) |
| **C-03** Driver licence (a member with `driving.work`, or myself from Account) | `DocumentsScreen.kt` (`driverUuid`) | `documents?driver_uuid=`, subject=driver |
| **C-04** Home numbers (a tile per non-null number) | `HomeScreen.kt` → `MovingDashboard` | `moving/dashboard` |
| **C-04** Requests feed (neighbourhoods only) + request page + send offer | `RequestScreens.kt` | `moving/requests`, `moving/requests/{uuid}/offers` |
| **C-04** Offers (all / awaiting approval): edit, withdraw, approve | `OffersScreen.kt` | `moving/offers` |
| **C-04** Jobs + job page: changes list (with the answer to the customer's new time), assign (`moving/drivers`), price difference, new time, cancel / exception, invoice | `JobScreens.kt` | `moving/jobs`, `moving/jobs/{uuid}/changes`, `moving/drivers`, `assign`, `reschedule`, `changes/{uuid}/respond`, `cancel`, `invoice` |
| **C-05** «مهامي» + accept / decline | `JobScreens.kt` → `MyJobsScreen` | `moving/my-jobs`, `jobs/{uuid}/accept|decline` |
| **C-05** The driver's day on the job page: status, one main action (set_out → arrived → loading → departed → unloading → deliver), navigation, no-GPS note | `DriverDay.kt` → `DriverDayPanel` | `jobs/{uuid}/step` |
| **C-05** Inventory: numbered pieces with photos, after-photo / reservation at delivery | `DriverDay.kt` → `InventoryPage` | `jobs/{uuid}/inventory`, `inventory/{item}/delivered` |
| **C-05** Delivery: 4-digit code or the customer's QR (zxing, as in driverApp), or without the code with a required note | `DriverDay.kt` → `DeliverPage` | `jobs/{uuid}/deliver` |
| **C-04** Tickets + reply (does not close) | `TicketScreens.kt` | `moving/tickets` |

Shared look helpers (labels of every server value, the status pill, label/value line, row card, count tile): `MovingUi.kt`;
the time picker field next to the copied `DateField`: `ui/components/TimeField.kt`. Management entries live on the Account tab;
the moving work on the Home tab.

Bottom bar (copied `BottomBar`): Home · Wallet · Account.

## NEEDS-DECISION

- **App name**: «شركاء دُرر» / "Dorr Partners" is a placeholder until the owner names it.
- **Map** (onboarding step 3 and the branches page, `BranchMap.kt`): `DorrMap` (see «The map engine»: Google or Mapbox as the
  admin chose), a light circle for the branch's range around the pin. Google's key `dorr.mapsKey` in `local.properties` (never in
  git; without it a Google map stays blank). The address comes from the phone's `Geocoder` (either engine); no location → the rider
  app's location window (`LocationGate`).
- **Appearance / dark mode / font per person**: the server's `provider/v1/appearance` is read-only (the admin's payload), so
  the freelancer app's appearance pages were not copied.
- **Legal pages**: read from the client app's public `mobile/v1/legal-pages` until providers get their own.
- **Profile editing** (name, photo): `provider/v1/profile` exists on the web portal side only (email accounts); not wired yet.
- **A driver alone doesn't see prices** (§5): the job's price and invoice show only with `jobs.track`, but the server still returns
  them, and a change's amount is shown to the driver. Decide if the server should hide them.
- **Assigning** doesn't pass `branch_id` to `moving/drivers`: the move resource has no branch id, so every driver of the establishment
  is listed and the server refuses one outside the job's branch.
- **Map** for the truck's position: the last position is shown as text until a map is added.
