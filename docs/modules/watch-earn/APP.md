# Watch & Earn — the apps (Android + iOS)

**For:** whoever builds or changes the Watch & Earn screens in `androidApp/` and `iosApp/`.

- **Android is the reference.** iOS has the same screens, file names and behaviour, and the same API/DTO field names (rule in `AGENTS.md`). The twin files are listed in [docs/modules/ios/README.md](../ios/README.md).
- **API:** [API.md](API.md). Decisions: [docs/watch_earn_tasks.md](../../watch_earn_tasks.md).
- **No polling.** Load once when a screen opens. Live updates come from realtime events (Pusher, same client as the chat) and Push (OneSignal). Durations (image time, interaction window) run on the device clock.
- **Customer-facing Arabic is Modern Standard Arabic.** Strings live in the Android XML (`values/` + `values-ar/`) with the prefix `we_`. iOS gets them through `iosApp/scripts/sync_strings.py`; never edit `Localizable.strings` by hand.
- **Builds (Gradle / Xcode) only when the owner asks.**

## Files

| Android `ui/screens/watchearn/` | iOS `UI/Screens/WatchEarn/` | What |
|---|---|---|
| `network/WatchEarnApi.kt` | `Network/WatchEarnApi.swift` | Every endpoint in API.md (mobile) + DTOs. The wallet's `X-Country` header like `WalletApi`. |
| `WatchEarnHost.kt` | `WatchEarnHost.swift` | The section's page stack (routes below), the deep links from push. |
| `WatchEarnHome.kt` | `WatchEarnHome.swift` | The landing page. |
| `WatchEarnPlayer.kt` | `WatchEarnPlayer.swift` | The player, like statuses (built on the story viewer's look). |
| `WatchEarnComments.kt` | `WatchEarnComments.swift` | The comments sheet, the reactions, the report sheets. |
| `WatchEarnRewards.kt` | `WatchEarnRewards.swift` | «مكافآتي». |
| `AdWizard.kt` | `AdWizard.swift` | Creating an ad, 5 steps. Editing reuses it. |
| `MyAds.kt` | `MyAds.swift` | «إعلاناتي». |
| `AdStats.kt` | `AdStats.swift` | One ad's stats page and its actions. |
| `AdWallet.kt` | `AdWallet.swift` | The ad wallet, fund, card top-up, return. |
| `WatchEarnAdPage.kt` | `WatchEarnAdPage.swift` | One ad for a viewer who already saw it (from a push). |
| `WeTheme.kt` | `WeTheme.swift` | Colours, cards, money formatting (minor units + `currency.decimals`), chips. |

## Entry points (D17)

Each one opens `WatchEarnHost` on `Home`:
- **Wallet home:** a card «شاهد واكسب» under the balance.
- **Chat list:** a slim card at the top, which the user can hide; it comes back from the chat settings (S01).
- **Home screen:** a banner card.
- **The «حسابي» tab:** a row above «البيانات الشخصية».

Push data `type = watch_earn` opens:
- `event = watch_earn.ad.*` → that ad's `AdStats`;
- `watch_earn.rewards` → `Rewards`;
- `watch_earn.comment_report_*`, by `role`:
  - `reporter` → that ad's `AdStats`;
  - `author` → `WatchEarnAdPage(ad_id)`, which shows the ad and its comments.

## Pages

### Home (`WatchEarnHome`)
- **Header:** «شاهد واكسب», and my rewards balance (the wallet's `reward_minor` + waiting).
- **Big «ابدأ المشاهدة» button:** shows how many ads are waiting (`feed.ads.size`) and what each earns. Hidden with an empty state when `enabled = false` or there are no ads.
- **Three tiles:** «مكافآتي», «إعلاناتي», «محفظة الإعلانات».
- **A «أنشئ إعلانًا» call to action** → `AdWizard`.

### Player (`WatchEarnPlayer`, D5/D6)
- **Full screen, statuses-style.** Progress bars at the top (one per ad in the feed). Advertiser name/avatar, ✕ and ⋮ (report). Title and text at the bottom, and a link button when there is one.
- **On open of each ad:** `POST ads/{id}/start`.
  - **Image:** counts `media.seconds` on the device clock.
  - **Video:** plays to the end. Sound on, but muted until the user taps 🔈. Never autoplay with sound in the chat.
- **Holding** pauses: the bar, the video and the count. **Opening comments** pauses too.
- **At the end:**
  1. `POST views/{view_id}/complete`.
  2. A small «+ 1.50 ر.س» reward toast (`earn.view_minor`).
  3. **Only then**, the interaction bar slides up: the chat's quick reactions plus 💬 «اكتب تعليقًا». A countdown from `interaction_deadline_at` shows how long the interaction reward is open. The first comment or reaction shows «+ مكافأة التفاعل».
- **`player.mode = auto`:** a few seconds after the end (unless comments are open or the user is holding), it moves to the next ad.
- **`player.mode = manual`:** it closes the ad view at the end (same exceptions). A tap / swipe goes to the next.
- **Errors:** `too_fast` → resume. `ad_unavailable` / `not_eligible` → skip to the next.
- **When the feed is done:** a summary card with what was earned in this session.

### Comments (`WatchEarnComments`)
- **Bottom sheet:** the list (`GET comments`, cursor), the reaction counts, my reaction, the composer.
- **Live:** subscribe to `private-watch-earn.ad.{id}` while open:
  - `watch_earn.comment` → add it at the top;
  - `watch_earn.comment_deleted` → remove it;
  - `watch_earn.reactions` → update the counts.
- **Actions:** long-press on my own comment → delete. On someone else's: ⋮ → report the ad (viewer). The advertiser reports comments from `AdStats`, not here.

### Rewards (`WatchEarnRewards`)
- **Two cards:** waiting and paid. The list of items: kind (view / interaction), the ad's title, the amount, «متاحة في …» or «أُضيفت».
- A note: rewards are spent anywhere in the app. If the country allows it, they can be sent to others too.

### Create an ad (`AdWizard`, D16)
A pager with a step indicator and animated transitions. «التالي» is enabled only when the step is valid. Values come from `GET advertiser/options`.

1. **File:** pick an image or a video.
   - Preview it in a phone frame.
   - Check the type, the size (`video_max_mb`) and the length (`video_max_seconds`) on the device first.
   - Send `media_seconds` (the measured length).
2. **Text:** title (2–120), text (optional), link (optional, https).
3. **Audience:**
   - **Cities:** a searchable multi-select from `options.cities`. Each choice is an animated chip; none = the whole country.
   - **Gender:** all / men / women.
   - **Language:** all / ar / en.
   - **Live «يصل إلى نحو N شخص»:** `POST advertiser/audience`, debounced on change.
4. **Budget and dates:**
   - **Budget:** amount, with «تكفي لنحو N مشاهدة» (budget ÷ `price_per_view_minor`). It shows the free ad wallet balance.
   - **If the free balance is short** (< max(budget, `min_ad_wallet_minor`)): a button «اشحن محفظة الإعلانات» that opens `AdWallet` fund and comes back.
   - **Dates:** start (today+) and end.
5. **Review:** the ad as the viewer will see it, a summary, then «أرسل للمراجعة» (multipart `POST advertiser/ads`). Success → a check animation, then «إعلاناتي».

**Editing:** `POST advertiser/ads/{id}`. The same steps 1–3 only, with a note that it goes back to the review.

### My ads (`MyAds`)
- Filter chips by status. Each card shows a thumbnail, title, status badge, a progress bar (spent / budget), completed views and the dates.
- A rejected ad shows its reason and «عدّل وأعد الإرسال».
- **Live:** `watch_earn.ad.changed` on my own channel → reload the list once.

### Stats (`AdStats`, D15)
- **Header:** the media, title, status, and actions by status:
  - **active:** «إيقاف مؤقت», «زيادة الميزانية», «تمديد», «إيقاف نهائي» (with confirmation).
  - **paused:** «استئناف».
  - **exhausted:** «زيادة الميزانية».
  - **pending / rejected:** «تعديل».
- **Number cards,** counting up when they appear: opens, completed, completion %, interactions, interaction %, comments, reactions.
- **Money:**
  - a ring of spent vs remaining;
  - price per view, views left, cost per interaction, daily average;
  - the remaining budget by bucket (reward / not withdrawable / withdrawable).
- **Charts:** completed and interactions per day (line or bars), by city (bars), gender and platform (donuts), hour of day (24 bars), reactions (emoji + count).
- **Comments tab:** the list. On each comment, ⋮ → «إبلاغ عن التعليق» (reasons: offensive, spam, misleading, harassment, other + note) → `POST advertiser/ads/{id}/comments/{c}/report`.
- **Live:** `watch_earn.ad.changed` → reload once. No timer.
- **Export:** a ⤓ button offers PDF and Excel. It downloads `advertiser/ads/{id}/export?format=…` (with auth) to the cache, then opens the system share sheet (Android `FileProvider` + `ACTION_SEND`; iOS `ShareLink` / `UIActivityViewController`).
- **The per-day chart follows the reading direction:** in Arabic, the first day is on the right, matching its date labels.

### One ad (`WatchEarnAdPage`)
- `GET ads/{id}`: the media (image or video, no timer, no session), the title, the text, and the comments list with the same live channel as the sheet.
- Reacting and commenting work, but earn nothing unless `can_interact` is true.

### Ad wallet (`AdWallet`, D12–D14)
- **Big total.** Then three rows: «متاح» (free), «محجوز في الإعلانات» (locked). Each is split by bucket with the same three colours everywhere: المكافآت / غير قابل للسحب / قابل للسحب.
- **The ads holding money:** title + status + locked amount (tap → `AdStats`).
- **«تحويل من المحفظة»:**
  1. Amount.
  2. `fund/preview` shows what each bucket gives, against the main wallet's balance by bucket (`main_wallet`).
  3. The wallet PIN (the wallet's PIN sheet, `X-Wallet-Pin`).
  4. `fund` with an `idempotency_key` (a UUID made once per attempt).
- **«اشحن بالبطاقة»:** amount → `POST wallet/checkouts {purpose: watch_earn_ad_wallet, reference: {amount_minor}}` → the app's existing payment screen (the same launcher the other paid things use). When it comes back paid, reload the ad wallet.
- **«إرجاع إلى المحفظة»:** amount (max = free), `return/preview` shows what goes back to each bucket, confirm → `return`. A note says the locked money comes back only when its ad stops or ends.
- **The statement:** `entries` with type icons (fund, topup, return, reserve, release), the bucket split, «الرصيد المتاح بعدها».

## The main wallet (WE-010 / WE-016)

- **`GET mobile/v1/wallet`** (the balance, `WalletApi.balance`) now also returns:
  - `reward_minor`: the rewards balance, already counted in `total_minor`;
  - `rewards_transferable`: whether this country lets rewards be sent to others;
  - `currency_decimals`.
- **`WalletHome`:** shows `reward_minor` as a third balance «رصيد المكافآت» with the same colour as above.
- **The transfer screen:** offers rewards as a source only when the country allows it. If the server answers `rewards_not_transferable`, show its message.
- **Statement types:** `ad_funding` and `ad_return` get their own icons; `reward` with `watch_reward` notes reads «مكافأة شاهد واكسب».
