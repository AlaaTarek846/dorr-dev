# providerAppIOS — شركاء دُرر / Dorr Partners (iOS)

The DORR providers app for iPhone: the SwiftUI twin of [`providerApp/`](../providerApp) (Android is the reference), on the
same API (`/api/provider/v1/*`, guard `provider_api`). Bundle id `com.dorr.provider`, iOS 16+, XcodeGen target `DorrPartners`.
One app for every service establishment (an individual, or a company with branches and a team); first service: furniture
moving (docs/moving_business.md, tasks docs/moving_tasks.md phase C). Every screen of `providerApp/` is ported.

The skeleton (XcodeGen spec, config, scripts, kit, components, theme, wallet, sign-in, OTP, splash, no-internet, language,
legal and notifications pages) is copied from [`freelanceAppIOS/`](../freelanceAppIOS), exactly as `providerApp/` copied
`freelanceApp/`. **The look is copied, never designed:** change the shared pieces in the DORR apps first and copy them here.

## Run

```bash
cd providerAppIOS
cp Config/Local.xcconfig.example Config/Local.xcconfig   # your backend: DORR_API_HOST / DORR_API_SCHEME
xcodegen
xcodebuild -project DorrPartners.xcodeproj -scheme DorrPartners -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath build CODE_SIGNING_ALLOWED=NO build
```

`scripts/typecheck.sh <paths>` compiles the shared core (Net, Kit, Components, Theme, Wallet, Routes, MainActions) plus the
given files against the simulator SDK without an Xcode build (fast, no DerivedData). The screens lean on each other, so check them
together: `scripts/typecheck.sh DorrPartners/Duty DorrPartners/UI/Screens/Home DorrPartners/UI/Screens/Workspace
DorrPartners/UI/Screens/Manage DorrPartners/UI/Screens/Moving`.

## Layout (Android twin in brackets)

| Swift | Kotlin |
|---|---|
| `DorrPartners/App/` — entry, `RootView` (stages, routes, shell, push taps), `Routes.swift` (`Route`, `RootStage`, `Nav`, `ShellState`, tab keys), `MainActions.swift` | `MainActivity`, `ProviderApp`, `navigation/ProviderNavGraph.kt`, `MainActions` in `MainScreen.kt` |
| `DorrPartners/Net/` — `Api.swift` (`ApiResult`, envelope, errors, the `X-Establishment` header), `Session.swift` (session, establishment, seat, device id, locale, network monitor, paged rows, keychain), `Workspace.swift`, `Realtime.swift`, one `*Api.swift` per Kotlin API | `net/*` + `driverApp/core` |
| `DorrPartners/UI/Kit`, `UI/Components`, `UI/Theme`, `UI/Wallet`, `UI/L10n.swift`, `UI/AppLanguage.swift`, `UI/DownloadedTranslations.swift` | `ui/kit`, `ui/components`, `ui/theme`, `ui/wallet`, `ui/locale` |
| `DorrPartners/Duty/MoveTrackingService.swift` (`MoveTracking`, `MoveTrackingService`) | `duty/MoveTrackingService.kt` |
| `DorrPartners/UI/Screens/{Auth,Home,Workspace,Manage,Moving,Account,Wallet}` + `MainScreen.swift` | `ui/screens/*.kt` (same file names) |

| Screen | Swift | Kotlin | API |
|---|---|---|---|
| Splash | `Auth/SplashScreen.swift` (copied) | `SplashScreen.kt` | `general/v1/mobile-appearance-defaults` |
| Sign-in | `Auth/LoginScreen.swift` (copied) | `LoginScreen.kt` | `auth/otp` |
| OTP | `Auth/OtpScreen.swift` (copied) | `OtpScreen.kt` | `auth/verify`, `auth/resend` |
| No internet | `Auth/NoInternetScreen.swift` (copied) | `NoInternetScreen.kt` | — |
| Workspace gate, invitations (exclusivity box) | `Workspace/WorkspaceScreens.swift` | `WorkspaceScreens.kt` | `workspace`, `invitations/{uuid}/accept|decline` |
| Onboarding (3 steps, location via CoreLocation) | `Workspace/OnboardingScreen.swift` | `OnboardingScreen.kt` | `workspace/options`, `POST establishments` |
| Tabs Home · Wallet · Account | `MainScreen.swift` | `MainScreen.kt` | `me`, `workspace`, `establishment` |
| Home, with a tile per non-null number | `Home/HomeScreen.swift` | `HomeScreen.kt` | `establishment`, `documents`, `establishment/submit`, `moving/dashboard` |
| Papers | `Home/DocumentsScreen.swift`, `Home/ImageUtil.swift` | `DocumentsScreen.kt`, `ImageUtil.kt` | `documents` (GET / multipart POST) |
| Wallet | `Wallet/WalletGate.swift`, `WalletScreens.swift`, `WalletSecurity.swift`, `WalletKit.swift`, `WalletFormat.swift` (copied, no freelance earnings card) | `WalletGate.kt`, `WalletScreens.kt`, `WalletSecurity.kt`, `WalletKit.kt`, `ui/wallet/WalletFormat.kt` | `wallet/*` |
| Account (switcher, leave, settings, sign out) | `Account/AccountScreens.swift` | `AccountScreens.kt` | `workspace`, `memberships/{uuid}/leave`, `auth/logout` |
| Branches (`branches.manage`) | `Manage/BranchesScreen.swift` | `BranchesScreen.kt` | `branches` |
| Team (`members.manage`): invite, templates, permissions, branches, exclusivity, limit, log | `Manage/TeamScreens.swift` | `TeamScreens.kt` | `members`, `members/events`, `workspace/options` |
| Vehicles (`fleet.vehicles`): photos first, then papers | `Manage/VehicleScreens.swift` | `VehicleScreens.kt` | `vehicles`, `documents` (subject=vehicle) |
| Driver licence | `Home/DocumentsScreen.swift` (`driverUuid`) | `DocumentsScreen.kt` | `documents?driver_uuid=`, subject=driver |
| Requests feed, request page, send / edit an offer | `Moving/RequestScreens.swift` | `RequestScreens.kt` | `moving/requests`, `moving/requests/{uuid}/offers` |
| Offers: edit, withdraw, approve | `Moving/OffersScreen.swift` | `OffersScreen.kt` | `moving/offers` |
| Jobs, job page, assign, change, new time, cancel, invoice, «مهامي» | `Moving/JobScreens.swift` | `JobScreens.kt` | `moving/jobs`, `moving/drivers?branch_id=`, `moving/my-jobs`… |
| The driver's day, inventory, delivery (4-digit code or QR) | `Moving/DriverDay.swift` | `DriverDay.kt` | `jobs/{uuid}/step`, `inventory`, `deliver` |
| Tickets + reply | `Moving/TicketScreens.swift` | `TicketScreens.kt` | `moving/tickets` |
| Language, policies, notifications | `Account/LanguageDialog.swift`, `LegalScreens.swift`, `NotificationsScreen.swift` (copied) | same names | `general/v1/translations/*`, `mobile/v1/legal-pages`, `notifications/*` |

## Rules

- **Android → iOS sync:** a change in `providerApp/` is made here in the same task, in the twin file, keeping names (screens,
  API functions, DTO fields, store keys); don't skip anything silently.
- **Strings:** edit only `providerApp/app/src/main/res/values*/*.xml`; `scripts/sync_strings.py` regenerates
  `Localizable.strings` on every build (Xcode pre-build script). Never edit the generated files. App texts in Arabic are فصحى.
- **X-Establishment:** `Session.establishment` (the chosen establishment's uuid, kept in `UserDefaults`
  `dorr_provider_workspace.establishment_uuid`) is sent as `X-Establishment` on **every** request once chosen (`Api.perform`),
  exactly like providerApp's `ApiClient`. Switching it (Account tab) restarts every tab for that establishment. A call that names its
  own keeps it: the truck's position goes to the job's establishment even if the driver has switched meanwhile.
- **Nulls in a body** (`net/ApiBody.kt` → `Net/ApiBody.swift`): a `JsonBody` sends its nils as JSON `null`, because the server reads a
  missing key as "no change" and only an explicit null as "clear it" (`BranchBody`, `VehicleBody`, a member, an offer). Android's plain
  `@Body` classes leave nulls out; their twins (`StepBody`, `DeliverBody`, a new establishment…) pass through `withoutNulls`.
- **A driver alone doesn't see prices** (§5): the server sends `money: null` and a 403 on the invoice; the page hides both without
  `jobs.track`. Assigning passes the move's `branch_id` to `moving/drivers`.
- **Permissions:** parts show by my seat's permissions (`MembershipDto?.can(...)`; the owner holds all): the Wallet tab and the
  header's wallet button need `wallet.view`; the papers are the owner's. The server checks every permission again.
- **No polling:** live events (Pusher, the provider's private channel) and push (OneSignal) — settings from
  `GET provider/v1/realtime-config`; one reload on reconnect (`NetworkMonitor.reconnectTick`) and per live notice
  (`Realtime.changed`). While a request or job page is open it also listens to `private-moving.{uuid}` (`Realtime.watchMove`):
  `moving.changed` → one reload, `moving.driver_location` → the truck's last position. Nothing asks the server on a timer; the one
  thing on a clock is the truck's position, and it only **sends** (below).
- **The location tracking (T10):** `Duty/MoveTrackingService.swift`, the iOS twin of the foreground service: CoreLocation with
  background updates (`location` background mode, when-in-use permission, the system's blue location indicator instead of Android's
  notification), one point every 5 s, batches every 8 s to `POST moving/jobs/{uuid}/locations` (at most 100 points; a batch that failed
  stays and goes with the next one). Only while the driver's job is `driver_on_way`, `arrived`, `loading`, `in_transit` or `unloading`.
  On: the job page after set_out (`MoveTracking.follow`), or one catch-up on open / reconnect (`MoveTracking.sync`). Off: delivery, a
  `moving.changed` whose status left those states, a 403 / 404 on sending, or sign-out.
- **Store keys:** `dorr_provider_session.*`, `dorr_provider_workspace.*`, `dorr_provider_locale`, `dorr_provider_appearance.*`,
  `dorr_provider_prefs.*`, `dorr_provider_biometric_pin*`, `dorr_provider_tracking.*` — never the freelancer app's or the client app's.

## Not identical to Android (platform)

- No system back key: each page's own back button does what Android's `BackHandler` does. The onboarding / invitations opened
  straight from the gate have nothing under them: Android's back then leaves the app; on iOS the back button does nothing.
- Location: CoreLocation's when-in-use permission (asked only when "use my current location" is tapped), one fix at best
  accuracy, 15 s at most (`CurrentPosition` in `OnboardingScreen.swift`; Android: fused provider `getCurrentLocation`).
- Papers come from the camera (`UIImagePickerController`) or the Files picker (`.fileImporter`: JPG / PNG / PDF); the inventory's
  photos from the camera or `PhotosPicker`.
- The customer's QR is read with AVFoundation (`AVCaptureMetadataOutput`, `QrCameraView` in `DriverDay.swift`), not zxing.
- Tracking: no notification (and no notification permission asked for it): iOS shows its own location indicator. A driver who force-quits
  the app stops the positions until they open it again, as when Android's system kills the service. After a "no" iOS never asks again, so
  the page's warning opens the phone's settings. Navigation opens Google Maps, else Apple Maps, else the browser.
- Decoding is stricter than Gson: a field the server sends as a number but Android reads as text is `FlexString` (`pieces_count`,
  `floor`). A dictionary's keys go through the decoder's snake-case rule and lose their order, so `WorkspaceOptionsDto.orderedGroups` /
  `orderedTemplates` put the permission groups and templates back in the server's config order (`branch_manager` reads `branchManager`).
- The time field uses the wheel picker (24-hour); iOS has no Material time dialog.
- Face ID is always "strong" (Android's weak face mode doesn't exist on iOS).

## NEEDS-DECISION

The same as providerApp's README: the app name («شركاء دُرر» / "Dorr Partners" is a placeholder), the map for branches and the truck's
position (coordinates as text for now), appearance / dark mode / font per person, the providers' own legal pages, profile editing.
