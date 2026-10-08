# Discover API

All responses use the standard envelope (`App\Support\Api\ApiResponse`). Lists that paginate have `pagination` at the top level. Errors have `error_code` set to `discover_<code>`. `timezone` (IANA) on any app call is where the person is now. When it's missing, the account's saved zone is used, and then the app's default.

## App: `mobile/v1/discover/*`
Middleware: `auth:user_api`, `ensure-phone-verified:user_api`, `country`, `locale`. Discover must be on for the request's country (`X-Country` and so on), except for `travel`, which checks the destination's country. Otherwise the response is `403 discover_off`.

| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `home` | `timezone` | `{country, categories[], cities[] (my country), follows[], preferences, can_submit, ai, sections[{key, items[]}]}`. Section keys: `for_you`, `this_week`, `weekend`, `free`, `popular`, `followed`. Empty sections are left out |
| GET | `events` | `city_id` or `country_id` (default: my country), `category_ids[]`, `from`, `to` (Y-m-d), `free`, `family`, `q`, `lat`, `lng`, `km`, `sort=soon\|popular`, `page`, `per_page`, `timezone` | `data: Event[]` + `pagination`. With one city the days are cut in that city's zone, otherwise in mine |
| GET | `travel` | `city_id`, `from`, `to` (at most 31 days apart), plus the same filters | `{city, from, to, items: Event[], meta}`. Days are cut in the city's zone (AT-DISC-03). `422 discover_travel_range` |
| POST | `ask` | `{text}` | `{filters: {city_id?, city?, category_ids?, categories[], from?, to?, free?, family?, q?}, items: Event[], meta}`. Throttled to 20 per minute. `403 discover_ai_off`, `503 chat_ai_unavailable` |
| GET | `categories` | — | `[{id, key, name, emoji, color}]` |
| GET | `cities` | `country_id?` | `[{id, name, country, country_id, timezone, lat, lng}]` (all active cities when `country_id` is left out) |
| GET | `events/{uuid}` | `timezone` | The full Event. A pending or rejected event is visible only to its own organizer, and `404 discover_not_found` to everyone else |
| POST | `events/{uuid}/interest` | `{notify?: bool}` (default true) | The Event with `interested` and `notify` |
| DELETE | `events/{uuid}/interest` | — | `null` |
| GET | `interests` | `past=0\|1` | `Event[]` (coming up, or already over) |
| GET / PUT | `preferences` | `{categories?: int[], alerts?: bool, alert_days?: 3\|7\|14\|30, family_only?: bool}` | `{categories, alerts, alert_days, family_only}` |
| GET | `follows` | — | `[{id, kind: city\|country, target_id, name, country}]` |
| POST | `follows` | `{kind, target_id}` | The list. At most 30: `422 discover_too_many_follows` |
| DELETE | `follows/{id}` | — | The list |
| POST | `events/{uuid}/share` | `{conversation_id (chat uuid), comment?, poll?: bool}` | `201 {message_id, poll_id?, conversation_id}`. It sends an `event_card` message and, with `poll`, a poll ("going · maybe · can't make it") |
| POST | `events/{uuid}/room` | `{members: user ids[]}` | `201 {conversation, not_added}`. A new group with the event's card. `422 discover_event_over`, `422 discover_room_needs_members` |
| GET | `organizer` | `timezone` | `{organizer: Organizer\|null, can_submit, events: Event[] + review_status, review_note}` |
| POST | `organizer` | `{name, about?, website?, phone?, email?}` | Organizer (`status: pending`). A rejected organizer goes back to pending. A verified one who changes name is reviewed again |
| POST | `organizer/events` | `{title, category_id, city_id, starts_at (the event's local time, e.g. "2026-11-05 20:00"), ends_at?, venue?, address?, lat?, lng?, is_free?, price_text?, booking_url?, source_url?, family_friendly?, description?, language?}` + `cover` (multipart, optional) | `201` Event (`review_status` is `approved` for a verified organizer when direct publishing is on, otherwise `pending`). `403 discover_not_organizer`, `discover_organizer_suspended`, `discover_submissions_off`. `409 discover_duplicate {event_id}` |
| POST | `organizer/events/{uuid}` | The same fields (all optional) | Event. A new time on a public event is a status change that the interested hear about. An unverified organizer's edit goes back to review |
| POST | `organizer/events/{uuid}/status` | `{status: confirmed\|postponed\|cancelled\|sold_out\|ended, note?, starts_at? (local)}` | Event |

**Event** (lists): `id` (uuid), `title`, `category{id,key,name,emoji,color}`, `city{id,name,timezone}`, `country`, `venue`, `address`, `lat`, `lng`, `starts_at`/`ends_at` (ISO, UTC), `timezone`, `local_date`, `local_time`, `local_end`, `my_time` (`Y-m-d H:i` in my zone when it differs), `is_free`, `price_text`, `family_friendly`, `status`, `verified`, `organizer{name, verified}`, `cover`, `interested_count`, `interested`, `notify`, `distance_km?`.
**Full** (show): the list fields plus `description`, `language`, `source` (`admin`\|`organizer`), `source_url`, `booking_url`, `last_verified_at`, `status_note`, `old_starts_at`, `history[{from, to, note, old_starts_at, at}]`, `mine`, `review_status`, `review_note` (the last two only when `mine`).

**Push data:** `{type: "discover", event: "discover.event.changed", event_id, status}` for a status change, and `{type: "discover", event: "discover.alert", event_id?}` for "don't miss it".

## Admin: `admin/v1/*`
Middleware: `auth:admin_api`. Permissions are listed per resource.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET / PUT | `discover-settings` | `discover-settings.view` / `.update` | `enabled`, `enabled_countries[]` (empty means everywhere), `submissions_enabled`, `auto_publish_verified`, `alerts_enabled`, `max_alerts_per_week` (0–21), `room_close_hours` (0–720), `ai_enabled` |
| apiResource + PATCH `{id}/status` | `discover-categories` | `discover-categories.*` | `{key, emoji?, color?, status?, sort_order?, translations[{locale, name}]}`. You can't delete one that events use (`422 discover_in_use`) |
| apiResource + PATCH `{id}/status` | `discover-cities` | `discover-cities.*` | `{country_id, timezone, lat?, lng?, status?, sort_order?, translations[]}`; `?country_id=` filter |
| GET | `discover-organizers` | `discover-organizers.view` | `?status=&search=&per_page=`, with `pending_count`. Rows include the account (name, phone) |
| GET | `discover-organizers/{id}` | `.view` | |
| PATCH | `discover-organizers/{id}/review` | `discover-organizers.update` | `{status: verified\|rejected\|suspended\|pending, note?}` |
| GET | `discover-events` | `discover-events.view` | `?review_status=&status=&country_id=&city_id=&category_id=&organizer_id=&when=upcoming\|past&search=&per_page=`. Pending events come first, and the response has `pending_count` |
| GET | `discover-events/{uuid}` | `.view` | The full Event plus `starts_local` / `ends_local` (`Y-m-d\TH:i` in its zone) for the form |
| POST | `discover-events` | `discover-events.create` | Multipart with the same fields as the organizer form, plus `cover`. Published at once as `source = admin` |
| POST | `discover-events/{uuid}` | `.update` | Edit. A new time on a public event goes through the status history |
| PATCH | `discover-events/{uuid}/review` | `.update` | `{review_status: approved\|rejected\|pending, note?}` |
| PATCH | `discover-events/{uuid}/status` | `.update` | `{status, note?, starts_at?, ends_at?}` (the event's local time). The interested who asked are told |
| DELETE | `discover-events/{uuid}` | `discover-events.delete` | |
