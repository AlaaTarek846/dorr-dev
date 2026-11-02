# freelanceAppIOS — دُرر للمحترفين (iOS)

The freelancer app for iPhone: the SwiftUI twin of [`freelanceApp/`](../freelanceApp) (Android is the reference), on the
same API (`/api/freelance/v1/*`, guard `freelance_api`). Bundle id `com.dorr.freelance`, iOS 16+, XcodeGen target `DorrPro`.

## Run

```bash
cd freelanceAppIOS
cp Config/Local.xcconfig.example Config/Local.xcconfig   # your backend: DORR_API_HOST / DORR_API_SCHEME
xcodegen
xcodebuild -project DorrPro.xcodeproj -scheme DorrPro -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath build CODE_SIGNING_ALLOWED=NO build
```

`scripts/typecheck.sh <paths>` compiles the shared core plus the given files against the simulator SDK without an Xcode
build (fast, no DerivedData).

## Layout (Android twin in brackets)

| Swift | Kotlin |
|---|---|
| `DorrPro/App/` — entry, `RootView` (routes, shell, gate, push taps), `Routes.swift` (`Route`, `Nav`, `ShellState`) | `MainActivity`, `FreelanceApp`, `navigation/FreelanceNavGraph.kt` |
| `DorrPro/Net/` — `Api.swift` (`ApiResult`, envelope, errors), `Session.swift`, `Realtime.swift`, one `*Api.swift` per Kotlin API | `net/*` + `driverApp/core` |
| `DorrPro/UI/Kit`, `UI/Components`, `UI/Theme`, `UI/Wallet` | `ui/kit`, `ui/components`, `ui/theme`, `ui/wallet` |
| `DorrPro/UI/Screens/{Auth,Work,Profile,Agreement,Services,Growth,Wallet,Account,Support}` | `ui/screens/*.kt` (same file names) |

## Rules

- **Android → iOS sync:** a change in `freelanceApp/` is made here in the same task, in the twin file, keeping names
  (screens, API functions, DTO fields).
- **Strings:** edit only `freelanceApp/app/src/main/res/values*/strings.xml`; `scripts/sync_strings.py` regenerates
  `Localizable.strings` on every build. Never edit the generated files.
- **No polling:** live events (Pusher, the freelancer's private channel) and push (OneSignal) — settings from
  `GET freelance/v1/realtime-config`; one reload on reconnect. Countdowns run on the phone's clock.

## Not identical to Android (platform)

- No system back key: each page's own back button does what Android's `BackHandler` does.
- Face ID is always "strong" (Android's weak face mode doesn't exist on iOS).
- The invite link still points to Google Play until the app has an App Store id.
- Rate app uses `SKStoreReviewController`; files open in Quick Look; pickers are `PhotosPicker` / `.fileImporter`.
