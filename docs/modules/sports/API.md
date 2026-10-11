# Sports API

The standard envelope applies. Errors have `error_code` set to `sports_<code>`; coupon errors use `coupon_<code>`. `timezone` (IANA) is where I am now. The app never calls the provider.

## App: `mobile/v1/sports/*`
Middleware: `auth:user_api`, `ensure-phone-verified:user_api`, `country`, `locale`. When Sports is off in my country, the answer is `403 sports_off`.

| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `home` | `date?`, `sport?`, `timezone` | `{date, sports[], live_count, mine: Match[], competitions[{competition, followed, matches[]}], following{teams[], competitions[]}, preferences}` |
| GET | `matches` | `date?`, `sport?`, `competition_id?`, `team_id?`, `live=1?`, `mine=1?` | `Match[]` |
| GET | `matches/{uuid}` | `timezone` | Match plus `venue`, `city`, `referee`, `status_label`, `events[]`, `statistics[{type, home, away}]`, `lineups{home, away}`, `coverage`, `following{home, away}`, `channel`. Opening it reads missing or old details from the provider once (live: older than 2 min; finished: not read since the whistle; within an hour of kick-off: line-ups) — one request, at most every 90 s per match, never from the budget reserve |
| GET | `competitions` | `sport?`, `q?` | Active competitions |
| GET | `competitions/{id}` | `timezone` | `standings[{group, rows[{rank, team, points, played, win, draw, lose, goals_for, goals_against, goal_diff, form, description, trend}]}]`, `standings_updated_at`, `standings_state`, `top_scorers[]`, `scorers_state` (`ok` · `pending` · `unavailable` = the provider's plan has none for this season), `teams[{id, name, logo, color, country, following}]` (the table's, else every team in its matches), `live`, `next`, `last`, `following` |
| GET | `teams` | `q` (≥ 2 characters), `sport?` | Teams to follow |
| GET | `teams/{id}` | `timezone` | Team plus `country`, `founded`, `venue{id, name, address, city, capacity, surface, image}`, `coach{id, name, photo, nationality, age}`, `form[{id, result: W \| D \| L, score}]` (last 5), `tables[{competition, group, rank, points, played, goal_diff, form, description}]`, `live`, `next` (30), `last` (20) — every competition the team plays, `follow{id, alerts, no_spoilers}`. Opening it reads the team's profile and coach (once a week) and its season (once a day), budget allowing |
| GET / POST | `follows` | POST `{kind: team \| competition, target_id, alerts?{reminder, kickoff, goal, red_card, half_time, finished, schedule, lineups}, no_spoilers?}` (adds or updates) | `[{id, kind, sport, target, alerts, no_spoilers}]`. At most 60 |
| DELETE | `follows/{id}` | — | The list |
| GET / PUT | `preferences` | `{no_spoilers?, celebration?: off \| calm \| normal \| festive, sounds?, vibrate?, reminder_minutes?: 0 \| 5 \| 10 \| 15 \| 30 \| 60, goals_in_quiet?}` | The same |
| GET | `matches/{uuid}/play` | — | `{enabled, locked, mine{winner, home_score, away_score, points, exact, correct_winner, result}, crowd{count, home, draw, away, top_score}, contests[], rating{count, fun, excitement, mine}}` |
| POST | `matches/{uuid}/prediction` | `{home_score, away_score}` or `{winner}` | The same as `play`. `422 sports_prediction_locked` after kick-off |
| POST | `matches/{uuid}/rating` | `{fun, excitement}` (1–5) | The same as `play`. Only after the final whistle |
| POST | `matches/{uuid}/share` | `{conversation_id, poll?}` | `201 {message_id, poll_id?, conversation_id}` (a `match_card`, plus a "who wins?" poll before kick-off) |
| POST | `matches/{uuid}/room` | `{members: user ids[]}` | `201 {conversation, not_added}` |
| GET | `contests` | — | Open contests in my country: `[{id, name, terms, scope, rule, status, match_id, match, competition, round, ends_at, prize_type, prize_amount_minor, prize_percent, currency_code, distribution, max_winners, prizes_here, my_prize}]` |
| GET | `contests/{uuid}` | `timezone` | The same, plus `leaderboard[{rank, name, points, exact, me}]`, `my_points`, `matches[]` |
| GET | `prizes` | — | `[{contest_id, name, prize_type, amount_minor, currency_code, status, points, paid_at}]` |
| GET | `widget` | `timezone` | The home-screen widgets: `{mine: Match[]` (my teams' live, else next, two weeks ahead, 3 at most)`, live: Match[]` (biggest live, 6)`, updated_at}` |

### The deep pages (docs/sports-plan.md §10, football)
Each part comes from `sports_cache` (`SportsLibrary`) and says its **`state`**: `ok` · `pending` (not read yet: the day's budget or a provider hiccup) · `unavailable` (the provider's plan doesn't cover it — not asked again for 7 days). `{state, fetched_at, data}` is called a **Part** below. Provider ids are used for players and coaches (we don't keep them); every team the provider names becomes one of ours, so it opens.

| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `competitions/{id}/rounds` | `round?`, `timezone` | `{rounds[{name, dates[]}], current, round, matches: Match[]}` — opening it reads the whole season once a day (one request) |
| GET | `competitions/{id}/leaders` | `type`: goals · assists · yellow · red | `{type, state, fetched_at, rows[{rank, player{id, name, photo, nationality, age}, team, value, goals, assists, penalties, appearances, minutes, rating}]}` (20, kept a day) |
| GET | `teams/{id}/squad` | — | Part of `[{position, players[{id, name, age, number, position, photo}]}]` (goalkeepers first, kept a week) |
| GET | `teams/{id}/statistics` | `competition?` (ours; default the league) | Part of `{competition, form, fixtures, goals{for, against: {total, average, minute[{range, total, percent}], under_over}}, biggest, clean_sheet, failed_to_score, penalty, lineups[{formation, played}], cards{yellow[], red[]}}` plus `competition`, `competitions[]` (kept a day when it plays today, else 3 days) |
| GET | `teams/{id}/transfers` | — | Part of `[{date, type, player, direction: in \| out, from, to}]` (newest first, 60) |
| GET | `players/{providerId}` | `season?` | `{id, name, firstname, lastname, age, birth, nationality, height, weight, number, position, injured, photo, team, seasons[{team, competition, appearances, lineups, minutes, rating, captain, goals, assists, saves, conceded, shots, shots_on, passes, key_passes, pass_accuracy, tackles, interceptions, duels_won, duels, dribbles, dribbles_tried, fouls_drawn, fouls, yellow, red, penalties_scored, penalties_missed}], season, stats_state}`. `503 sports_pending` before the first read |
| GET | `players/{providerId}/career` | — | `{teams: Part<[{team, seasons[]}]>, trophies: Part<[{competition, country, season, place}]>, transfers: Part<[…]>, sidelined: Part<[{type, start, end}]>}` (kept a week) |
| GET | `coaches/{providerId}` | — | `{id, name, firstname, lastname, age, nationality, birth, photo, team, career[{team, start, end}], trophies: Part}`. `503 sports_pending` before the first read |
| GET | `matches/{uuid}/insights` | — | `{prediction: Part<{winner, winner_comment, win_or_draw, under_over, goals, advice, percent{home, draw, away}, comparison[{type, home, away}], home/away{form, att, def, goals_for, goals_against, league_form}}>, h2h: Part<{summary{home, draw, away, home_goals, away_goals, played}, matches[{date, competition, home, away, home_score, away_score}]}>, injuries: Part<[{side, player, type, reason}]>, odds: Part<{bookmaker, updated_at, bets[{key, name, values[{value, odd}]}]}> \| null, poster}`. **Odds are information only**, and `null` unless the admin turned them on for my country (`sports_settings.odds_countries`) |
| GET | `search` | `q` (≥ 2; players need 4) | `{competitions[], teams[], players[{id, name, photo, nationality, age, position}]}` — fewer than 5 teams of ours: the provider's are added (kept a day) |
| GET | `media/{path}` | — (no sign-in) | A logo or photo (`{sport}/{kind}/{id}.png`, `flags/{code}.svg`) from our copy in `storage/app/sports-media`; the first time it's copied from the provider (8 a second at most, else a redirect to it) |

The **match page** (`matches/{uuid}`) also has `lineups.{side}.start[]/subs[]` with `id`, `photo`, `rating`, `captain`, `goals`, `yellow`, `red`, and `coach_id`, `coach_photo`; `players{home[], away[]}` (the match sheet: minutes, rating, captain, goals, assists, shots, passes, tackles, duels, dribbles, cards…, by rating); `events[].player_id` / `assist_id`; and `poster{venue{name, city, image, capacity}, referee{name, country}, home/away{coach{id, name, photo}, captain{id, name, number, photo}}}` (the captain of the last match until this one's line-ups are out; the provider has no referee photos). Standing rows have `home` / `away{played, win, draw, lose, goals_for, goals_against}`. Logos and photos point at `media/…`.

**Match:** `id`, `sport`, `competition{id, name, logo, country, flag, tier}`, `round`, `home` / `away{id, name, code, logo, national, color}`, `starts_at` (UTC), `local_date`, `local_time`, `status` (scheduled · live · break · finished · postponed · cancelled · suspended), `status_code`, `minute`, `minute_extra`, `home_score`, `away_score`, `periods[{label, home, away}]`, `winner`, `version`, `synced_at`.

**Realtime (Pusher):**
- `sports.match.updated` on `sports.match.{uuid}` and `sports.live`: the compact Match plus `changes[{type, side, minute, scorer, player, own_goal, penalty}]`.
- On the account's private channel:
  - `sports.alert {match_id, type, alert, hidden, home_score, away_score, side, scorer, minute, celebrate}`.
  - `sports.prize {contest_id, prize_type, amount_minor, currency_code, title}`.

**Push data:** `{type: "sports", event: "sports.<change>", match_id}` and `{type: "sports", event: "sports.prize", contest_id}`.

## Wallet: coupons
| Method | Path | Notes |
|---|---|---|
| POST | `mobile/v1/wallet/checkouts/{uuid}/coupon` | `{code}`. The checkout then shows `discount_minor`, `payable_minor` and `coupon{code, discount_minor}`. Errors: `coupon_invalid`, `coupon_expired`, `coupon_used`, `coupon_not_here`, `coupon_min_amount` |
| DELETE | `mobile/v1/wallet/checkouts/{uuid}/coupon` | Removes the coupon |
| GET | `mobile/v1/wallet/coupons` | My usable coupons `[{code, kind, value, currency_code, expires_at, source}]` |

The coupon is spent when the checkout is paid, in the same transaction: `wallet_coupon_redemptions` records it, and revenue is the amount paid.

## Admin: `admin/v1/*`
| Method | Path | Permission | Notes |
|---|---|---|---|
| GET / PUT | `sports-settings` | `sports-settings.*` | `enabled`, `enabled_countries`, `tier_seconds{big, normal, minor}`, `reserve_percent`, `daily_limit`, `predictions_enabled`, `prizes_countries` (empty = prizes closed everywhere), `sports[{key, status, min_share_percent}]` |
| GET | `sports-competitions` | `.view` | `?sport=&tier=active\|big\|normal\|minor\|off&scope=&country=&search=`, plus `counts` and `countries` |
| PATCH | `sports-competitions/{id}` | `.update` | `{tier?, scope?, priority?, translations?[{locale, name}]}` |
| PATCH | `sports-competitions/bulk` | `.update` | `{ids[], tier}` |
| POST | `sports-competitions/import` | `.create` | `{sport, suggest?}` (one provider request) |
| GET | `sports-usage` | `sports-usage.view` | `limit`, `used`, `left`, `reserve`, `planned_rest_of_day`, `by_sport[{used, planned, factor, intervals, live_now, today}]`, `by_endpoint`, `last_days` |
| POST | `sports-usage/sync` | `sports-usage.update` | `{what: schedule \| standings \| tick}` |
| GET / POST | `sports-contests` | `sports-contests.view` / `.create` | Create as a draft: `{scope, match_id \| competition_id (+ round \| ends_at), countries[], rule, prize_type, prize_amounts{country_id: minor}, coupon{kind, value?, valid_days}, distribution, max_winners?, budget_minor?, min_account_days?, auto_pay?, review_above_minor?, translations[{locale, name, terms?}]}` |
| GET / PATCH | `sports-contests/{uuid}` | `.view` / `.update` | Shows the winners and the seed |
| POST | `sports-contests/{uuid}/open` · `/cancel` · `/settle` | `.update` | |
| PATCH | `sports-contests/{uuid}/winners/{id}` | `.update` | `{status: paid \| rejected, note?}` (the review) |
