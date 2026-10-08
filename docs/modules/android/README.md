# Android App (`androidApp/`)

**Last updated:** 2026-09-30

Native Kotlin app for end users. It talks to the Laravel backend through `/api/mobile/v1/*` (guard `user_api`, Sanctum Bearer token) and `general/v1/services` for the service catalog. There is no Provider or Admin app here.

## Stack

| Item | Value |
|------|-------|
| Language / UI | Kotlin, Jetpack Compose, Material3 |
| Package | `com.dorr.app` (placeholder identity), `minSdk` 26, `targetSdk` 34 |
| Network | Retrofit + OkHttp + Gson |
| Images | Coil (+ GIF / animated WebP) |
| Realtime | `pusher-java-client` (host from `GET chat/realtime-config`) |
| Calls | LiveKit Android SDK |
| Push | OneSignal |
| Wallet | zxing QR, `androidx.biometric` (local unlock only; the PIN still guards money movement) |
| Video | media3 transformer (720p H.264 before upload) |

## Structure (`app/src/main/java/com/dorr/app/`)

| Path | Role |
|------|------|
| `DorrApp.kt` | Attaches stores, push and call controller at start |
| `MainActivity.kt` | `FragmentActivity` (needed for BiometricPrompt); theme, font, locale, offline screen |
| `navigation/DorrNavGraph.kt` | Routes: splash → onboarding → login → otp → main; logout, account deletion, 401 handling |
| `network/` | One `*Api.kt` per area, `ApiClient` (interceptors), `AuthSession`, `DeviceId`, `NetworkMonitor`, stores and caches |
| `ui/screens/` | Home, Services, ServiceDetail, Profile, Notifications, Login, Otp, Onboarding, Splash |
| `ui/screens/chat/` | Chat list, conversation, info, stories, themes, calls overlay, money cards |
| `ui/screens/wallet/` | Home, history, transfer, top-up, QR, PIN flows, biometric |
| `ui/screens/profile/` | Personal data, addresses, appearance and font, notifications, FAQ, privacy policy, contact us, invite friends, rate app, support ticket |
| `ui/components/` | Shared pieces: `DorrTextField`, `HtmlText`, `ServicesSection`, `HeroBannerSlider`, … |
| `ui/theme/` | Colors, typography, `Appearance` tokens, app-wide admin-chosen font (`AppFont`) |
| `chat/` | Realtime, push, call controller, voice/video tools, live location service |
| `res/values`, `res/values-ar` | Strings — **always update both** |

Main screen: 4 tabs (Home, Services, History, Account). Wallet and chat open on top of it.

## Behaviour to remember

- **Auth:** phone + OTP only (fixed demo code, see CHECKPOINT). A soft-deleted account is restored by signing in with the same phone.
- **401:** `ApiClient` clears `AuthSession` and routes to Login, remembering the page to return to. Only when a Bearer token was sent.
- **First launch:** Splash → 3-step onboarding once (`onboarding_completed`), then Login or Home.
- **Appearance:** `GET/PUT mobile/v1/appearance` (dark mode, primary/secondary colors, font). Before login the built-in palette is used.
- **Rating:** `RateAppScreen` ↔ `RatingApi` (`mobile/v1/ratings/mine`, `POST mobile/v1/ratings`); 4–5 stars launch Google Play In-App Review (no-op until the app is published on Play).
- **Content:** FAQ from `mobile/v1/faqs`, policy text from `mobile/v1/legal-pages?type=privacy|term&service_id=`, rendered with `HtmlText`. Services from `general/v1/services`. Support: tickets and their live conversation via `mobile/v1/support-tickets*` (`SupportTicketScreen`, `SupportChatScreen`), updated by the `support.*` Pusher events on the account channel (`ChatRealtime`); a tapped support push (`data.type = support`) opens the ticket through `ChatDeepLink.Support`. The Support menu has a separate "Quick chat" entry (guided help: `SupportHelpFlowScreen`, `mobile/v1/support-help`), while "New ticket" in Support requests opens the form directly: topics as a list, each pick answered, and at the end only "solved" or "I need an agent" (the latter opens the ticket form with the topic as its title).
- **Headers sent on every request:** `Accept: application/json`, `X-Locale`, `X-Device-Id`.
- **Referral:** `GET mobile/v1/referrals/my-code` / `POST .../track`. Share uses the server code. A pending code (typed, shared, or Play Install Referrer later) is sent once after login; the backend is idempotent.
- **Offline:** unknown host / refused connection shows the app-wide no-internet screen.

## Backend host (dev)

`network/ApiClient.kt` hard-codes `BASE_HOST`:

| Target | Value |
|--------|-------|
| Phone on Wi-Fi (current) | `LAN_HOST` — the PC's current IP (`ipconfig`); must be a `ServerAlias` of the Laragon Apache vhost for `dorr.test`; reload Apache after changing |
| Emulator | `EMULATOR_HOST` = `10.0.2.2` |
| Remote | `NGROK_HOST` (scheme switches to https automatically) |

Media URLs built by Laravel with `dorr.test` are rewritten to the reachable host.

## Rules for changes

1. Backend contract first: check `docs/06-API-SPECIFICATION.md` and module API docs; never invent endpoints.
2. New API → add to the matching `network/*Api.kt` and DTO in `ApiModels.kt`.
3. New UI string → `values/strings.xml` **and** `values-ar/strings.xml`.
4. Colors/fonts from the appearance tokens, not hard-coded.
5. Chat details live in [../chat/README.md](../chat/README.md); wallet plan in [../../wallet-plan.md](../../wallet-plan.md).
6. Build check: `./gradlew assembleDebug` from `androidApp/`.
7. Update this file and `docs/10-CHANGELOG.md` when behavior changes.

## Known issues

- `androidApp/.gradle/` was untracked from git (2026-09-30); `.gitignore` already ignores it and `build/`.
- No Android unit/UI tests.
