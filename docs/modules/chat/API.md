# Chat Module — API

Base: `/api/mobile/v1/chat` · guard `user_api` · middleware `locale`, `ensure-phone-verified`, `country`, `remember-locale`. Send `X-Country` like the wallet does.
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
| GET | `conversations/{c}` | | Also returns `presence`, `i_blocked`, `blocked_me`, and `block_screenshots` for a direct chat |
| PATCH | `conversations/{c}/settings` | `pinned`, `archived`, `locked`, `marked_unread`, `mute` (8h\|1w\|always\|off), `theme_id`, `custom_theme` | Only for me |
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
| POST | `conversations/{c}/messages` | `type`, `body`, `uuid` (idempotent), `reply_to`, `mentions[]` (participant ids), `files[]`, plus the fields each type needs (see below) | Multipart when there are files |
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

`payment` is `kind: request` (`amount_minor`, `can_pay`, `can_cancel`) or `kind: split` (`total_minor`, `mode`, `shares[] {profile, amount_minor, status}`, `paid_minor`, `my_share`, `status` open\|settled). Money and view-once messages can never be forwarded (`422`).

**Message shape:** `id`, `conversation_id`, `type`, `body`, `meta`, `attachments[]`, `sender` (profile), `is_mine`, `status` (sent\|delivered\|read, my messages only), `reply_to`, `is_forwarded`, `forwarded_many_times`, `mentions[]`, `is_edited`, `is_deleted`, `expires_at`, `reactions {summary, mine, total}`, `is_starred`, `system {event, actor, targets, text}`, `created_at`, plus what the extra types need: `poll`, `payment`, `view_once`, `view_once_opened`, `live_location`, `link_preview`.

## Groups

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `groups` | `name`, `description`, `avatar`, `members[]` (user ids), `disappearing_seconds` | Returns `not_added` for people whose privacy said no |
| POST | `groups/{c}` | `name`, `description`, `avatar`, `remove_avatar` | Admins only when `only_admins_edit_info` is on |
| PATCH | `groups/{c}/settings` | `only_admins_send`, `only_admins_edit_info`, `only_admins_add_members`, `approve_joins` | Admins only |
| GET / POST | `groups/{c}/members` | `members[]` | Limited by `max_group_members` |
| DELETE | `groups/{c}/members/{participant}` | | Admins only. The owner can't be removed |
| PATCH | `groups/{c}/members/{participant}/role` | `role` = admin\|member | |
| POST | `groups/{c}/leave` | | When the owner leaves, the oldest admin (or oldest member) takes over |
| GET / POST | `groups/{c}/invite`, `groups/{c}/invite/reset` | | Returns `dorr://chat/join/{token}` |
| GET / POST | `invites/{token}`, `invites/{token}/join`, `invites/{token}/join/cancel` | | Someone who joins doesn't see earlier history. The preview carries `approve_joins` and `request_status` |

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
| POST | `contacts/lookup` | `phone` | 404 when the number isn't registered. Throttled to 20 per minute |
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
