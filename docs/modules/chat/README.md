# Chat Module (`Modules/Chat`)

WhatsApp-style messaging. It is a module of its own, like `Modules/Wallet`. The feature study and design are in [../../chat-plan.md](../../chat-plan.md), and the endpoints are in [API.md](API.md).

## Status (2026-09-29)
- **Backend: done** for conversations, message requests, messages (every type, including wallet cards), ticks, reply, forward, edit, delete, reactions, stars, pins, search, the gallery, disappearing messages, groups and roles, invite links, contacts (sync, number lookup, QR), privacy, blocks, presence, typing, folders, calls (LiveKit), push, and the admin limits.
- **Android: done** (`androidApp/.../ui/screens/chat`, plus `chat/` for realtime, calls and voice). Opened from the chat button in the Home header, it takes the whole screen like the wallet does.
- **Stories: done** on the backend (`StoryService`, 9 tests in `tests/Feature/ChatStoryTest.php`) and on Android (`StoriesBar`, `StoryViewer`, `StoryComposer`, `StoryPrivacyPage`).
- **Themes & reports: done** (2026-09-28). Backend (`ChatThemeService`, `ChatReportService`, tables `chat_themes`, `chat_report_types`, `chat_reports`, each with `*_translations`, `*_users` and `*_messages` tables where needed, and 7 tests in `tests/Feature/ChatThemeReportTest.php`). Admin screens under **Chat** in the sidebar: reports, report reasons, themes and settings. Android: a theme picker with a live preview and a report sheet, both opened from chat info. The theme colours the bubbles and the wallpaper.
- **Admin chat settings screen: done** (`views/chat/settings`).
- **Group video calls: done**. Everyone in the call shows in a grid, with their camera or their photo, a glowing ring on whoever is talking, and a mic-off badge.
- **Web chat: done**. Route `/user/messages` in the Vue user SPA (`views/messages/index.vue`, `components/messenger/*`, `composables/useChatRealtime.js`). It uses the same API under `/api/user/v1/chat`, with realtime over pusher-js from `realtime-config`.
- `ChatDatabaseSeeder` seeds 6 report reasons and 6 colour themes (none set as the default) into empty tables only.

## Android app
| Path | What |
|---|---|
| `network/ChatApi.kt` | Retrofit endpoints and DTOs for `API.md` |
| `chat/ChatRealtime.kt` | One Pusher connection on the account's private channel (`pusher-java-client`), configured from `GET chat/realtime-config`. Also runs the online heartbeat while the app is in the foreground |
| `chat/CallController.kt` | App-wide call state machine plus the LiveKit room (`livekit-android`) |
| `chat/VoiceRecorder.kt` / `VoicePlayer.kt` | Voice notes: recording with a live waveform, and playback at 1×, 1.5× and 2× |
| `ui/screens/chat/ChTheme.kt` | The chat's design system: colours, avatars, badges, ticks, typing dots, wallpaper, motion |
| `ChatListPage` · `ConversationPage` (+ `ConversationState`, `MessageBubble`, `Composer`, `MessageActions`) · `ChatInfoPage` · `NewChatPage` (new chat, new group, my QR) · `ChatExtraPages` (privacy, starred, calls) · `CallOverlay` | The screens |

Behaviour worth knowing: sending is optimistic. A message shows at once with ⏱, and a retry reuses the same `uuid`, so it can never be duplicated. "Send money" on a wallet-QR card hands off to the wallet through `WalletDeepLink`, and the wallet opens its normal confirmation after the PIN gate.

**Server requirements for Android:** `BROADCAST_CONNECTION=pusher` with the Pusher keys (the app reads them from `realtime-config`), and "client events" doesn't need enabling because typing goes through the API. Calls also need the `LIVEKIT_*` values.

## Web (Vue user SPA)
| Path | What |
|---|---|
| `resources/js/modules/user/themes/theme-1/views/messages/index.vue` | Two panes: the list (search, All / Unread / Groups / Archived filters, requests) and the open chat (header with presence and typing, search inside the chat, messages, composer). Under 992px it shows one pane at a time |
| `resources/js/components/messenger/MessengerBubble.vue` | One message. Covers every type, replies, reactions, ticks, and hover actions (react, reply, copy, edit, delete) |
| `resources/js/components/messenger/messenger.css` | The look. It takes the brand colour from `--primary-rgb`, and supports dark mode and RTL |
| `resources/js/composables/useChatRealtime.js` | pusher-js on `private-Modules.User.Models.User.{id}`, authorised through `/broadcasting/auth` with the user token |

The web page supports optimistic sending with the same `uuid` rule as the app (retrying never duplicates), albums (several images sent as one message), drag-and-drop and paste for files, an upload progress bar, jumping to a replied message (it loads `around` it when it's old), and a media viewer. It also applies the chat theme.

## Layout
| Path | What |
|---|---|
| `app/Services/ConversationService.php` | Chat list, direct chats, requests, read and delivered, my settings on a chat |
| `app/Services/MessageService.php` | Send, edit, delete, forward, react, star, pin, info, search, gallery |
| `app/Services/GroupService.php` | Groups, roles, invites |
| `app/Services/ChatPrivacy.php` | **Every** "may A reach B" rule (blocks, who can message, add, or call) |
| `app/Services/ContactService.php` | Sync, lookup, QR |
| `app/Services/CallService.php` + `Support/LiveKitToken.php` | Call state machine and LiveKit tokens |
| `app/Services/WalletShareService.php` | Transfer-receipt and wallet-QR cards, built from the sender's own wallet |
| `app/Services/ChatBroadcaster.php` / `ChatPushNotifier.php` | Pusher (via Laravel Broadcasting) and OneSignal |
| `app/Support/ParticipantDirectory.php` | Batch-loaded profiles, using the viewer's own contact names and respecting photo privacy |
| `app/Support/ParticipantType.php` | `user` / `provider` aliases. Enabled types live in `config('chat.enabled_participants')` |
| `app/Console` | `chat:expire-calls` (every minute) and `chat:purge` (hourly) |

## Rules worth knowing
- A person's own settings on a chat (pin, mute, archive, lock, clear, delete, unread, theme) live on **their** `chat_participants` row.
- A new group member never sees messages from before they joined (`cleared_before_message_id`), and someone who left sees nothing sent after they left.
- Deleting a message for everyone hides its content straight away. `chat:purge` wipes that content after `deleted_message_retention_days`, so a report can be reviewed in the meantime.
- Read receipts are mutual in direct chats. Groups always show them.
- Realtime is sent only after the database commits, and never through the Pusher SDK directly, so moving to a self-hosted server later is only a config change.

## Deployment
- `.env`: `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`, plus the Pusher and OneSignal keys that already exist.
- PHP `upload_max_filesize` / `post_max_size` must be at least `max_file_size_mb` (100MB by default).
- The scheduler must run (`schedule:run` every minute).
