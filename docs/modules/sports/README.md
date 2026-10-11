# Sports Module (`Modules/Sports`)

DORR Sports (spec 183–200). The plan, decisions and request budget are in [../../sports-plan.md](../../sports-plan.md), and the endpoints are in [API.md](API.md).

## How data flows
- **Provider:** API-Sports, with one API per sport and **one key and one daily budget for all of them** (`API_SPORTS_KEY`, `API_SPORTS_DAILY_LIMIT`). Only the server calls it (`ApiSportsClient`). Every request is counted in `sports_api_usage` and refused once the day is spent. The AI never guesses live data (200).
- **Catalog (184–188):** `sports:import {sport}` brings a sport's current competitions (one request). New ones start `off`, except the suggested list in `config/config.php`, which starts `big`. The admin sets each competition's tier, scope, priority and names.
- **Schedule (190):** one `fixtures?date=` / `games?date=` request per sport per day covers every competition. Today and tomorrow are refreshed every 3 hours; the next 5 days once a day. Postponements and time changes are caught by these refreshes. This uses the reserve.
- **Live (191):** `sports:tick` runs every 30 s and costs nothing outside match windows.
  - Inside a window it makes one live request per tier: big every 60 s, normal 180 s, minor 480 s (the admin can change these).
  - A match with a followed team goes one tier up; a competition nobody watches goes one tier down.
  - Details (events, statistics, line-ups) are fetched only when the score or status changed, for big-tier statistics every few minutes, and for line-ups an hour before kick-off.
  - A match that left the live answer gets one details call, so a finish is never missed.
- **Governor (`SportsGovernor`):** plans the rest of the day (window minutes ÷ interval, plus details) for each sport. When that's more than what's left after the reserve, it stretches the intervals just enough. Each sport keeps at least its guaranteed share.
- **Standings (192):** `sports:standings` runs 30 min after a competition's round ends, else daily (minor: weekly). Top scorers update daily for big and normal competitions. At most `sports.standings_per_run` (6) requests per hourly run, big tiers first; a season the provider's plan refuses (`"plan"` error, e.g. the free plan's current season) isn't asked again for 7 days and the app shows "unavailable". The trend (up, down, same) is kept per row.
- **Details on open:** opening a match page reads its missing or old events, statistics and line-ups once (`SportsEngine::refreshOnOpen`), budget-checked.
- **Changes:** `SportsEngine::apply()` computes what changed: kickoff, goal (from the score, then `goal_detail` with the scorer), red card, half time, resumed, finished, postponed, cancelled, suspended, time changed, line-ups. Every change bumps the match `version` and is broadcast at once on Pusher public channels (`sports.match.{uuid}`, `sports.live`). It is also announced as the `MatchChanged` event.

## People
- **Follows (189):** any number of teams, national teams and competitions, in any sport. Each follow has its own alerts: reminder, kickoff, goal, red_card, half_time, finished, schedule, lineups. It also has its own "no spoilers".
- **Alerts (193):** `SportsNotifier` (the `MatchChanged` listener) sends them.
  - In the app at once, on the account's private channel (`sports.alert`, with `celebrate` when it's my team's goal or win). Also as a OneSignal push.
  - Never twice: `sports_notification_log` keeps one per person, match and key.
  - Quiet hours hold the push; goals can be let through if the person chose that. The reminder comes from `sports:reminders`.
- **No spoilers (194):** no score in alerts or on screen (the app shows "Show"), and no celebration.
- **Celebrations (197, 198):** off, calm, normal or festive, with the synthesised sounds in the app's `res/raw/sp_*.wav`. They never play when the phone is silent.

## Predictions and contests (196, 199)
- **Predictions:** free, one per person per match, locked at kick-off. Settled from the official result: exact score = 3 points, the right winner = 1. A cancelled or postponed match voids them. The crowd's split is shown.
- **Contests:** the admin's contests on a match, a round or a competition, in chosen countries.
  - **Rule:** exact score, the right winner, or most points.
  - **Prize:**
    - `wallet`: `spend_only` credit, `WalletTransactionType::Reward`, booked as `prize_cost`.
    - `coupon`: from `Modules\Wallet\Services\CouponService`, spent on Checkout.
    - `badge`.
  - **Sharing:** each winner, split the pool, or first N, with a budget cap per country and a fair draw seeded by the contest (the seed is stored).
  - **Eligibility:** a verified phone, a minimum account age, and one winner per device (wallet device ids and push ids).
  - **Review:** above `review_above_minor`, or without auto-pay, a prize waits for the admin.
  - **Settling:** single-match contests settle when their match ends (the `SettlePredictions` listener). The others settle through `sports:contests`.
- **Legal:** prizes are paid only in countries listed in `sports_settings.prizes_countries`. The default is nowhere, until the legal review (in KSA, prize competitions need a Ministry of Commerce permit). Badges are allowed everywhere.
- **Rating (199):** fun and excitement (1–5) after the final whistle, shown as "DORR community", not as an official rating.

## Chat and calendar
- **Chat:** the `match_card` message type (`meta.match`, a snapshot; the app shows the live score), match rooms (a group with the card first, 195), and sharing a match with a "who wins?" poll.
- **Calendar:** the `sports` source in DORR Calendar shows the matches of the teams I follow.

## Layout
| Path | What |
|---|---|
| `app/Support/ApiSportsClient.php` | Requests, counting, the daily budget |
| `app/Data/FootballAdapter.php` · `GamesAdapter.php` | API-Football v3 and the v1 "games" sports, mapped to one shape (`SportAdapter`) |
| `app/Services/SportsEngine.php` | Import, schedule, the live loop, applying changes, standings |
| `app/Services/SportsGovernor.php` | Planning the day's requests, stretching intervals |
| `app/Services/SportsNotifier.php` · `SportsFollowService.php` · `SportsFollowIndex.php` | Alerts, follows and preferences, what's followed |
| `app/Services/PredictionService.php` · `ContestService.php` | Predictions, contests, winners, prizes |
| `app/Services/SportsChatService.php` · `SportsPresenter.php` | Match card, rooms, sharing · how things look in the API |
| `app/Services/SportsLibrary.php` · `FootballViews.php` | Everything besides live scores, kept in `sports_cache` with the provider's own freshness (squads, team and player statistics, coaches, transfers, trophies, injuries, predictions, head to head, leaders, rounds, odds) · mapped for the app on the way out |
| `app/Support/SportsMedia.php` · `Http/Controllers/SportsMediaController.php` | Logos and photos from our copy (`storage/app/sports-media`) |
| `app/Http/Controllers/SportsLibraryController.php` | The deep pages: rounds, leaders, squad, statistics, transfers, players, coaches, insights, search |
| `app/Console` | `sports:check`, `sports:import`, `sports:tick`, `sports:schedule`, `sports:standings`, `sports:seasons`, `sports:reminders`, `sports:contests`, `sports:plan-changed` |
| `database/migrations/2026_10_14_100000` | `sports_cache`, venue ids, event player ids, match ratings, home/away tables, team venue/coach/captain, `odds_countries` |
| `database/migrations/2026_10_13_100000/100100/100400` | Engine · follows and alerts · predictions, contests, ratings, rooms |

**Admin (Vue):** `views/sports/` holds competitions (tiers, import), contests (and winners' review), requests and budget, and settings. Permissions are `sports-settings`, `sports-competitions`, `sports-usage` and `sports-contests`.

**Android:** `network/SportsApi.kt` and `ui/screens/sports/`:
- `SportsStore`: live state, sounds, the minute.
- `SportsParts`: crest, flip score, rows, cards, the Home card.
- `SportsScreen`: home, competition, team, follow, settings.
- `SportsMatch`: hero, timeline, stats, line-ups on a pitch, table.
- `SportsPlay`: predictions, contests, prizes, share, room, the chat card.
- `SportsCelebration`: the goal moment, the win, banners.

The coupon box is on the payment screen (`WalletCheckout.kt`).

**Tests:** `SportsTest` (6), `SportsPredictionsTest` (5), `SportsMoreSportsTest` (3).

## Other sports (183)
- **Formula 1 (`RacesAdapter`):**
  - One championship competition. Each race (and sprint) is a team-less match: the Grand Prix is its `round` (and `title`), laps are its minute, and the classification is in `scores.results`.
  - While a race is live, each poll also fetches the order (one more request). The final order comes from `details`.
  - Standings are the drivers' championship. Following the championship brings its races, in alerts and in the calendar.
- **MMA (`FightsAdapter`):**
  - The fighters are home and away, the event is the `round`, and `scores.fight` holds the weight class, main event, how it was won, the round and the time. One extra request a day brings the results when fights are over.
  - Built from the provider's documented shape: no fights fell in the free plan's dates when it was tried (2026-10-07).
- **Games (basketball, volleyball, handball, hockey, rugby, baseball, `GamesAdapter`):** the score per period. The app shows a periods table.
- **Free plan (tried 2026-10-07):** each sport's API has its own 100 a day. F1 only has seasons 2022–2024, MMA only dates around today. The paid plan covers the current seasons.

## Quiet hours (193)
- A push held by quiet hours is recorded in `sports_held_alerts` (once per person and match).
- When the quiet hours end, `sports:reminders` sends one summary: each match's score as it is now, with no score when spoilers are hidden.
- Migration: `2026_10_13_100500`.

## The deep pages (phase 6, docs/sports-plan.md §10)
- **Freshness:** `config('sports.ttl')` — from the provider's "Recommended Calls": profiles, squads, coaches, transfers, trophies a week; player and team statistics, leaders, rounds, head to head a day (team statistics 3 days when it doesn't play); predictions 6 h; injuries 4 h; odds 3 h; finished matches' extras 30 days.
- **Who pays:** the first person to open something pays one request; everyone after reads our copy. Nothing on-demand spends the reserve (`SportsGovernor::canSpend()`), and the same answer is never asked twice within a minute (a cache lock).
- **Plan refusals** (`errors.plan`, e.g. the free plan's current season, `ids`, `last`) are remembered 7 days per answer. After upgrading the plan, `php artisan sports:plan-changed` forgets them all at once (no request), and tables, leaders and seasons become due on the next hourly runs. On the free plan, details use `fixtures?id` one by one (`FootballAdapter::detailsBatch()`).
- **Seasons:** `sports:seasons` (hourly, 4 at a time) reads each active competition's whole season once a day (minor: weekly); opening a competition or a team reads it if due.
- **Poster:** venue photo, both coaches, both captains (`games.captain` on the match sheet; the last match's until the line-ups), the referee (a name only).
- **Odds:** information only, off everywhere until the admin picks countries (`odds_countries`).
- **Widgets:** in-app pieces (`SportsWidgets.kt`) and two home-screen widgets (Jetpack Glance, `widget/SportsWidgets.kt`): "My team" and "Live now", from `GET widget` every 15 minutes, after a sports push and when Sports opens.
