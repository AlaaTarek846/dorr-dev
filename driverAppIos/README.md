# driverAppIos — دُرر كابتن (iOS)

The captain app for iPhone: the SwiftUI twin of [`driverApp/`](../driverApp) (Android is the reference), on the same API
(`/api/driver/v1/*`, guard `driver_api`). Bundle id `com.dorr.driver`, iOS 16+, XcodeGen target `DorrCaptain` plus the
notification extension `DorrCaptainNotifications`.

## Run

```bash
cd driverAppIos
cp Config/Local.xcconfig.example Config/Local.xcconfig   # your backend + Google Maps key: DORR_API_HOST / DORR_API_SCHEME / DORR_GOOGLE_MAPS_KEY
xcodegen
xcodebuild -project DorrCaptain.xcodeproj -scheme DorrCaptain -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath build CODE_SIGNING_ALLOWED=NO build
```

`scripts/typecheck.sh` compiles the app against the simulator SDK without an Xcode build (fast, no DerivedData); files that
import a binary SDK (GoogleMaps, MapboxMaps, LiveKit, PusherSwift, OneSignal) are left out and stood in for.

## Layout (Android twin in brackets)

| Swift | Kotlin |
|---|---|
| `DorrCaptain/App/` — `DorrCaptainApp` (launch, foreground / background), `DriverNavHost` (routes, tabs, bottom bar, request card, push taps), `Routes.swift` | `DriverApp.kt`, `MainActivity.kt`, `ui/DriverNavHost.kt` |
| `DorrCaptain/Net/` — `Api.swift` (envelope, errors, Gson-like decoding), `DriverBackend.swift` (session), `DriverModels.swift`, `DriverApi.swift`, Bus / Marine / Hire / Lessons / Wallet APIs | `core/network`, `core/session`, `net/*`, `wallet/net/*` |
| `DorrCaptain/Duty/` — `Duty`, `DriverDutyService` (the shift: location batches, GPS switch, the request notification and its Accept / Decline) | `duty/*` |
| `DorrCaptain/Live/` — `DriverLive` (+ `DriverLivePusher`), `DriverPush` (+ `DriverPushOneSignal`) | `live/DriverLive.kt`, `live/DriverPush.kt` |
| `DorrCaptainNotifications/` — the notification service extension | `live/DriverPushExtension.kt` |
| `DorrCaptain/Map/DorrMap.swift` (Google or Mapbox, the admin's choice) | `map/DorrMap.kt` (same as iosApp's port) |
| `DorrCaptain/UI/Theme`, `UI/Kit`, `UI/Wallet`, `UI/Screens` | `ui/theme`, `ui/kit` + `ui/Common.kt`, `wallet/*`, `ui/*.kt` (same file names) |

## Rules

- **driverApp → driverAppIos sync:** a change in `driverApp/` is made here in the same task, in the twin file, keeping names
  (screens, API functions, DTO fields, store keys). See `AGENTS.md`.
- **Strings:** edit only `driverApp/app/src/main/res/values*/*.xml`; `scripts/sync_strings.py` regenerates
  `Localizable.strings` on every build. Never edit the generated files.
- **No polling:** live events (Pusher: the captain's private channel + the ride's) and push (OneSignal) — settings from
  `GET driver/v1/realtime-config`; one reload on reconnect / coming back to the screen. Countdowns run on the phone's clock.
  The location batches (30 s idle, 10 s on a ride) are uploads, as on Android.

## Not identical to Android (platform)

- **The shift in the background:** iOS's `location` background mode (with the blue location pill) instead of a foreground
  service with an ongoing notification; "Always" location permission is asked when going online.
- **A request while the app is closed:** a time-sensitive notification with Accept / Decline (set up by the notification
  extension for `type = ride_offer` pushes, or rung by the app itself) — iOS has no full-screen "call-like" notification for
  ordinary apps, and gives apps no access to the phone's ringtone (the default sound is used).
- No system back key: each page's own back button does what Android's back does.
- Face ID instead of the fingerprint / face unlock.
