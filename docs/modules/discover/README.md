# Discover Module (`Modules/Discover`)

DORR Discover (spec 169–182): public events from trusted sources, by city, interest and date. Events can be saved into DORR Calendar and shared in chats. There is no external event provider. Events come from two places:

- **The admin:** an official source, published and trusted at once.
- **Organizers:** anyone can ask to be one. Their events are public only after review, unless the organizer is verified and the admin allows direct publishing. An event shows as **verified** only when its organizer is verified (AT-DISC-02).

The endpoints are in [API.md](API.md).

## Rules worth knowing
- **Time zones.** An event is stored as a UTC instant plus its city's IANA zone. Lists show its own local time (`local_date`, `local_time`) and mine (`my_time`) when I'm somewhere else. A travel search cuts days in the destination's zone (AT-DISC-03).
- **Status changes.** These are confirmed, postponed (maybe with a new time), cancelled, sold out and ended. Every change goes into `discover_event_status_history`. Only people who said "interested" **and** asked for updates (`notify`) get a push (AT-DISC-01). "Ended" tells nobody.
- **Duplicates.** `dedupe_key` is a hash of the normalised title, the city and the local day. A second submission of the same event is refused with `409 discover_duplicate`, and the response carries the existing event's id.
- **Alerts ("don't miss it", 172).** `discover:alerts` runs hourly. It sends confirmed events in my interests, where I live or in places I follow, within my window (3, 7, 14 or 30 days). It never sends the same event twice (`discover_alert_log`), never sends past the admin's weekly limit, never during my quiet hours, and never for events I'm already interested in.
- **Event rooms (179).** A chat group named after the event, with its card as the first message. `discover:close-rooms` runs every 30 minutes. Some hours after the event (`room_close_hours`), it switches the group to admins-only and adds a system line.
- **AI (181).** The AI turns a question into filters only: a city id, kind keys, dates, free and family. The events come from Discover itself, so the AI can't invent one. If a search word matches nothing, the search runs again without it.
- **Chat.** `MessageType::EventCard` (`event_card`) has `meta.event` set to a snapshot built on the server. The poll is an ordinary chat poll.
- **Calendar.** Events I'm interested in are the calendar's `discover` source (`ChatCalendarPreference::SOURCES`). The calendar reads them only when this module exists.
- **Admin switches (182).** These live in `discover_settings`, cached as `discover.settings`: on/off, which countries (empty means everywhere), organizer submissions, direct publishing for verified organizers, alerts and the weekly limit, room closing hours, and the AI.

## Layout
| Path | What |
|---|---|
| `app/Models` | `DiscoverSetting`, `DiscoverCategory` (+ translations), `DiscoverCity` (+ translations, timezone), `DiscoverOrganizer`, `DiscoverEvent` (cover via media library), `DiscoverEventStatus`, `DiscoverInterest`, `DiscoverFollow`, `DiscoverPreference`, `DiscoverEventRoom` |
| `app/Services/DiscoverService.php` | Home, search, travel, show, interests, follows, preferences, catalogs, presenting |
| `app/Services/OrganizerService.php` | Applying, submitting, editing, status, field parsing in the city's zone, dedupe |
| `app/Services/EventStatusService.php` | Status and time changes, history, and the push to the interested |
| `app/Services/DiscoverAiService.php` | Questions to filters, through the chat's central AI provider (`ChatAiService`) |
| `app/Services/DiscoverChatService.php` | Event card, poll, event room |
| `app/Console` | `discover:alerts` (hourly), `discover:close-rooms` (every 30 min) |
| `app/Exceptions/DiscoverException.php` | `discover_<code>` errors; messages in `lang/{en,ar}/discover.php` |
| `database/migrations/2026_10_12_100000_create_discover_tables.php` | All tables; every `timestamp` is nullable (strict MySQL) |
| `database/seeders/DiscoverSeeder.php` | 10 kinds, plus cities with zones in SA, EG, AE, KW, QA, BH, OM and JO (for the countries that exist) |
| `routes/mobile.php` · `routes/admin.php` | `mobile/v1/discover/*` (user app) · `admin/v1/discover-*` |

**Admin (Vue):** `resources/js/modules/admin/themes/theme-1/views/discover/` holds events (the review queue, add and edit, status), organizers, catalog (categories and cities) and settings. Sidebar section: "DORR Discover".

**Android:** `network/EventsApi.kt` (`ApiClient.events`) and `ui/screens/events/`:
- `EventsScreen`: home, browse, travel, ask AI, saved, settings, organizer and submit.
- `EventsParts`: cards, the detail page, the share, status and city sheets, the chat bubble, and the home banner.

`EventsLink` opens Discover from anywhere: the home banner, a chat card, the calendar, or a push with `type=discover`.

**Tests:** `tests/Feature/DiscoverTest.php` (8 tests: AT-DISC-01/02/03, home and calendar, chat card, poll and room, AI, alerts, admin switches).

## Not done / later
- Android: organizers can't upload a cover from the app yet. The admin can.
- Web user app: deferred (mobile first). The API is ready.
- No external event feed or importer. If one is added, it should write events with `source = admin` (or a new source) through `OrganizerService::fields()` and `assertNotDuplicate()`.
