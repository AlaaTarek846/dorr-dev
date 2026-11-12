# Watch & Earn — API

**Readers:** the mobile apps and the admin dashboard.

- **Code:** `Modules/WatchEarn`. Tasks are in [docs/watch_earn_tasks.md](../../watch_earn_tasks.md).
- **Envelope:** `ApiResponse`.
- **Errors:** `watch_earn_<code>`; the messages are in `lang/*/watch_earn.php`.
- **Money:** always in minor units (`*_minor`). A response that shows money includes `currency: {code, decimals}`.
- **Country:** every mobile route needs one, given by `X-Country` or resolved by the `country` middleware.

## Rules the server enforces

- **Price split.** The price of a view is set per country, and the price of each ad is fixed when it is sent:

  ```
  price_per_view = platform_share + view_reward + interaction_reward
  ```

- **Charging a view.** A completed view is charged its full price from the ad's reserved budget:
  - the platform share is booked to `watch_earn_revenue`;
  - the view reward goes to the viewer;
  - the interaction share is **held** on the view.
- **Interaction share.**
  - If the viewer comments or reacts inside the `interaction_window_minutes` window, the held share becomes their reward.
  - If the window closes with no interaction, `unclaimed_interaction` decides where the share goes: `platform` (it is booked as revenue) or `not_charged` (it goes back to the ad's budget).
- **Buckets.** Every amount remembers which wallet bucket it came from (`reward`, `spend_only`, `withdrawable`).
  - Funding the ad wallet and charging an ad take money that cannot be withdrawn first, in the order reward → spend_only → withdrawable (decision D20). Every other payment in the app spends the wallet in the same order (`Wallet::SPEND_ORDER`).
  - A return gives back withdrawable money first, and each part goes back to the bucket it came from.
  - Money topped up directly into the ad wallet counts as `withdrawable`.
- **Locking.** An ad's reserved budget is locked while the ad is `pending_review`, `active`, `paused`, `exhausted` or `suspended`. The free balance can be returned at any time.
- **Reward bucket.** The wallet's `reward` bucket is never withdrawn, and is spent anywhere in the app. Whether it can be sent to another user is set per country by `wallet_settings.rewards_transferable` (off by default). When it is off, a transfer with `from_bucket=reward` fails with `rewards_not_transferable` (422).

## Mobile — `/api/mobile/v1/watch-earn` (`auth:user_api`)

### Viewer

| Method | Path | Notes |
|---|---|---|
| GET | `feed` | Returns `{enabled, player: {mode: auto\|manual, image_seconds, interaction_window_minutes}, ads: [FeedAd]}`. Ads that target the viewer's city come first, then ads for the whole country. Calling it also refreshes the viewer's location from their IP (D10). |
| GET | `ads/{ad}` | One ad the viewer already watched or commented on, opened from a push. Returns `{ad: FeedAd, watched, can_interact, interaction_deadline_at}`. It starts no session and pays no reward. Anyone else gets `not_found` (404). |
| POST | `ads/{ad}/start` | Body: `{platform?}`. Returns `{view_id, started_at, seconds}`. Fails with `not_eligible` (403), or `already_watched` (409) if the viewer has already completed this ad. |
| POST | `views/{view}/complete` | Fails with `too_fast` (422) if less than `seconds` has passed since `start`, or `ad_unavailable` (409). Calling it again is safe. Returns `{interaction_deadline_at, can_interact}`. |
| GET | `ads/{ad}/comments` | Returns `{comments, next_cursor, reactions: {emoji: n}, my_reaction}`. |
| POST | `ads/{ad}/comments` | Body: `{body}`. Only for a viewer who watched the ad to the end (`watch_first`). The first comment or reaction inside the window earns the interaction reward. |
| DELETE | `ads/{ad}/comments/{id}` | Deletes the viewer's own comment. |
| POST / DELETE | `ads/{ad}/reaction` | Body: `{emoji}`. One reaction per viewer; changing it earns nothing more. |
| POST | `ads/{ad}/report` | Body: `{reason: misleading\|offensive\|adult\|violence\|scam\|other, note?}`. One report per viewer. When `report_threshold` is reached, the ad is suspended. |
| GET | `rewards` | Returns `{waiting_minor, paid_minor, items, next_cursor}`. |

**Realtime:** a viewer with an ad open subscribes to `private-watch-earn.ad.{uuid}`, which carries these events:
- `watch_earn.comment`: a new comment, `{ad_id, comment}`.
- `watch_earn.comment_deleted`: a comment was removed, `{ad_id, comment_id}`.
- `watch_earn.reactions`: the reaction counts changed, `{ad_id, reactions}`.

### Advertiser

| Method | Path | Notes |
|---|---|---|
| GET | `advertiser/options` | Everything the wizard needs: `enabled`, country, currency, `price_per_view_minor`, `min_ad_wallet_minor`, `free_minor`, the media limits (including `image_max_mb`), `cities` (`[{id, name}]` from `discover_cities`), genders, locales. |
| POST | `advertiser/audience` | Body: `{cities?, gender?, locale?}`. Returns `{people}`, the estimated audience size. |
| GET | `advertiser/ads?status=` | The advertiser's ads, paginated. |
| GET | `advertiser/ads/{ad}/export?format=pdf\|xlsx` | The stats as a file (`Content-Disposition: attachment`). Counts and money only; no viewers, no price split. |
| POST | `advertiser/ads` | Multipart: `media`, `media_seconds?`, `title`, `body?`, `link_url?`, `cities[]?`, `target_gender?`, `target_locale?`, `starts_at`, `ends_at`, `budget_minor`. Reserves the budget and creates the ad as `pending_review`. Possible errors: `insufficient_ad_wallet`, `budget_too_small`, `bad_dates`, `bad_cities`, `bad_file`, `file_too_big`, `video_too_long`, `video_length_unknown`. An image is at most `media.image_max_mb` (10 MB). A closed ad answers `closed` (409) to any change. |
| GET | `advertiser/ads/{ad}` | Returns an `AdResource`. |
| GET | `advertiser/ads/{ad}/stats` | Returns `{ad, stats}`. `stats` contains `totals`, `money`, `daily[]`, `by_city[]`, `by_gender`, `by_platform`, `by_hour[24]` and `reactions`. It never identifies viewers. |
| POST | `advertiser/ads/{ad}` | Multipart content edit. The ad goes back to review. Who already saw the ad is kept, and a new file increments `media.version`. |
| POST | `advertiser/ads/{ad}/budget` | Body: `{amount_minor}`. Reserves more budget from the free balance. An `exhausted` ad becomes `active` again. |
| POST | `advertiser/ads/{ad}/extend` | Body: `{ends_at}`. Must be later than the current end date. |
| POST | `advertiser/ads/{ad}/pause`, `resume`, `stop` | `stop` is permanent; the rest of the budget goes back to the free balance. |
| POST | `advertiser/ads/{ad}/comments/{comment}/report` | Body: `{reason: offensive\|spam\|misleading\|harassment\|other, note?}`. The advertiser can't delete comments on their ad, only report them (D21). One report per comment. |

**Realtime and push:**
- The advertiser's own channel receives `watch_earn.ad.changed` with `{ad_id, status, completed, interactions, spent_minor}`. On it, the stats page reloads once.
- Push notifications are sent for: approved, rejected, suspended, `low_balance` (sent once), exhausted, ended.

### Ad wallet

| Method | Path | Notes |
|---|---|---|
| POST | `/api/mobile/v1/wallet/checkouts` | **Topping up with a card (WE-013):** `{purpose: "watch_earn_ad_wallet", reference: {amount_minor}}`, then the normal payment screen (coupon / wallet / gateway).<br>- Paid by a gateway: the money arrives in the ad wallet as `withdrawable`.<br>- Paid from the wallet balance: it moves like a fund.<br>- The ad wallet statement shows it as `topup` or `fund`. |
| GET | `ad-wallet` | Returns:<br>- `free`, `locked` (each by bucket) and `total_minor`;<br>- `ads`: what each ad holds;<br>- `main_wallet`: the main wallet's available balance by bucket;<br>- `entries` and `next_cursor`: the ad wallet's statement. |
| POST | `ad-wallet/fund/preview` | Body: `{amount_minor}`. Returns `{take: by bucket}`. |
| POST | `ad-wallet/fund` | Body: `{amount_minor, idempotency_key}`. Needs the `X-Wallet-Pin` header. |
| POST | `ad-wallet/return/preview` | Body: `{amount_minor}`. Returns `{give: by bucket}`. |
| POST | `ad-wallet/return` | Body: `{amount_minor, idempotency_key}`. Only the free balance can be returned. |

## Admin — `/api/admin/v1/watch-earn` (`auth:admin_api`)

The admin endpoints need these permissions:
- `watch-earn-settings.view` and `watch-earn-settings.update`;
- `watch-earn-ads.view` and `watch-earn-ads.update`;
- `watch-earn-finance.view`.

| Method | Path | Notes |
|---|---|---|
| GET | `settings` | Every active country, with its own settings or the defaults. |
| PUT | `settings/{country}` | All the fields of `we_settings`. The split must add up to the price (`split_mismatch`). |
| GET | `ads?status=&country_id=&search=&reported=` | Ads waiting for review come first. Includes the price split and the advertiser. |
| GET | `ads/counts` | Returns `{by_status, reported}`. |
| GET | `ads/{ad}` | For the details modal: `{ad, stats, reports}`. |
| GET | `ads/{ad}/reports` | The ad's reports. |
| POST | `ads/{ad}/approve` | Approves the ad. |
| POST | `ads/{ad}/reject` | Body: `{reason}`. Rejects the ad and frees its budget. |
| POST | `ads/{ad}/suspend` | Body: `{reason?}`. Suspends the ad. |
| POST | `ads/{ad}/unsuspend` | Restores a suspended ad. Its end date moves later by how long it was suspended. |
| GET | `comment-reports?status=pending\|removed\|kept` | Advertisers' reports on comments. Needs `watch-earn-ads.view`. |
| POST | `comment-reports/{id}/decide` | Body: `{action: removed\|kept, note?}`. Needs `watch-earn-ads.update`. `removed` deletes the comment for everyone. The advertiser and the comment's author both get a push with the decision and the note. Push data: `{type: watch_earn, event: watch_earn.comment_report_<decision>_<role>, ad_id, comment_id, decision, role: reporter\|author}`. |
| GET | `finance/export?format=pdf\|xlsx&country_id=&from=&to=` | The same numbers as a file. |
| GET | `finance?country_id=&from=&to=` | Platform revenue, total spent, rewards paid and waiting, ad wallets' free balance, budget locked in ads, and completed views. |

## Scheduler

`watch-earn:tick` runs every 5 minutes. It:
- closes ads whose end date has passed (their budget is freed);
- settles interaction windows that have closed;
- pays out rewards whose waiting time is over, with one push per viewer.
