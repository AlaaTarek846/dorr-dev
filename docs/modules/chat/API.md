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
| GET | `conversations/{c}` | | Also returns `presence`, `i_blocked`, `blocked_me`, and `block_screenshots` for a direct chat, `can_call` (calls on for the platform and in my country — and, one-to-one, in theirs; the app hides the call buttons when false), and `business` for a peer with opening hours: `{hours[7] {open, from, to}, timezone, open_now}` (Sunday first) |
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
| POST | `conversations/{c}/messages` | `type`, `body`, `uuid` (idempotent), `reply_to`, `mentions[]` (participant ids), `silent` (bool), `urgent` (bool), `files[]`, plus the fields each type needs (see below) | Multipart when there are files. `silent=1` stores `is_silent` and sends the push with `data.silent = "1"`, which the app shows without sound or vibration |
| PATCH | `messages/{m}` | `body` | Own messages only, within `edit_window_minutes` |
| DELETE | `messages/{m}` | | Deletes for everyone: my own message within the window, or any message if I'm a group admin |
| POST | `conversations/{c}/messages/delete-for-me` | `messages[]` | |
| POST | `messages/forward` | `messages[]`, `conversations[]` (≤ `max_forward_targets`) | Media is copied. `wallet_transfer`, `call` and `system` messages can't be forwarded |
| PUT | `messages/{m}/reaction` | `emoji` (null removes it) | One reaction per person |
| GET | `messages/{m}/reactions` | | Who reacted with what |
| PUT | `messages/{m}/star` | `starred` | |
| GET | `messages/starred`, `conversations/{c}/starred` | | |
| PUT | `messages/{m}/read-later` | `on` (bool) | "Read later": my own list, apart from stars. The message carries `is_read_later` |
| GET | `messages/read-later` | | My list across every chat, oldest first (up to 200) |
| PUT | `messages/{m}/follow-up` | `on` (bool) | "Needs a reply" (spec 118): on my follow-up list until I answer. In a one-to-one chat anything I send afterwards answers it; in a group only a reply to it does. The message carries `is_follow_up` |
| GET | `messages/follow-up` | | Waiting for my reply, oldest first |
| PUT / DELETE | `messages/{m}/reminder` | `remind_at` (ISO-8601, 30 s to 365 days ahead), `note?` (≤200) | "Remind me" (spec 47, 121): one per message per person. `chat:send-reminders` (every minute) pushes it once (title "⏰ Reminder", the note or the text — following my `notification_privacy`) and sends `chat.reminder.due` `{conversation_id, message_id, note}`. The message carries `reminder_at` |
| GET | `reminders` | | My reminders still to come: `{remind_at, note, message}` |
| POST / DELETE | `messages/{m}/pin` | `duration_seconds` = 86400\|604800\|2592000 | Pinned for everyone. The oldest pin gives way at `max_pinned_messages` |
| GET | `conversations/{c}/pinned` | | |
| GET | `messages/{m}/info` | | My own message: `read_by`, `delivered_to`, `pending` |
| GET | `messages/search`, `conversations/{c}/messages/search` | `q` | |
| GET | `conversations/{c}/gallery` | `kind` = media\|documents\|audio\|links\|locations, `before` | |

**Live location** — a `location` message the sender keeps moving until it runs out or is stopped. Durations are 900 (15 min), 3600 (1 h) or 28800 (8 s); the server writes `live_until` (ISO 8601) and `stopped` into the message `meta`.

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `live-locations` | | My live locations still running — `message_id`, `conversation_id`, `live_until` each. The app resumes sending positions after a restart |
| PUT | `messages/{m}/live-location` | `latitude`, `longitude`, `accuracy` (nullable) | A new position for my live location. Throttled to 120 per minute (`chat-live-location`) |
| POST | `messages/{m}/live-location/stop` | | Ends it and returns the updated message. Throttled to 30 per minute (`chat-live-stop`) |

Moving or stopping a location that is not mine, not a `location` message, or already over answers `422` (`chat_live_location_ended`).

**Fields each message type needs:**

| `type` | Required fields |
|---|---|
| `text` | `body` |
| `image` / `video` / `audio` / `document` | `files[]`. `body` becomes the caption. Video can add `duration_ms` |
| `voice` | `files[]`, `duration_ms`, `waveform[]` (0–100, up to 200 bars) |
| `location` | `latitude`, `longitude`, `location_name`, `address`, `live_seconds` (900\|3600\|28800 for a live one) |
| `contact` | `contact_name`, `contact_phones[]` |
| `poll` | `body` (the question), `poll_options[]`, `poll_multiple` (optional) |
| `gif` | `giphy_id`. The server resolves it; a `url` from the client is ignored |
| `sticker` | `sticker_id` from a pack the admin published |
| `money_request` | `amount_minor`, in a **direct** chat only |
| `bill_split` | `amount_minor`, `split_mode` = equal\|custom, with `split_participants[]` or `split_shares[{participant_id, amount_minor}]` |
| `wallet_transfer` | `wallet_transaction_id`. This must be **my own** `transfer_out`, and the server builds the card |
| `wallet_qr` | `country_code` (optional, defaults to the request's country). The server builds the card from **my own** wallet |

`image` / `video` / `voice` can add `view_once` (the file is handed out once per recipient by `messages/{m}/open`, then deleted by `chat:purge`).

**Message shape:** `id`, `conversation_id`, `type`, `body`, `meta`, `attachments[]`, `sender` (profile), `is_mine`, `status` (sent\|delivered\|read, my messages only), `reply_to`, `is_forwarded`, `forwarded_many_times`, `mentions[]`, `is_edited`, `is_deleted`, `expires_at`, `reactions {summary, mine, total}`, `is_starred`, `system {event, actor, targets, text}`, `created_at`, plus what the extra types need: `poll`, `payment`, `view_once`, `view_once_opened`, `live_location`, `link_preview`.

## Polls, view once, links, money

| Method | Path | Body | Notes |
|---|---|---|---|
| PUT | `messages/{m}/vote` | `options[]` (option ids; empty takes the vote back) | A single-choice poll refuses more than one |
| GET | `messages/{m}/votes` | | Who voted for each option |
| POST | `messages/{m}/open` | | The view-once files, once. `403` for the sender, `410` when already opened |
| GET | `link-preview` | `url` (query) | The card for a link being typed, or an empty object when there is none |
| POST | `messages/{m}/pay` | | The PIN travels in `X-Wallet-Pin`. One transfer per payer, however often it is retried |
| POST | `messages/{m}/decline-request`, `messages/{m}/cancel-request` | | "Not paying this" / "never mind" |
| POST | `conversations/{c}/send-money` | `amount_minor`, `note?` (≤500), `uuid` | **Send money** straight from a one-to-one chat (no request first). The PIN travels in `X-Wallet-Pin`. A normal wallet transfer to the other person, then its `wallet_transfer` receipt posted with `id = uuid` and the note as its body. The uuid is also the transfer's idempotency key: a retry gives the same transfer and message. Not in groups, channels or my notes (`chat_money_request_direct_only`); blocked either way → `403` before any money moves. Throttled to 20 per minute |

`payment` is `kind: request` (`amount_minor`, `can_pay`, `can_cancel`) or `kind: split` (`total_minor`, `mode`, `shares[] {profile, amount_minor, status}`, `paid_minor`, `my_share`, `status` open\|settled). Money and view-once messages can never be forwarded (`422`).

**Message shape:** `id`, `conversation_id`, `type`, `body`, `meta`, `attachments[]`, `sender` (profile), `is_mine`, `status` (sent\|delivered\|read, my messages only), `reply_to`, `is_forwarded`, `forwarded_many_times`, `mentions[]`, `is_edited`, `is_deleted`, `expires_at`, `reactions {summary, mine, total}`, `is_starred`, `system {event, actor, targets, text}`, `created_at`, plus what the extra types need: `poll`, `payment`, `view_once`, `view_once_opened`, `live_location`, `link_preview`.

**Text formatting.** `body` is stored as typed. The apps draw WhatsApp-style marks: `*bold*`, `_italic_`, `~strike~`, `` `code` `` and ```` ```block``` ````, and at the start of a line `- ` or `* ` (bullet), `1. ` (numbered) and `> ` (quote) — not inside a ```` ``` ```` block. The push text shows bullets as "• " and drops the quote mark. A mark only counts at a word edge and around non-blank text, so `5*3*2` and `snake_case` stay as typed. Nothing is formatted inside code or links. The push text drops the marks (`ChatPushNotifier::plain()`). The same rules live in `ChatFormatting.kt` (Android) and `MessengerBubble.vue` (web).

**Media page.** `GET conversations/{c}/gallery?kind=media|documents|audio|links|locations&before={uuid}` returns 60 per page with `has_more`. View-once and deleted messages are left out. The app's "Media, links and docs" page pages through it, and "show in chat" scrolls the conversation to the message (`around`).

**My own chat look (`custom_theme`).** Each participant can set `sender_color`, `receiver_color`, `background_color` (hex) and `dim` (0–80, how much the photo is darkened). The photo itself is uploaded through `POST …/wallpaper`; a URL is never accepted (`wallpaper` can only be `null`, which removes it). Setting a key to `null` falls back to the theme's value, and `custom_theme: null` drops the whole look and deletes the photo. `theme.custom` returns my look (the photo as a URL). `theme.applied` returns what to draw: the picked or default theme with my look on top (`is_custom: true`, `id: 0`, `dim`). See `ChatThemeService::appliedFor()`. **Note for clients:** Retrofit's default Gson drops `null` map values, so the Android app sends these settings with `updateSettingsJson` + `jsonKeepingNulls()`. Otherwise `theme_id: null` ("back to Dorr") never reaches the server.

**Urgent messages (spec 116–117).** `urgent: true` on a one-to-one message (else `422 chat_urgent_direct_only`) when the recipient allows it — privacy `who_can_urgent`: everyone | contacts (default) | nobody, else `403 chat_urgent_not_allowed` — and at most 3 in 24 hours to the same person (`429 chat_urgent_quota`). It gets through a mute: the push goes out with the title "🚨 {name}" and `data.urgent = "1"`. The message carries `is_urgent`.

**Money gifts (spec 65).** `send-money` takes `gift` (general | birthday | eid | ramadan | wedding | newborn | graduation | congrats | thanks): the receipt's `meta.gift`, which the app draws as a card for the occasion.

**Privacy mode and sensitive messages (spec 104–113).** `sensitive: true` on a message: its content never shows in a push (at most the sender's name), the chat list's `last_message.body` is `null` with `is_sensitive: true`, and the app hides it until the recipient unlocks it. `PUT privacy-mode` `{minutes: 5–10080}` turns quick privacy mode on, `DELETE privacy-mode` turns it off and returns `{settings, summary: {messages, conversations, from}}`, and `GET privacy-mode/summary?from=` gives the same summary after a timed mode ended. `PATCH privacy` takes `privacy_schedule {from: "HH:mm", to: "HH:mm", days?: [0–6], timezone?}` (or `null`): a daily window, which may run past midnight. While privacy mode is on (now or by schedule), every chat push says only "Dorr / New message", and those pushes carry `data.private = "1"` so the phone folds them into one "N new messages". `GET privacy` returns `privacy_mode {on, until, started_at, schedule}`.

**Personal status (spec 90).** `PUT status` `{emoji?, text? (≤100), until? (future ISO), audience? (everyone | contacts | nobody)}` (one of emoji / text required: `chat_status_empty`), `DELETE status`. `GET privacy` returns it as `status {emoji, text, until, audience, active}`. Profiles carry `status {emoji, text, until}` while it lasts and only for its audience (contacts = people the owner saved).

## Groups

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `groups` | `name`, `description`, `avatar`, `members[]` (user ids), `disappearing_seconds` | Returns `not_added` for people whose privacy said no |
| POST | `groups/{c}` | `name`, `description`, `avatar`, `remove_avatar` | Admins only when `only_admins_edit_info` is on |
| PATCH | `groups/{c}/settings` | `only_admins_send`, `only_admins_edit_info`, `only_admins_add_members`, `approve_joins`, `slow_mode_seconds` (0\|10\|30\|60\|300\|900\|3600), `banned_words[]` (≤200, each ≤50 chars) | Admins only. Slow mode and banned words are for groups (ignored on a channel) |
| GET / POST | `groups/{c}/members` | `members[]` | Limited by `max_group_members` |
| DELETE | `groups/{c}/members/{participant}` | | Admins only. The owner can't be removed |
| PATCH | `groups/{c}/members/{participant}/role` | `role` = admin\|member | |
| POST | `groups/{c}/leave` | | When the owner leaves, the oldest admin (or oldest member) takes over |
| POST | `groups/{c}/owner` | `participant_id` | Owner only (`403 chat_owner_action_only`): that member becomes the owner and I stay an admin. System line `owner_changed`. Groups and channels |
| DELETE | `groups/{c}` | | Owner only: the group / channel is deleted for everyone (everyone leaves, soft-deleted). Members hear `chat.conversation.updated`, get `404` for it, and drop it from their list |
| GET / POST | `groups/{c}/invite`, `groups/{c}/invite/reset` | `expires_in_hours` (reset only: 1\|24\|168\|720, or none = never) | Returns `{token, link: dorr://chat/join/{token}, expires_at}`. A reset kills the old link at once. Opening an expired link as an admin makes a fresh one that never expires |
| GET / POST | `invites/{token}`, `invites/{token}/join`, `invites/{token}/join/cancel` | | Someone who joins doesn't see earlier history. The preview carries `approve_joins` and `request_status`. An expired link answers `410 chat_invite_expired` |

**Moderation.** With `slow_mode_seconds` on, a member (not an admin) who sent less than that long ago gets `429 chat_slow_mode` with `data.retry_after` (seconds left); turning it on or off leaves a system line (`slow_mode_on` with `seconds`, `slow_mode_off`). A member's text, caption, poll option or edit containing a banned word is refused with `422 chat_banned_word` (admins are exempt). Matching ignores case, tashkeel and tatweel, treats أ/إ/آ/ا, ى/ي and ة/ه as the same letter, allows any spacing inside a phrase, and never matches inside another word (`Support\BannedWords`). `group.banned_words` is only returned to admins (`null` for members); `group.slow_mode_seconds` is returned to everyone.

**Joining by link with `approve_joins` on** leaves a request instead of a membership: `invites/{token}/join` answers `202` with `status: pending`, the conversation reports `group.pending_join_requests` to admins, and the queue is answered with:

| Method | Path | Notes |
|---|---|---|
| GET | `groups/{c}/join-requests` | Admins only — `id`, `profile`, `requested_at` each |
| POST | `groups/{c}/join-requests/{request}/approve`, `.../reject` | Answers with the queue that is left; answering a closed request is `404` |

## Contacts, privacy, blocks, presence, folders

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `contacts` | `registered`, `favorites` | |
| POST | `contacts/sync` | `contacts[] {name, phone}` (≤2000), `full` | Numbers are normalised to E.164 using the request's country. Returns the contacts who are registered |
| POST / PATCH / DELETE | `contacts`, `contacts/{id}` | `name`, `phone`, `is_favorite` | |
| POST | `contacts/lookup` | `phone`, `country_code?` | The number can be written with or without the country code (`+966…`, `00966…`, `966…`), with or without the trunk `0`, and with spaces. Numbers without a code are read in `country_code`, or else in the country of my own phone. It must be a **whole** number: exactly the country's `phone_length` digits, starting with `phone_starts_with`. Otherwise it's `422 chat_invalid_phone` (with `data.phone_length`, `dial_code`), and no search is made. 404 when the number isn't registered. Throttled to 20 per minute. `POST contacts` (manual add) uses the same rule |
| GET / POST | `contacts/qr`, `contacts/qr/reset`, `contacts/qr/resolve` | `payload` | `dorr://chat/{token}` |
| GET / PATCH | `privacy` | `last_seen`, `profile_photo`, `who_can_message`, `who_can_add_to_groups`, `who_can_call` (everyone\|contacts\|nobody), `read_receipts`, `block_screenshots`, `notification_privacy` (all\|name\|none) | What a chat push shows: `all` = name + text, `name` = name + "New message", `none` = "Dorr" + "New message". The old switch `notification_preview` (bool) is still accepted (true → all, false → none) and still returned |
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
| — | `messages` (send) `type=sticker` + `sticker_id` **or** `my_sticker_id` **or** `giphy_id` | A pack sticker (`source=pack`, active packs only, otherwise `chat_sticker_not_found`), one of mine (`source=mine`, only my own) or a Giphy sticker |
| POST | `stickers/mine` | **My stickers** (made from my own photos in the app: cut-out with a white outline, or circle / rounded square). `image` PNG/WebP, ≤1 MB, ≤1024×1024 (the app sends 512×512 with a transparent background), `emoji?`. Up to 200 (`chat_my_stickers_full`). Throttled to 30 per minute. `GET stickers` returns them in `mine[]` |
| DELETE | `stickers/mine/{id}` | Removes it from my list; messages already sent keep it |

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

## Scheduled messages

Text written now and sent later by the server (`chat:send-scheduled`, every minute — needs the scheduler running). It goes out through the normal send, so every rule is checked at that moment; one that can't go stays as `failed` with `error_code` (e.g. `chat_blocked`). The scheduled id becomes the message's uuid, so it can never be sent twice. Only the author sees them; changes reach the author's devices as `chat.scheduled.changed` `{conversation_id, id, status, pending}`.

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `conversations/{c}/scheduled` | | Mine here that are `pending` or `failed`, soonest first: `{id, conversation_id, body, is_silent, send_at, status, error_code}` |
| POST | `conversations/{c}/scheduled` | `body`, `send_at` (ISO-8601 with offset), `silent?` | From 30 s to 365 days ahead (`chat_schedule_time_invalid`), up to 100 waiting (`chat_too_many_scheduled`). Refused up front where I can't post (`chat_admins_only`) or with a banned word. Throttled to 30 per minute |
| PATCH | `scheduled/{id}` | `body?`, `send_at?`, `silent?` | Editing a failed one puts it back in the queue (a minute from now if its time has passed) |
| DELETE | `scheduled/{id}` | | `404 chat_scheduled_not_found` once sent or deleted |
| POST | `scheduled/{id}/send` | | Send now; returns the message |

## Business tools

For the accounts in `config('chat.business_participants')` (users and providers today), otherwise `403 chat_business_unavailable`.

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `business` | | `{profile, quick_replies[]}` |
| PATCH | `business` | `welcome_enabled`, `welcome_message` (≤1000), `away_enabled`, `away_message` (≤1000), `away_mode` (always\|outside_hours), `hours[7] {open, from: "HH:mm", to: "HH:mm"}` (Sunday first; `to` < `from` runs past midnight), `timezone` | Switching a message on without text → `chat_business_message_required`; "away outside hours" without any hours → `chat_business_hours_required` |
| GET / POST | `quick-replies` | `shortcut` (letters, digits, `_`, `-`, ≤32; a leading `/` is dropped; stored lower-case), `body` (≤4000) | Up to 100 (`chat_too_many_quick_replies`); a shortcut I already use → `chat_quick_reply_taken`. The app shows them when the field starts with `/` |
| PATCH / DELETE | `quick-replies/{id}` | | Only my own (`404 chat_quick_reply_not_found`) |

**Automatic replies** go out as the business, only in one-to-one chats, right after a customer's message: the **away** message when it's on and (for `outside_hours`) the business is closed in its own time zone — at most once per 12 hours in a chat — otherwise the **welcome** message on a customer's first message or the first after 14 quiet days. They carry `meta.auto_reply` (`welcome`\|`away`; the apps label them "Automatic reply"), don't accept a message request, and don't mark the customer's message as read. Nothing is sent when either side blocked the other.

## AI

Translate, voice to text, summary and suggested replies, through the AI module's provider (`Services\ChatAiService`). Each is **one tap by the person** — nothing is ever sent to an AI provider by itself. Only chats I'm in, only messages I can see, never a view-once message (`chat_ai_view_once`). Translations and transcripts are cached per message (30 days), summaries for 10 minutes. The admin switch is `ai_enabled` in chat settings.

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `ai` | | `{enabled, translate, summarize, smart_replies, transcribe}` — the app shows the buttons from this. `transcribe` needs a provider that takes audio (OpenAI or Groq Whisper, or Google Gemini; Anthropic can't) |
| POST | `messages/{m}/translate` | `to?` (ar, en, fr, es, de, tr, ur, hi, bn, id, fa, ru, zh, pt, it, tl; default = request locale) | `{text, to}`. Formatting marks are removed first. Throttled to 60 per minute |
| POST | `messages/{m}/transcribe` | | Voice / audio messages (≤25 MB): `{text}`. Throttled to 20 per minute |
| POST | `conversations/{c}/summarize` | `unread_only?` | `{text, messages}`: 3–7 bullet points in the request language, from my latest 200 visible messages (12 000 characters, newest kept), or only those after my last read. Throttled to 10 per minute |
| POST | `conversations/{c}/smart-replies` | | `{replies[3]}`: short replies in the chat's own language and dialect, from the last 15 messages. They're only suggestions — nothing is sent. Throttled to 20 per minute |

Errors: `503 chat_ai_unavailable` (switched off, or no provider), `502 chat_ai_failed` (the provider failed — its own message goes to the log only), `422 chat_ai_nothing_to_translate`, `chat_ai_not_voice`, `chat_ai_audio_too_long`, `chat_ai_nothing_to_summarize`.

## Calls (LiveKit)

| Method | Path | Notes |
|---|---|---|
| POST | `conversations/{c}/calls` `{type: audio\|video}` | Returns `call` plus `join {url, room, token}`. In a group with a call already running, you join that call |
| POST | `calls/{id}/accept` · `decline` · `leave` | Accept returns `join` |
| GET | `calls/{id}/token` | Rejoin an ongoing call |
| GET | `calls` · `calls/{id}` | Call log |

Calls can be switched off per country (`calls_disabled_countries` in chat settings, by the account's `country_id`): the caller gets `403 chat_calls_unavailable_country`, calling someone there gets `403 chat_calls_unavailable_peer_country`, and in a group members there simply aren't rung.

The token is a LiveKit JWT (HS256) for one room and one identity (`user:{id}`), signed with `LIVEKIT_API_SECRET`. `chat:expire-calls` runs every minute and turns a call that rang longer than `CHAT_CALL_RING_TIMEOUT_SECONDS` (45) into a missed call.

## Admin

| Method | Path | Permission |
|---|---|---|
| GET | `/api/admin/v1/chat-settings` | `chat-settings.view` |
| PUT | `/api/admin/v1/chat-settings` (also `calls_disabled_countries[]` country ids, `ai_enabled`) | `chat-settings.update` |
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
