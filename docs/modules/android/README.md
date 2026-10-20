# Android App (`androidApp/`)

**Last updated:** 2026-10-14

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

Main screen: 4 tabs (Home, Services, History, Account). Wallet and chat open on top of it. Home order: themed greeting header, ads, stories, calendar (today card), sports, events, moments, wallet, services.

## Passenger rides (`ui/screens/rides/`, `network/RidesApi.kt`)
Tapping the service with module `passenger_ride` opens `RidesHost` (MainScreen) instead of the generic service page:
- **Map** (Google Maps for Compose; key `dorr.mapsKey` in `local.properties` → manifest `com.google.android.geo.API_KEY` — without it the map is blank and the rest works). The pickup is under the centre pin (it lifts while the map moves); addresses and the place search use the phone's free `Geocoder`, never Google's paid APIs.
- **Where to?** → search page (pickup, destination, stops when `multi_stop` is on, "pick on the map") or **No set destination** (when `open_destination` is on) → **prices** (`POST rides/quotes`: one card per category, fixed price or range, route drawn) → payment (wallet / card / cash, as switched on here) → book (`Idempotency-Key`).
- **Card:** the ride comes back `awaiting_payment`; the app opens the shared payment screen with `CheckoutLauncher.open(CheckoutRequest("ride", {ride_uuid}))`. Wallet short of money → "pay by card instead".
- **Following the ride:** polled every 3–4 s (searching radar → captain card with rating, car, a drawn plate, the PIN → free-waiting countdown at pickup → on the way); chat sheet (ready-made messages + text, closed when the ride ends); cancel sheet showing the fee first, with reasons; at the end the receipt lines and the rating (stars + tags + comment).
- **Bidding (`RidesBidding.kt`):** a bid category shows "Name your price"; the sheet's price editor stops at the floor. After booking: my price with +5 / +10 raises, and every captain's offer as a card (cheapest first, cheapest / nearest badges, a draining timer bar, decline / take), polled every 2 s.
- An active ride is resumed when the screen opens (`GET rides/current`). Strings: `res/values*/rides_strings.xml`.

## Behaviour to remember

- **Auth:** phone + OTP only (fixed demo code, see CHECKPOINT). A soft-deleted account is restored by signing in with the same phone.
- **401:** `ApiClient` clears `AuthSession` and routes to Login, remembering the page to return to. Only when a Bearer token was sent.
- **First launch:** Splash → 3-step onboarding once (`onboarding_completed`), then Login or Home.
- **Appearance:** `GET/PUT mobile/v1/appearance` (dark mode, primary/secondary colors, font). Before login the built-in palette is used.
- **Rating:** `RateAppScreen` ↔ `RatingApi` (`mobile/v1/ratings/mine`, `POST mobile/v1/ratings` create or update the same row); 4–5 stars launch Google Play In-App Review once (no-op until the app is published on Play).
- **Content:** FAQ from `mobile/v1/faqs`, policy text from `mobile/v1/legal-pages?type=privacy|term&service_id=`, rendered with `HtmlText`. Services from `general/v1/services`. Support: tickets and their live conversation via `mobile/v1/support-tickets*` (`SupportTicketScreen`, `SupportChatScreen`), updated by the `support.*` Pusher events on the account channel (`ChatRealtime`); a tapped support push (`data.type = support`) opens the ticket through `ChatDeepLink.Support`. The Support menu has a separate "Quick chat" entry (guided help: `SupportHelpFlowScreen`, `mobile/v1/support-help`), while "New ticket" in Support requests opens the form directly: topics as a list, each pick answered, and at the end only "solved" or "I need an agent" (the latter opens the ticket form with the topic as its title).
- **Headers sent on every request:** `Accept: application/json`, `X-Locale`, `X-Device-Id`.
- **Referral:** `GET mobile/v1/referrals/my-code` / `POST .../track`. Share uses the server code. A pending code (typed, shared, or Play Install Referrer later) is sent once after login; the backend is idempotent.
- **Offline:** unknown host / refused connection shows the app-wide no-internet screen.
- **Chat features by country and city (`ChatFeatures`):** on chat open the app reads `GET chat/features` and hides what's off (voice rooms, the live toggle, stories; AI buttons follow `GET chat/ai`, call buttons `can_call`). With approximate location allowed it sends `PUT chat/location` at most every 6 hours (the server keeps only the nearest city). The permission is asked once, after our own explanation sheet.
- **Chat header and sheets (spec header rule):** the chat list has no back arrow (the tab bar and system back leave it; search and the special lists step back). Every inner screen starts with `ChBack` — back (mirrored in RTL) with the DORR logo beside it. Every bottom sheet in chat, Moments, Calendar, Discover and Sports is a `ChSheet`: Material's sheet with a × beside the drag handle.
- **Moments preferences (spec 168):** Settings is one Account-style card: Show occasions, Animations, and Country occasions. The last two open inner screens. Country occasions is a searchable dropdown (login country first) with "which occasions" under it; changing country calls `PUT moments/preferences` then `GET moments/catalog?country_id=`.
- **Moments sheets:** add-occasion and new group card use `ChSheet` with `skipPartiallyExpanded` so the action button is not cut off, and the same labeled pill fields (`DorrTextField` / `MoSheetSelect`) as the calendar form.
- **Dark hero panel:** Calendar Today / Week / Month and the Moments center share `DarkHeroPanel` (navy + brand-red in light, the same appearance primary as Home in dark). It reads `LocalThemeState` so flipping dark/light updates the panel. Translucent round buttons. Moments uses the same settings cog; the occasion card sits on the page below the panel.
- **App language in overlays:** a `Dialog` / `ModalBottomSheet` is a new window. Capture the app locale with `rememberAppLocaleFrame()` / `LocaleAwareDialog` (and `ChSheet`) so `stringResource` and dates stay in the in-app language, not the phone's.
- **Data and performance (chat menu → "Data and performance", spec 82, 87, 83):** data saver (`ChatDataSaver`: off / mobile data / always) holds others' photos until tapped (Coil cache-only first, then the server's `preview`), stops GIF clips playing by themselves, sends lighter photos and videos, and makes calls 360p. Lite mode (`ChatLite`: auto at ≤3 GB memory / on / off) drops bubble animations, blur, animated GIFs and stickers, and camera backgrounds. Usage stats (`ChatUsage`) count on the phone only: an OkHttp **network** interceptor measures chat API calls and media, split mobile / Wi-Fi, and a call's data is the app's own traffic (TrafficStats) while it lasts. All three load in `DorrApp.onCreate`.
- **Never crash on a message's data:** read `meta` with `obj()` / `arr()` / `bool()` / `int()` / `long()` / `dbl()` / `str()` (ChatHost.kt).
  - These return null for a missing field or JSON null.
  - `getAsJsonObject` / `.asBoolean` on JSON null crashed the bubble, and with it the chat every time it opened. This happened to live cards after the live ended, and to poll counts.
- **Voice rooms (2026-10-12):**
  - A loudspeaker button (`VoiceRoomController.toggleSpeaker`, through AudioSwitch like calls).
  - `VoiceRoomService`, a foreground service (microphone + media playback), keeps the room's sound and mic going outside the app.
  - Its notification is the room "minimised": tap to come back, mute or unmute, or leave.
- **Sharing a contact without the permission:** once READ_CONTACTS was refused, the attach sheet opens the phone's own number picker (`ACTION_PICK` on `Phone.CONTENT_URI`). Android grants that one row, so nothing is asked again.
- **Invites:** `inviteTokenOf()` reads `https://…/j/CODE`, `dorr://chat/join/CODE` or a bare code. These come from a tapped link in a message, the QR scanner, "Join with a code or link" on New chat, or the `/j/` page (`ChatDeepLink.Join`). The join sheet then opens.
- **Long-press menu:** the message takes at most a third of the screen and scrolls. The menu takes the rest and scrolls too.
- **Split a bill:** the sheet loads the group's members itself. Before this they were loaded only for "@" suggestions, so the sheet could open with nobody to pick.
- **Add members:** members that someone's privacy kept out are named in a toast, not silently dropped.
- **Story reply:** sending, or closing the keyboard, clears the field's focus so the story and its bar go on.
- **Crash reports:** Firebase Crashlytics (`CrashReports.kt`).
  - The Gradle plugins apply only when `app/google-services.json` exists. Without it, nothing is reported and nothing breaks.
  - The account id is attached when the chat connects.
- **Real-time start:** `ChatRealtime.start()` retries `chat/realtime-config` up to 6 times (2 s → 30 s), and again when the app comes to the front.
  - This matters because the same answer also starts push (`ChatPush.ensure`).
  - Before this, one failed first call meant no live chat and no notifications for the whole session.
- **Video size (backlog 38, 40):** `ChatPrefs.videoMode()` picks the size. `VideoTools.compress` acts on it.
  - **hd:** upload quality "high" → 1080p at ~6 Mbps.
  - **slow:** data saver, "saver", or a slow metered line on auto → 480p, never over 64 MB; the bitrate fits the length. Otherwise it throws `VideoTooBigException` and the message stays failed, with a toast.
  - **normal:** 720p.
  - **All sizes:** never upscaled.
- **WhatsApp backlog screens (`ChatBacklogUi.kt`, `StoryVoice.kt`, `network/BacklogApi.kt`):**
  - **Chats:** export a chat (info page); storage (Data and performance → manage storage); channel stats and allowed reactions.
  - **Business:** the business card and message stats.
  - **Broadcasts:** a list from a folder.
  - **Profile and QR:** a ready message on my QR / link.
  - **Statuses:** a voice status (the "Voice" tile, `ChRoute.StoryComposer("voice")`), "@ Mention" on every status editor, and "Mentioned you" with "Add to my status" in the viewer and in the chat (`story_mention`).
  - **Links:** `dorr://chat/{token}` links open the chat (manifest intent filter, `ChatDeepLink.ChatLink`). Exports are shared through the FileProvider `${applicationId}.files`.
- **A call outside its screen, Messenger-style (2026-10-13):**
  - **Minimise:** back or ⌄ sets `CallController.minimized`. The call becomes a draggable bubble (`CallMiniBubble` in `CallOverlay.kt`) showing their video, or their photo, the timer and hang up. A tap opens it again.
  - **Leaving the app mid-call:** home or another app triggers `onUserLeaveHint` → `CallPip.enter`, which opens a picture-in-picture window. `CallController.inPip` makes the activity draw only `CallPipView`, and the window goes away once the call ends. Both activities declare `supportsPictureInPicture`.
  - **`CallService`:** a foreground service (`microphone|camera|mediaPlayback`) that runs from "calling" to the end, so the mic and camera keep working in the background. Its notification shows who and the timer, with Mute and Hang up. A tap opens the app with `EXTRA_OPEN_CALL`, which un-minimises the call.
  - **Ringing over other apps:** with "display over other apps" allowed (`SYSTEM_ALERT_WINDOW`, `CallPermissions`), `CallNotifications.showIncoming` also opens `IncomingCallActivity` directly. Without it, an unlocked phone only gets a banner.
    - Android 14+ also needs full-screen notifications allowed (`canUseFullScreenIntent`).
    - Both are asked once in the chat (`CallPermissionPrompt`), and offered on the calls page while missing.
  - **Back on `IncomingCallActivity` after answering:** it opens the app with the call minimised. On the lock screen, it moves out of the way instead.
- **WhatsApp backlog, second batch (2026-10-12):**
  - **Status layers (79, 80) — `StoryLayers.kt`:**
    - `StoryLayerEditor` holds the stickers, GIFs, emoji, text and pen lines. They can be dragged, pinched and turned.
    - Tapping a layer picks it and shows a delete button. Tapping picked text rewrites it.
    - Layers are placed in the photo or video's own frame (`style[aspect]`). The viewer draws them with `StoryLayersView`.
  - **Call links (72) — `ChatCallLinks.kt`:**
    - The calls page has "Create call link", plus my links (copy, share, join, cancel).
    - `CallLinkSheet` opens from `dorr://chat/call/X`, https `/call/X`, a QR, a typed code, or the "someone joined" push.
    - `CallController.joinLink` joins the room with no call row and no hang-up when alone.
  - **Screen sharing (71):**
    - The screen-share button in the call asks Android first, then calls `CallController.startScreenShare`.
    - A red "sharing" bar with Stop shows while it runs.
    - A remote screen is shown whole (`CallVideo.screen`, `SCALE_ASPECT_FIT`). The call buttons row now scrolls.
  - **Reply buttons and lists (123–125) — `ChatInteractiveUi.kt`:**
    - The attach menu has "Buttons", which opens the composer for buttons or a list.
    - The bubble shows buttons, or a list button that opens a sheet. `ConversationState.choose` handles a tap.
    - The sender sees "N answered" and who picked what.
  - **Communities (93–98) — `ChatCommunityUi.kt`:**
    - Chat menu → Communities, routes `ChRoute.Communities` and `ChRoute.Community(id)`.
    - A community page has announcements, my groups, and other groups (join or ask).
    - Admin tools: add or make groups, members and admins, rename, delete (creator) or leave.
    - A group's info page links to its community.

- **Tablets and wide screens (spec 89):** from 700 dp wide (`TWO_PANE_MIN_WIDTH`, measured with `BoxWithConstraints`, so split screen and folding count) the chat shows its list and the open page side by side. `ChatHost.twoPane` makes opening a conversation replace the one beside the list. `detail` is what shows there (a placeholder when nothing is open), and the list marks `openConversationId`. `MainActivity` declares `configChanges` for orientation, size and keyboard, so turning or resizing re-lays the app out instead of restarting it: open chats, typed text and the call screen stay. `LocalizedApp` rebuilds its locale context from the new configuration. Nothing may count on the activity being recreated for those changes.
- **Call recording (spec 92):** `CallController.recording` / `recordingAvailable` / `joinConsent`, UI in `ChatCallRecordingUi.kt`: the REC badge (always shown, never hidden with the controls), "N of M agreed" while asking, the yes / no question with a countdown, "agree and join" when the server answers `409 chat_recording_consent_required`, and the recordings sheet (`ChatViewers.recordings`) from a call line marked `recorded` or the call log (`recordings_count`).

- **Pull to refresh:** wrap a screen's scrollable content in `PullToRefreshHost` (`ui/components/PullToRefresh.kt`, used by every tab in `MainScreen` and by the wallet) and key its load on `collectReconnectTick()` — `RefreshCoordinator` adds a pull tick to that value and hides the indicator when the in-flight API requests (counted by an OkHttp interceptor) have finished. Screens that load once with `LaunchedEffect(Unit)` do not reload on a pull until keyed on the tick.
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
6. Build check: `./gradlew assembleDebug` from `androidApp/` with JDK 17–21 (Gradle 8.9 doesn't run on JDK 25).
7. Update this file and `docs/10-CHANGELOG.md` when behavior changes.

## App size

Native libraries were most of a 200 MB APK. Now: `abiFilters` = `arm64-v8a,armeabi-v7a` (property `dorr.abis`; emulator builds add `x86_64`), one WebRTC (LiveKit's, package `livekit.org.webrtc`; don't add `io.github.webrtc-sdk`), and release builds shrink with R8 (`-dontobfuscate`, `com.dorr.app.**` kept because Gson/Retrofit read it by reflection). Still the biggest: ML Kit selfie segmentation `libxeno_native.so` (~20 MB per CPU type, camera backgrounds in calls) and WebRTC (~11 MB). Release APK ≈ 67 MB; a Play bundle sends each phone one CPU type.

## Known issues

- `androidApp/.gradle/` was untracked from git (2026-09-30); `.gitignore` already ignores it and `build/`.
- Few Android tests: JVM unit tests only (`app/src/test`, e.g. `ChatTwoPaneTest`, `FolderGifTest`), no UI tests.
