# Chat Module — API

Base: `/api/mobile/v1/chat` (the app) and `/api/user/v1/chat` (the website, Vue user SPA). Both mount the same `routes/customer.php`, so every endpoint below works under either prefix. Guard `user_api` · middleware `locale`, `ensure-phone-verified`, `country`, `remember-locale`. The app sends `X-Country` like the wallet does. The website doesn't, so the country comes from the user's profile.
Every response uses `App\Support\Api\ApiResponse`. Errors from chat rules have `error_code` = `chat_<code>` (for example `chat_blocked` or `chat_edit_window_passed`), and their messages live in `lang/{ar,en}/chat.php`.

Ids: `{conversation}`, `{message}` and `{call}` are **uuids**. `{contact}`, `{folder}` and `{participant}` are numeric ids. `participant` is a row in a group, taken from `members`.

---

## Realtime config

`GET realtime-config` returns how to connect to the realtime server: `enabled`, `key`, `cluster`, `host`/`port` (null for Pusher's cloud), `use_tls`, and `auth_path` (`/broadcasting/auth`). The values are public only and never include the secret. The app reads them from here, so moving to a self-hosted Soketi or Reverb server needs no app update.

## Conversations

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `conversations` | `filter` = all\|unread\|groups\|direct\|archived\|locked\|requests, `folder`, `search`, `per_page` | Pinned first, then by last message. Returns `requests_count` next to `data` |
| POST | `conversations/direct` | `participant_id`, `participant_type` (default `user`) | Opens or creates the chat. It is `pending` when the other person hasn't saved me as a contact |
| POST | `conversations/self` | | My **note to self** chat, created on first use and the same one afterwards. A `direct` chat (`direct_key` = `self\|user:{id}`) with only me in it: `is_self: true`, `title` = "Notes (you)", `avatar` = mine, `peer` = null. No push, no typing, and calls answer `422 chat_self_no_calls` |
| GET | `conversations/{c}` | | Also returns `presence`, `i_blocked`, `blocked_me`, and `block_screenshots` for a direct chat |
| PATCH | `conversations/{c}/settings` | `pinned`, `archived`, `locked`, `marked_unread`, `mute` (8h\|1w\|always\|off), `theme_id`, `custom_theme` | Only for me |
| POST | `conversations/{c}/wallpaper` | `image` (jpeg/png/webp, ≤ 8 MB, multipart) | My own wallpaper for this chat, seen only by me. It replaces and deletes the previous one, and `dim` defaults to 25. Stored at `public/chat/wallpapers/{participant}/` |
| POST | `conversations/{c}/clear` | | Clears the history for me only |
| DELETE | `conversations/{c}` | | Deletes the chat for me. A group has to be left first |
| POST | `conversations/{c}/read` | `up_to` (message uuid, optional) | Sends blue ticks to the others |
| POST | `conversations/delivered` | | Everything so far reached this phone (grey double ticks) |
| POST | `conversations/{c}/typing` | `state` = typing\|recording\|stopped | Not stored. Throttled to 40 per minute |
| PUT | `conversations/{c}/disappearing` | `seconds` = null\|86400\|604800\|7776000 | For the whole chat. Adds a system line |
| POST | `conversations/{c}/accept` / `reject` | `block` (bool, reject only) | Message requests |

## Messages

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `conversations/{c}/messages` | `before` \| `after` \| `around` (uuid), `limit` ≤100 | Newest last. Also returns `has_more_before` and `has_more_after` |
| POST | `conversations/{c}/messages` | `type`, `body`, `uuid` (idempotent), `reply_to`, `mentions[]` (participant ids), `silent` (bool), `files[]`, plus the fields each type needs (see below) | Multipart when there are files. `silent=1` stores `is_silent` and sends the push with `data.silent = "1"`, which the app shows without sound or vibration |
| PATCH | `messages/{m}` | `body` | Own messages only, within `edit_window_minutes` |
| DELETE | `messages/{m}` | | Deletes for everyone: my own message within the window, or any message if I'm a group admin |
| POST | `conversations/{c}/messages/delete-for-me` | `messages[]` | |
| POST | `messages/forward` | `messages[]`, `conversations[]` (≤ `max_forward_targets`) | Media is copied. `wallet_transfer`, `call` and `system` messages can't be forwarded |
| PUT | `messages/{m}/reaction` | `emoji` (null removes it) | One reaction per person |
| GET | `messages/{m}/reactions` | | Who reacted with what |
| PUT | `messages/{m}/star` | `starred` | |
| GET | `messages/starred`, `conversations/{c}/starred` | | |
| POST / DELETE | `messages/{m}/pin` | `duration_seconds` = 86400\|604800\|2592000 | Pinned for everyone. The oldest pin gives way at `max_pinned_messages` |
| GET | `conversations/{c}/pinned` | | |
| GET | `messages/{m}/info` | | My own message: `read_by`, `delivered_to`, `pending` |
| GET | `messages/search`, `conversations/{c}/messages/search` | `q` | |
| GET | `conversations/{c}/gallery` | `kind` = media\|documents\|audio\|links\|locations, `before` | |

**Fields each message type needs:**

| `type` | Required fields |
|---|---|
| `text` | `body` |
| `image` / `video` / `audio` / `document` | `files[]`. `body` becomes the caption. Video can add `duration_ms` |
| `voice` | `files[]`, `duration_ms`, `waveform[]` (0–100, up to 200 bars) |
| `location` | `latitude`, `longitude`, `location_name`, `address` |
| `contact` | `contact_name`, `contact_phones[]` |
| `wallet_transfer` | `wallet_transaction_id`. This must be **my own** `transfer_out`, and the server builds the card |
| `wallet_qr` | `country_code` (optional, defaults to the request's country). The server builds the card from **my own** wallet |

**Message shape:** `id`, `conversation_id`, `type`, `body`, `meta`, `attachments[]`, `sender` (profile), `is_mine`, `status` (sent\|delivered\|read, my messages only), `reply_to`, `is_forwarded`, `forwarded_many_times`, `mentions[]`, `is_edited`, `is_deleted`, `expires_at`, `reactions {summary, mine, total}`, `is_starred`, `system {event, actor, targets, text}`, `created_at`.

**Text formatting.** `body` is stored as typed. The apps draw WhatsApp-style marks: `*bold*`, `_italic_`, `~strike~`, `` `code` `` and ```` ```block``` ````. A mark only counts at a word edge and around non-blank text, so `5*3*2` and `snake_case` stay as typed. Nothing is formatted inside code or links. The push text drops the marks (`ChatPushNotifier::plain()`). The same rules live in `ChatFormatting.kt` (Android) and `MessengerBubble.vue` (web).

**Media page.** `GET conversations/{c}/gallery?kind=media|documents|audio|links|locations&before={uuid}` returns 60 per page with `has_more`. View-once and deleted messages are left out. The app's "Media, links and docs" page pages through it, and "show in chat" scrolls the conversation to the message (`around`).

**My own chat look (`custom_theme`).** Each participant can set `sender_color`, `receiver_color`, `background_color` (hex) and `dim` (0–80, how much the photo is darkened). The photo itself is uploaded through `POST …/wallpaper`; a URL is never accepted (`wallpaper` can only be `null`, which removes it). Setting a key to `null` falls back to the theme's value, and `custom_theme: null` drops the whole look and deletes the photo. `theme.custom` returns my look (the photo as a URL). `theme.applied` returns what to draw: the picked or default theme with my look on top (`is_custom: true`, `id: 0`, `dim`). See `ChatThemeService::appliedFor()`. **Note for clients:** Retrofit's default Gson drops `null` map values, so the Android app sends these settings with `updateSettingsJson` + `jsonKeepingNulls()`. Otherwise `theme_id: null` ("back to Dorr") never reaches the server.

## Groups

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `groups` | `name`, `description`, `avatar`, `members[]` (user ids), `disappearing_seconds` | Returns `not_added` for people whose privacy said no |
| POST | `groups/{c}` | `name`, `description`, `avatar`, `remove_avatar` | Admins only when `only_admins_edit_info` is on |
| PATCH | `groups/{c}/settings` | `only_admins_send`, `only_admins_edit_info`, `only_admins_add_members` | Admins only |
| GET / POST | `groups/{c}/members` | `members[]` | Limited by `max_group_members` |
| DELETE | `groups/{c}/members/{participant}` | | Admins only. The owner can't be removed |
| PATCH | `groups/{c}/members/{participant}/role` | `role` = admin\|member | |
| POST | `groups/{c}/leave` | | When the owner leaves, the oldest admin (or oldest member) takes over |
| GET / POST | `groups/{c}/invite`, `groups/{c}/invite/reset` | | Returns `dorr://chat/join/{token}` |
| GET / POST | `invites/{token}`, `invites/{token}/join` | | Someone who joins doesn't see earlier history |

## Contacts, privacy, blocks, presence, folders

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `contacts` | `registered`, `favorites` | |
| POST | `contacts/sync` | `contacts[] {name, phone}` (≤2000), `full` | Numbers are normalised to E.164 using the request's country. Returns the contacts who are registered |
| POST / PATCH / DELETE | `contacts`, `contacts/{id}` | `name`, `phone`, `is_favorite` | |
| POST | `contacts/lookup` | `phone`, `country_code?` | The number can be written with or without the country code (`+966…`, `00966…`, `966…`), with or without the trunk `0`, and with spaces. Numbers without a code are read in `country_code`, or else in the country of my own phone. It must be a **whole** number: exactly the country's `phone_length` digits, starting with `phone_starts_with`. Otherwise it's `422 chat_invalid_phone` (with `data.phone_length`, `dial_code`), and no search is made. 404 when the number isn't registered. Throttled to 20 per minute. `POST contacts` (manual add) uses the same rule |
| GET / POST | `contacts/qr`, `contacts/qr/reset`, `contacts/qr/resolve` | `payload` | `dorr://chat/{token}` |
| GET / PATCH | `privacy` | `last_seen`, `profile_photo`, `who_can_message`, `who_can_add_to_groups`, `who_can_call` (everyone\|contacts\|nobody), `read_receipts`, `block_screenshots`, `notification_preview` | |
| GET / POST | `blocks`, `blocks/remove` | `participant_id` | |
| POST | `presence` | `online` | Send it on foreground, then every ~60s. It expires after 90s |
| CRUD | `folders`, `folders/{id}/conversations` (PUT `conversations[]`) | | Limited by `max_folders` |

## Stories

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `stories` | | `{mine, recent, muted}`. Each item is `{owner, stories[], all_seen, last_at, block_screenshots}`, and unseen come first |
| POST | `stories` | `type` = text\|image\|video, `body`, `style[background]`, `style[font]`, `duration_ms`, `allow_replies`, `file` | Video is limited to `story_video_max_seconds`. Stories expire after `story_duration_hours` |
| DELETE | `stories/{id}` | | Mine only |
| POST | `stories/{id}/view` | | Idempotent. When my read receipts are off, the owner isn't told |
| PUT | `stories/{id}/reaction` | `emoji` | |
| POST | `stories/{id}/reply` | `body` | Becomes a `story_reply` message in our direct chat, with a snapshot of the story in `meta` |
| GET | `stories/{id}/viewers` | | Owner only. Returns an empty list when the owner's own read receipts are off |
| GET / PUT | `stories/privacy` | `audience` = contacts\|except\|only, `except[]`, `only[]` (user ids) | The audience is frozen per story when it's posted |
| POST | `stories/mute` | `participant_id`, `muted` | Returns the updated feed |

Realtime events: `chat.story.posted`, `chat.story.deleted`, `chat.story.viewed` and `chat.story.reaction` (the last two go to the owner).

## Link cards, polls, view once, live location

| Method | Path | Body | Notes |
|---|---|---|---|
| — | `messages` (send) | `type=poll`, `body` = question, `poll_options[]` (2–12), `poll_multiple` | Blank and duplicate options are dropped. `poll` in the message: `question`, `multiple`, `options[{id, text, votes}]`, `my_votes[]`, `voters` |
| PUT | `messages/{m}/vote` | `options[]` (`[]` takes the vote back) | Replaces my answer. Only one option unless `multiple`. Realtime `chat.poll.updated` sends `message_id`, `participant`, `option_ids`, `counts{option_id: n}`, `voters` |
| GET | `messages/{m}/votes` | | `[{id, text, voters[profile]}]`. Polls are not anonymous |
| — | `messages` (send) | `view_once=1` on `image`/`video`/`voice` | `attachments` is always `[]` and `view_once_opened` is set. Mine: someone opened it. Theirs: I already opened it. Can't be forwarded, and doesn't appear in the gallery |
| POST | `messages/{m}/open` | | The recipient gets `attachments` this one time only. Again → `410 chat_view_once_opened`, and the sender → `403`. Realtime `chat.view_once.opened`. `chat:purge` deletes the files 10 minutes after everyone has opened them, or after 14 days |
| — | `messages` (send) | `type=location` + `live_seconds` = 900 \| 3600 \| 28800 | `live_location {active, live_until, updated_at}`. A forwarded copy isn't live |
| PUT | `messages/{m}/live-location` | `latitude`, `longitude`, `accuracy?` | The sender only, while it's live. Otherwise `422 chat_live_location_ended`. Realtime `chat.location.moved`. Throttled to 40 per minute |
| POST | `messages/{m}/live-location/stop` | | |
| GET | `live-locations` | | My live shares still running, so the app can resume them after a restart |
| GET | `link-preview?url=` | | The link card while typing: `{url, title, description, image, site_name}` or `[]` |

**Link cards:** the first link in a text message becomes `link_preview` (it's also in `meta.link_preview`). The server fetches it **after** the response is sent (`dispatch()->afterResponse()`), then sends `chat.message.updated`. Each URL is fetched once and cached for 24 hours in `chat_link_previews`. The page is read from `og:*`, `twitter:*` or `<title>`, at most 512KB. Only public addresses are fetched: localhost and private or reserved IPs are refused, even after a redirect, and at most 3 redirects are followed. `CHAT_LINK_PREVIEW_CHECK_DNS` turns off only the DNS part of this check, for tests.

## Stickers & GIFs

| Method | Path | Notes |
|---|---|---|
| GET | `stickers` | `{packs: [{id, name, cover, stickers[{id, pack_id, emoji, url, width, height}]}], library_enabled}`. These are Dorr's own packs (admin). `library_enabled` = a Giphy key is set |
| GET | `gifs?kind=gifs\|stickers&q=&offset=` | Giphy: trending (no `q`) or search, in the request locale. `{items[{id, kind, title, url, webp, mp4, preview, width, height}], next_offset}`. Cached for 10 minutes and throttled to 60 per minute. Empty without a key |
| — | `messages` (send) `type=gif` + `giphy_id` | The server fetches it from Giphy by id and stores its URLs in `meta` (`source=giphy`). A URL sent by the client is never used. `chat_gif_not_found` |
| — | `messages` (send) `type=sticker` + `sticker_id` **or** `giphy_id` | A pack sticker (`source=pack`, active packs only, otherwise `chat_sticker_not_found`) or a Giphy sticker |

Admin: `chat-sticker-packs` (GET, POST, `POST {id}` update with a multipart `cover`, `PATCH {id}/status`, DELETE), `POST chat-sticker-packs/{id}/stickers` (`files[]` PNG/WebP/GIF up to 1MB each, max 50, `emoji?`), `PATCH / DELETE chat-stickers/{id}`. Permissions: `chat-stickers.view/create/update/delete`. `.env`: `GIPHY_API_KEY`, `GIPHY_RATING` (pg-13).

## Channels

A channel is a conversation of type `channel`. It is group-like (it has a `group` row, roles and an invite link), with these rules:
- Only the owner and admins post. Followers read, react and vote in polls.
- Followers never see each other: the member list is for admins only, there are no "joined" or "left" lines, and there's no typing indicator.
- Posts carry `views` (followers who read them) instead of ticks. Read receipts go to the admins only.
- There are no calls, no money requests, and the group-size limit doesn't apply.

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `channels` (multipart) | `name`, `description?`, `handle?`, `is_public` (default true), `avatar?` | `201` + the conversation. The handle is 3–32 characters of `[a-z0-9_]` and unique (`chat_channel_handle_invalid` / `chat_channel_handle_taken`) |
| GET | `channels/discover?search=` | | Public channels by name or @handle, biggest first: `{id, name, description, avatar, handle, is_public, followers_count, is_following, last_post_at}` |
| GET | `channels/{uuid or @handle}` | | The same card. A private channel returns 404 unless I follow it |
| POST | `channels/{uuid or @handle}/follow` | | Public channels only. A private one is followed through its invite link (`invites/{token}/join`). No system line, and no unread badge on arrival |
| POST | `channels/{c}/unfollow` | | Quiet |
| PATCH | `channels/{c}/handle` | `handle` (null removes it) | Admins |
| PATCH | `groups/{c}/settings` | `is_public` | Admins. `only_admins_send` / `only_admins_add_members` are ignored for channels |

The conversation list has a `filter=channels`. `group.handle` and `group.is_public` are on the conversation, and `group.members_count` is the number of followers.

**Performance:** a channel's followers aren't loaded for the list or the message pages. Only its admins and me are (`ChatConversation::loadViewParticipants()`).

## Money requests & bill splits

| Method | Path | Body | Notes |
|---|---|---|---|
| — | `messages` (send) | `type=money_request`, `amount_minor`, `body?` (the note) | **Direct chats only** (`chat_money_request_direct_only` in a group). It's in the requester's currency (the request's country) |
| — | `messages` (send) | `type=bill_split`, `amount_minor` (the total), `body?`, `split_mode=equal` + `split_participants[]` (participant ids), **or** `split_mode=custom` + `split_shares[{participant_id, amount_minor}]` | At least 2 people, including someone other than me. Equal: the leftover minor units go to the first people, so it always adds up. Custom must add up to the total (`chat_split_invalid`). My own share is `owner` (already paid) |
| POST | `messages/{m}/pay` | header **`X-Wallet-Pin`** | A real wallet transfer (TransferService: limits, fees, balance) to the requester, for the request or **my** share. Idempotency key = `chatpay:{message}:{me}`, so a double tap can never pay twice. Returns the message |
| POST | `messages/{m}/decline-request` | | The payer declines (the request, or their share) |
| POST | `messages/{m}/cancel-request` | | The requester closes it. What's already paid stays paid |

`payment` in the message, from **my** side:
- A request: `kind=request`, `amount_minor`, `status` (pending, paid, declined or cancelled), `can_pay`, `can_cancel`, `is_requester`.
- A split: `kind=split`, `total_minor`, `paid_minor`, `status` (open, settled or cancelled), `shares[{profile, amount_minor, status}]`, `my_share`, `can_pay`, `can_cancel`.
- Both: plus `currency`, `currency_symbol` and `decimal_places`.

The `chat.message.updated` broadcast carries the **requester's** view, so the apps re-read the message (`messages?around={id}&limit=1`). These messages can't be forwarded.

## Group join approval

| Method | Path | Notes |
|---|---|---|
| PATCH | `groups/{c}/settings` `{approve_joins}` | Admins. It adds a system line |
| GET | `invites/{token}` | Now also returns `approve_joins` and `request_status` (`pending` or null) |
| POST | `invites/{token}/join` | `200` + the conversation, or **`202` + `{status: pending, request_id}`** when approval is on. Asking twice still makes one request |
| POST | `invites/{token}/cancel` | Withdraw my pending request |
| GET | `groups/{c}/join-requests` | Admins: `[{id, profile, requested_at}]` |
| POST | `groups/{c}/join-requests/{id}/approve` · `reject` | Admins. Approving adds the person and a system line. The requester gets `chat.group.join_decided` `{conversation_id, status, group_name}`, and the admins get `chat.group.join_requests` `{pending}` |

`group.approve_joins` and `group.pending_join_requests` (the count, admins only) are on the conversation.

## Themes & reports

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `themes` | | Active admin themes: `id`, `name`, `wallpaper` (url or null), `background_color`, `sender_color`, `receiver_color`, `is_dark`, `is_default` |
| PATCH | `conversations/{c}/settings` | `theme_id` (null = none) | Must be an **active** theme (`422` otherwise). The conversation's `theme` = `{theme_id, custom, applied}`. `applied` is the pick if it's still active, else the admin default, else null (the app's own look) |
| GET | `report-types` | | Active reasons, `id` + `name` in the request locale |
| POST | `conversations/{c}/report` | `report_type_id`, `details?`, `participant_ids[]?` (group members), `block?`, `leave?` | `201`. A direct chat always reports the other person. Copies the last 30 messages **I can see** into the report. `block` works in direct chats and `leave` in groups. Throttled to 10 a minute. `chat_report_type_invalid` if the reason is inactive or missing |

## Calls (LiveKit)

| Method | Path | Notes |
|---|---|---|
| POST | `conversations/{c}/calls` `{type: audio\|video}` | Returns `call` plus `join {url, room, token}`. In a group with a call already running, you join that call |
| POST | `calls/{id}/accept` · `decline` · `leave` | Accept returns `join` |
| GET | `calls/{id}/token` | Rejoin an ongoing call |
| GET | `calls` · `calls/{id}` | Call log |

The token is a LiveKit JWT (HS256) for one room and one identity (`user:{id}`), signed with `LIVEKIT_API_SECRET`. `chat:expire-calls` runs every minute and turns a call that rang longer than `CHAT_CALL_RING_TIMEOUT_SECONDS` (45) into a missed call.

## Admin

| Method | Path | Permission |
|---|---|---|
| GET | `/api/admin/v1/chat-settings` | `chat-settings.view` |
| PUT | `/api/admin/v1/chat-settings` | `chat-settings.update` |
| GET | `/api/admin/v1/chat-themes` · `chat-themes/{id}` | `chat-themes.view` |
| POST | `/api/admin/v1/chat-themes` (multipart: `translations[i][locale/name]`, `sender_color`, `receiver_color`, `background_color`, `is_dark`, `is_default`, `status`, `sort_order`, `wallpaper`) | `chat-themes.create` |
| POST | `/api/admin/v1/chat-themes/{id}` (same fields + `remove_wallpaper`) | `chat-themes.update` |
| PATCH | `/api/admin/v1/chat-themes/{id}/status` | `chat-themes.update` |
| DELETE | `/api/admin/v1/chat-themes/{id}` | `chat-themes.delete` |
| GET / POST / PUT / DELETE | `/api/admin/v1/chat-report-types` (+ `/dropdown`, `/{id}/status`) — `translations[]`, `status`, `sort_order` | `chat-report-types.*` |
| GET | `/api/admin/v1/chat-reports?status=&report_type_id=` | `chat-reports.view` (the response also has `pending_count`) |
| GET | `/api/admin/v1/chat-reports/{id}` | `chat-reports.view`, with `messages[]` (the copied evidence) |
| PUT | `/api/admin/v1/chat-reports/{id}` `{status: pending\|reviewing\|resolved\|dismissed, admin_note}` | `chat-reports.update` |

Only one theme can be the default: saving one as default clears the others, and a default theme is always active. Deleting a theme sends the chats that used it back to the default.

---

## Real-time (Pusher, private channel `Modules.User.Models.User.{id}`)

| Event | Payload |
|---|---|
| `chat.message.sent` / `chat.message.updated` / `chat.message.deleted` | `conversation_id`, `conversation_type`, `message`. In the payload `is_mine` and `status` are null, and the app works them out from `message.sender.key` |
| `chat.receipt` | `conversation_id`, `participant`, `delivered_up_to`, `read_up_to` (message uuids) |
| `chat.reaction` | `conversation_id`, `message_id`, `participant`, `emoji`, `summary` |
| `chat.typing` | `conversation_id`, `participant`, `state` |
| `chat.presence` | `participant`, `online`, `last_seen_at`. Sent only to direct-chat peers that privacy allows |
| `chat.conversation.updated` | `conversation_id` (+`status`). The app re-fetches the conversation |
| `chat.pins.updated` | `conversation_id` |
| `chat.call.ringing` / `accepted` / `declined` / `left` / `ended` | `call_id`, `conversation_id`, `status`, `participant`, `duration_seconds`. `ringing` adds `caller` and the full call |

Push (OneSignal) goes out for new messages, respecting mute, locked chats, and `notification_preview`, and for incoming and missed calls. `data.type` is `chat` or `chat_call`.
