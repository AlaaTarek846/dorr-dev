# Rides Module (`Modules/Rides`)

Passenger rides. The plan and the owner's decisions are in [../../rides-plan.md](../../rides-plan.md), the tables in [../../rides-structure.md](../../rides-structure.md). The drivers themselves live in [Modules/Driver](../driver/README.md).

## Status
**Phase 0 (2026-10-13):** the foundations below.
**Phase 1, backend core (2026-10-14):** an Economy ride end to end — quote, request, dispatch, accept, arrived, PIN, started, stops, end, wallet / cash payment, settlement, cancellations, no-show, ratings, driver earnings. Then card payment through the shared payment screen, and Wasl (2026-10-14). Still to come in phase 1: the admin screens and the two apps' ride screens.

## Feature switches (`RideFeatures`, `ride_feature_rules`)
- Same shape as the chat's rules (country, or one of its cities — `discover_cities.id`, no FK), but **no rule means off**, and nothing works unless `rides` itself is on there. A city rule beats the country rule.
- Features: `rides`, `category.{slug}`, `bidding`, `multi_stop`, `open_destination`, `scheduled`, `book_for_other`, `cash`, `wallet`, `card`, `tips`, `pin`, `share`, `change_driver`, `driver_points`, `one_touch`.
- `EnsureRideFeature::class.':feature'` refuses with `403 rides_feature_disabled`, using the city of `lat`/`lng` when sent, else the account's country.

## Where a point is (`CityResolver`)
1. Google's city name for the point (Geocoding, Arabic, cached per ~1 km cell) matched by name to one of our cities (`Support\PlaceName` ignores spaces, tatweel, diacritics, hamza forms);
2. otherwise a `service_area` zone polygon (bounding box in SQL, then point-in-polygon in PHP — `Support\Geo`);
3. otherwise the nearest Discover city within `rides.city_radius_km` (60).
So the city still comes out when the key runs out or Google can't tell. No spatial SQL anywhere: the tests run on SQLite.

## Policies (`RidePolicies`, `ride_policies`)
- One row per scope (country, city, zone, category) holding only the keys it overrides; the defaults and every allowed key are `rides.policy_defaults` (docs/rides-structure.md §12). Prices live here too (`price.*`).
- Resolution, broadest first, each key from the most specific row: country → country+category → city → city+category → zone → zone+category.
- `RidePolicies::save()` validates (`RidePolicyData::validate`: unknown keys and wrong kinds are refused, `422 rides_invalid_policy`), keeps one row per scope, and writes `audit_logs` (`ride_policy.saved`, before / after).
- `pricing_mode` is the admin option: `upfront` or `metered` (open-destination rides are always metered).

## Other tables (phase 0)
`ride_categories` (+ translations, 11 categories), `ride_category_document_types`, `ride_zones` (+ translations; the bounding box is kept from the polygon on save), `ride_tax_rules` (+ translations; Saudi VAT 15% on the commission, carried by the driver — Jawad's rule), `ride_cancel_reasons` / `ride_rating_tags` (+ translations), `driver_points_rules`, and `ride_regulator_cities`.

**`ride_regulator_cities`:** Wasl rejects a trip if the city name is off by one letter, so the name sent comes only from here — the exact official name chosen by the admin, locked once a trip was accepted with it — never a translation, never Google. Seeded with «الرياض» only (the name Jawad uses in production); the rest of the official list is needed from ELM / Wasl.

## Maps (`GeoProvider` → `ResilientGeoProvider`)
- **Google first** (`GoogleGeoProvider`): Routes API (`computeRoutes`, field mask: distance, duration, polyline, legs) and Geocoding (city name). The key is set in the admin (`ride_settings.google_maps_key`, encrypted), else `GOOGLE_MAPS_SERVER_KEY`.
- **Free estimate when Google can't answer** (no key yet, the key ran out, a refusal, no connection) — Jawad's approach: straight-line distance per leg × a road factor (`fallback_road_factor_bp`, 13000 = ×1.3), at an average speed (`fallback_speed_kmh`, 60 like Jawad). `RouteResult::$estimated = true`, no polyline. A ride is never blocked on Google.
- City names are cached `geocode_cache_days` (30); a miss only an hour, and a new key drops the cache (`forgetCityNames`).

## The regulator's city name (`RegulatorCityNames`)
`officialName('wasl', lat, lng)`: Google's city name if it matches an official name in `ride_regulator_cities`, else our city for the point through its mapping, else `null` (the trip waits in the admin). The name sent is always the stored official one, never Google's spelling.

## Seeding
`RidesDatabaseSeeder` (in `DatabaseSeeder`, after the service categories): the 11 categories (only Economy active), Saudi Arabia's policy and Economy's **example** prices (the admin sets the real ones), the VAT rule, Riyadh's Wasl name, the phase-1 feature switches for Saudi Arabia (`rides`, `category.economy`, `multi_stop`, `open_destination`, `cash`, `wallet`, `card`, `tips` — review before launch), cancel reasons, rating tags and points rules. Safe to run again.

## API (phase 0)
| Method | Path | Guard | Query | Returns |
|---|---|---|---|---|
| GET | `mobile/v1/rides/features` | `user_api` | `lat?`, `lng?` | `{country_id, city_id, service, categories{slug: bool}, features{name: bool}}` — what the rider app may show here |
| GET | `admin/v1/ride-settings` | `admin_api`, `ride-settings.view` | | `{google_maps_key_set, google_maps_key_hint (••••1234), env_key_set, fallback_speed_kmh, fallback_road_factor_bp, geocode_cache_days}` — the key itself is never returned |
| PUT | `admin/v1/ride-settings` | `admin_api`, `ride-settings.update` | `google_maps_key?` (empty clears), `fallback_speed_kmh?` (10–150), `fallback_road_factor_bp?` (10000–30000), `geocode_cache_days?` (1–365) | Same as GET; audited (`ride_settings.updated`) |

## The ride (phase 1)
**Tables:** `ride_quotes`, `rides`, `ride_counters`, `ride_holds`, `ride_stops`, `ride_events` (append-only), `ride_active_locks`, `ride_offers`, `ride_price_lines`, `ride_adjustments`, `ride_tracks`, `ride_settlement_items`, plus the people tables (`driver_ride_profiles`, `driver_daily_stats`, `driver_points_ledger`, `rider_ride_profiles`, `ride_blocks`, …) and the ledger categories `trip_commissions`, `trip_taxes` («ضريبة التوصيل»), `discount_expenses`.

**Flow** (`Services/Lifecycle`, `Services/Dispatch`):
1. `QuoteEngine` prices every open category with one route call; a category with no prices set is never offered. Upfront → a fixed total; metered → a ±15% range; open destination → the rate card and a fixed hold.
2. `RideRequestService` re-checks the quote (uuid + fingerprint + expiry) and the switches, takes the rider's lock (one active ride), freezes the policy, the tax and the breakdown in `confirmed_snapshot`, and holds the wallet amount. A cash ride also collects an old debt (`previous_balance`). A card ride is created `awaiting_payment` with a checkout of purpose `ride` (its id is `checkout_id` on the ride): the app opens its one payment screen (`wallet/checkouts/{id}`), paid from the balance or a gateway top-up. `RideCheckoutPurpose` implements the wallet's new `ReservesFunds`: paying only makes sure the money is in the wallet, then the purpose holds it and the search starts — nothing is debited or booked as revenue at that point, and coupons don't apply. Not paid within `payment.await_s` (15 min) → the ride expires and the checkout closes.
3. `DispatchEngine`: one offer at a time to the nearest eligible online driver, the radius growing 5 km per round up to 50 km (Jawad); `rides:dispatch-tick` every 10 s expires offers, runs due rounds, and expires the ride after `search.max_search_s`. Accepting is atomic (the driver's lock row and the conditional status move commit together).
4. `RideTripService`: `arrive` (within `arrival.radius_m`), `start` (the PIN when `pin.required`, 5 tries), stops, `end`. The final price is the agreed fare + paid waiting (upfront), or computed from the distance driven (metered): the phone's odometer when plausible, else the logged points, else the route / straight line × 1.3 (`distance_source`). Wallet → captured; cash → `cash_due`, then `collect-cash` (change can go to the rider's wallet).
5. `RideSettlementService`: commission and tax go to the ledger. Cash ride → commission + tax are taken from the driver's wallet now. Wallet ride → the driver's net waits `settlement.pending_days`, then `rides:release-earnings` (hourly) posts it.
6. `RideCancellationService`: rider cancel is free before acceptance and for `cancel.free_after_accept_s` after (up to `cancel.rider_daily_free` a day), else `price.cancel_fee_minor`, which goes to the driver who came. A driver may step back before arriving (the ride searches again without them); after arriving it's a no-show after `no_show.wait_s`.
7. `RideRatingService`: each side rates once, within `rating.window_h`, in the shared `ratings` table (rateable = the ride); the averages live on the ride profiles. Not exposed through the generic rating endpoint.

Every status move goes through `RideStateMachine` (allowed moves, `version`, a `ride_events` row). Writes take an `Idempotency-Key`: the same key for the same action returns the ride again; a key reused for another action is `409 rides_idempotency_conflict`. Live updates: private channel `rides.{uuid}` (rider on `user_api`, the ride's driver on `driver_api`), events `ride.changed` and `ride.driver_location`.

## Bidding — inDrive style (phase 2, `Services/Bidding`, `ride_bids`, `ride_price_offers`)
A category with `pricing_strategy = bid` (seeded: «العروض» / Offers, off) is quoted only with a destination and the `bidding` switch on. Its quote carries `bid{suggested_minor, floor_minor, step_minor}`: floor = suggested − `bids.rider_max_discount_bp` (20%), in whole currency units, never under the minimum fare.
1. **Booking** `POST rides` with `rider_price_minor` (≥ floor) → `bidding`, `booking_mode = bid`; a wallet ride holds the rider's price; card goes through the payment screen first and then to `bidding`.
2. **Batches:** every `bids.batch_interval_s` (10 s) up to `bids.batch_size` (3) more drivers get it (radius growing as in a normal search); each keeps it `bids.request_ttl_s` (60 s). The request card carries `bid{rider_price_minor, cap_minor, steps_minor [+5/+10/+15], net_at_rider_price_minor, commission, tax, my_bid, attempts_left}`.
3. **Driver:** `POST driver/v1/offers/{uuid}/bid {price_minor}` — equal to the rider's price = **accepted at once** (`DispatchEngine::accept → assign`); higher (up to `bids.counter_cap_bp`, 50%) = an offer with a `bids.ttl_s` (20 s) timer. One live offer per driver (`driver_bid:{id}` lock); `bids.max_attempts_per_driver` (0 = no limit). Declined or timed out → they may offer again, even lower, still above the rider (the request stays 30 s more).
4. **Rider:** `GET rides/{uuid}/bids` (cheapest first, `cheapest` / `nearest`, `seconds_left`, how many queued past `bids.max_visible`), `POST …/bids/{bid}/accept` (wallet holds the difference first — no money, no ride; the offer stays until its timer ends), `…/decline`, `POST rides/{uuid}/raise {price_minor}` (holds the difference, drivers see it, a new batch goes out). No offers after `bids.suggest_raise_after_s` → a push suggesting a raise.
5. **Agreement** is atomic (`DispatchEngine::assign`: the driver's lock row + the conditional move): the price becomes the fixed fare (`agreed_price_minor`, breakdown recomputed — commission and tax as usual), every other offer is withdrawn and every bidder freed. A ride that ends any other way (`RideCloser`) withdraws its bids too.

## Booking ahead (phase 3, `Services/Scheduling/ScheduledRideService`)
R-12, R-105..108, R-165, R-170, D-119, D-168, D-260..269. Switch `scheduled`; not for bidding categories (offers are live).
1. **Booking** `POST rides` with `scheduled_at` (ISO with offset): between `scheduled.min_ahead_s` (1 h) and `max_ahead_s` (7 days), at most `max_per_rider` upcoming, not within `overlap_gap_s` of another of the rider's bookings. → `scheduled`, `booking_mode = scheduled`; a wallet ride holds now, a card ride pays on the payment screen and then waits as `scheduled`. **No rider lock and no search** until its time — the rider may ride now meanwhile. The quote carries `scheduling{min_ahead_s, max_ahead_s, free_cancel_before_s, cancel_fee_minor}` (null for bidding).
2. **Cancelling:** free until `scheduled.free_cancel_before_s` (60 min) before the time, then `price.cancel_fee_minor` whatever the stage; the preview gives `free_until`.
3. **Captains (D-260..269):** `GET driver/v1/scheduled` = unreserved bookings within the max search radius, in categories they may and want to drive (time, fixed net, `online_from`, pickup distance); `POST scheduled/{uuid}/reserve` (one captain; never two of theirs within the trip + `overlap_gap_s`); `GET scheduled/mine` (`can_start`, `starts_at`, `release_counts_as_cancel`); `POST scheduled/{uuid}/release` (later than `driver_free_release_before_s` (2 h) before = counted as a cancel); `POST scheduled/{uuid}/start` from `driver_start_before_s` (1 h) before → `accepted`, straight to them. A captain whose reservation starts before an instant ride could end is not offered that ride.
4. **The time comes** (`rides:dispatch-tick` → `ScheduledRideService::tick`): `reminder_before_s` (1 h) before, the rider and the reserved captain are reminded once (`reminded_at`). At `lead_time_s` (30 min) before, the rider's lock is taken and the ride goes to the reserved captain if online, seen lately and free — else the reservation is dropped (counted against them) and it searches like any ride (`search_started_at`). The rider still on another ride: retried every minute, and `expired` (`scheduled_missed`, nothing charged) `start_grace_s` (15 min) after the time.
5. **Waiting:** `Ride::waitingFrom()` = the later of the arrival and the booked time — a captain who came early neither starts the free waiting nor the no-show clock (`times.waiting_from` in both apps).

Rider: `GET rides?tab=current|scheduled|past` (the list carries `scheduled_at`, `free_cancel_until`); a ride carries `scheduled{at, free_cancel_until, starts_search_at, reserved}` and `reserved_driver{name, avatar_url, rating, trips_count}`. Pushes: `ride_scheduled`, `_reminder`, `_reserved`, `_driver_released`, `_searching`, `_missed`; captain: `ride_scheduled_driver_reminder`, `ride_scheduled_go`, `ride_reservation_dropped`.

## Safety (`Services/Safety`, `ride_shares`, `ride_safety_incidents`)
R-84..86, R-110, R-111, R-113, D-141, D-142, D-156. The ride card has the wireflow's tiles 💬 Message · 📤 Share · 🚨 SOS (the driver app: Share + SOS on the trip band).
- **Family link** (switch `share`): `POST {uuid}/share` (rider `mobile/v1/rides`, captain `driver/v1/rides`) returns one link per person per ride (`GET` shows it, `DELETE` turns it off). `https://…/ride/{token}` is a public page (no app, no account): a live OpenStreetMap with the car gliding, the state with a 5-step bar, the captain's first name, the car and its plate — never a phone number or a last name — and "Arrived safely" at the end; it polls `/ride/{token}/live` every 5 s and dies `safety.share_ttl_after_end_s` (1 h) after the end.
- **SOS:** `POST {uuid}/sos {lat?, lng?, accuracy_m?, note?}` from either side, while the ride runs and up to an hour after it. Kept in `ride_safety_incidents` with where and the ride's state, a `sos` ride event, and an in-app alert to admins holding `ride-safety.view`. The other side is never told. Pressing again within a minute is the same alert (its place updated). The answer carries `emergency_number` (policy `safety.emergency_number`, 911); the app's sheet: a big red button with breathing rings (hold to alert), "Call 911", "Share my ride". Every ride carries `safety.emergency_number`.
- **Admin → Rides → Safety (SOS)** (`ride-safety.view / update`): open first, a red pulse on open ones, who pressed it (with their phone), a map link, the ride; "On it" and "Resolved" with a note (audited); refreshes every 20 s. The ride page lists its SOS and family links (with views).

## Safety Center (`SafetyCenterService`, `SafetyContacts`, `SafetyCheckService`)
R-112, R-113, R-117, R-219..226, R-229, R-230, D-124, D-140, D-143..145, D-151..155, D-157, D-159, D-187, D-381, D-396, D-410. Tables `ride_trusted_contacts`, `ride_safety_preferences`, `ride_safety_checks` (+ `ride_blocks`, `favorite_drivers`).
- **Screens:** the rider opens it from the 🛡️ button always on top of the rides map; the captain from the home card. Both: SOS, trusted contacts, Smart Ride Check (always on), and who they won't be matched with. The rider also gets the ride-code choice (as the city sets / every ride / at night), a check-in after arriving (off / 10 / 20 / 30 min), the seatbelt reminder, and favourite captains. API: `GET|POST|PUT|DELETE {mobile/v1/rides|driver/v1}/safety[/contacts/{id}|/preferences|/blocks/{id}|/favorites/{driver}]`.
- **Trusted contacts** (max `safety.max_contacts`, never your own number, never shown to the other side): each gets the ride's live link by itself on **every ride**, **at night only** (`safety.night_start_h`..`night_end_h`), or **by hand** (`POST {uuid}/contacts/share {ids?}`), and "arrived safely" at the end. A contact on DORR is told in the app; anyone else by SMS (`safety.contacts_sms`, the country's SMS provider). Logged as `contacts_notified` ride events.
- **Ride code:** `pin.required` from the city, or the rider's choice (always / at night).
- **Smart Ride Check:** with the dispatch tick, a ride standing still `safety.stop_alert_s` away from its stops / destination, or `safety.deviation_m` off the planned route (point-to-segment), asks both sides "Are you OK?" (push + a dialog from `safety_check` on the ride). One of a kind per `safety.check_cooldown_s`. **Check-in:** `checkin_after_min` after the end. "I'm OK" closes it, "I need help" is an SOS, silence for `safety.check_answer_s` → an incident (`ride_check` / `checkin_missed`) and an alert to that person's contacts. The rider app also reads a pending check from `GET safety` (`pending_check`) after the ride ended.
- **Reports:** `POST {uuid}/report {type, note?, lat?, lng?}` — rider: `unsafe_driving / abuse / harassment / vehicle_issue / other`; captain: `rider_mismatch / abuse / threat / accident / road_hazard / other`. An accident answers with the steps to take now (`rides.accident_guidance`). Urgent kinds alert the safety team at once. All show on Admin → Safety with their kind.
- **"Not this car"** `POST {uuid}/vehicle-mismatch` (accepted / arrived): cancelled with no fee + a `vehicle_mismatch` incident.
- **Block / favourite:** `POST {uuid}/block` (either side — never matched again, also in scheduled rides); `POST {uuid}/favorite {on}` — favourite captains are offered first when within the radius (a preference, not a promise). The ride carries `favorite_driver`.
- **Seatbelt:** a push and a banner when the ride starts (the rider may turn the push off); the captain gets the banner too.

## Price extras, the tip and the rules before confirming (rides_tasks batch 2)
R-45, R-47, R-48, R-49 (partial), R-52, R-53, R-144, D-182, D-204; Wireflow م11 / م12 and step 7.
- **`PriceExtras`** adds what the rider pays besides the fare, each on its own line, in this order before the old debt / commission / totals: `customer_fee` (R-47: `price.customer_fee_minor` + `price.customer_fee_bp` of the fare part), `discount` (R-45: negative amount), `payment_method_fee` (R-48: `payment.card_fee_minor` + `payment.card_fee_bp`, or `payment.cash_fee_minor`; the wallet never adds one). **The driver's net never moves**: it is worked out on the fare before them. The same `extras` (kept in the quote's `policy_snapshot.extras`, then the ride's `confirmed_snapshot.extras`) price the quote, a change during the ride (`rev.N`) and the end, so the numbers agree. Not on bidding prices.
- **Coupon (`RidePromotions`, switch `coupons`):** `POST quotes {…, coupon_code}`. The wallet's own checkout refuses rides (a held ride has no price yet), so a wallet coupon (`purposes` empty or containing `ride`, this country, not used / expired) is priced into the quote instead: each quote returns `coupon{code, discount_minor, applied, reason}` — a category under the coupon's minimum keeps its price with a reason. Refused codes: `rides_coupon_invalid|expired|used|not_here|needs_destination` (open destination). Spent at confirmation (`wallet_coupon_redemptions.ride_id`, re-checked under a lock), the real discount written at the end, given back by `RideCloser` when the ride ends without a trip. DORR pays it: ledger `discount_expenses`; on a cash ride the driver gets it back on their wallet (`discount_compensation` item).
- **Fees to DORR:** ledger `trip_customer_fees` and `trip_payment_fees`. Cash ride → taken from the driver's wallet with the commission (`customer_fee` / `payment_method_fee` settlement items), since the driver kept them in the cash.
- **Payment-method fee (R-48):** each quote returns `payment_fees{wallet, card, cash}` on its price; the chosen one joins the lines, the agreed price and the hold at confirmation. **FX / international card (R-49):** the line code `fx_fee` and its labels exist in both apps, but no licensed provider tells us a card is foreign — nothing computes it, so R-49 stays open.
- **Before confirming (R-52, R-53, `RidePolicySummary`):** each quote returns `policy_summary{cancel{fee_minor, free_after_accept_s, daily_free}, no_show{wait_s, fee_minor}, items[{code, text}], refund{wallet, card, cash}}` — written in the rider's language from the very policy the quote fixed.
- **Tip (R-144, D-182, D-204, `RideTipService`, switch `tips`):** `POST mobile/v1/rides/{uuid}/tip {amount_minor}` + `Idempotency-Key`. One per ride, a completed ride with a driver, within `tips.window_h` (24) of the end, 1…`tips.max_minor`; the ride carries `tip{allowed, presets_minor (tips.presets_minor), max_minor, until}` or the given one. Paid from the rider's **withdrawable** balance only (spend-only credit never becomes money a driver withdraws) — never cash — and all of it reaches the driver's wallet at once, no commission: `wallet_transactions` type `tip` both sides (one `operation_id`), a posted `tip` settlement item, `rides.tip_minor` / `tipped_at`, `driver_daily_stats.tips_minor`, a `tipped` event and the driver's `ride.tip_received` notification. Errors: `rides_cannot_tip`, `rides_tip_window_closed`, `rides_tip_out_of_range`, `rides_already_tipped`, `rides_insufficient_balance` (with `shortfall_minor`). The driver sees `tip_minor` on the ride, in `rides` history and `earnings.*.tips_minor`.
- **Apps:** DORR app (`RidesPricing.kt`) — a promo-code field (green ticket once applied, the old price struck through on the card), "Price details" with an icon per line, the fee on each payment chip, "Cancellation & refund" for the chosen method, and the tip under the rating (5 / 10 / 20 / other, a heart that pops once given; the receipt shows the tip line). Driver app (`TipViews.kt`) — a tips card on Earnings (counts up, "100% yours") and the tip on the finished trip. Admin: the new policy keys and the `coupons` switch are labelled (the policy page lists every key by itself).

## The terms in force before booking (R-179, `RideTerms`)
Each quote returns `terms[{type: term|privacy, title, service_id, scope: service|general, updated_at}]` — for each kind, the rides service's own published legal page (`service_categories.module_name = passenger_ride`), else the general one (`LegalPageRepository::effectiveForType`); a kind with no published page is left out. The DORR app shows "By confirming, the terms in force apply: Terms · Privacy" under the cancellation summary; each opens a sheet with the page (`GET mobile/v1/legal-pages?type=&service_id=&fallback=1` — `fallback` is new: a service without its own page gets the general one), whose scope and last update it names. Reading them doesn't block booking: recording consent, re-consent and refusal are R-180..R-184.

## Trip details, paying differently, tier, waiting and ETA (rides_tasks batch 3)
R-61, R-81, D-60, D-61, D-122, D-129.
- **How many ride (D-60):** `POST mobile/v1/rides {…, passengers}` — 1…the category's `capacity_max` (`rides_too_many_passengers` with `max`). Stored in `rides.passengers`; both apps and the request card read `passengers`.
- **Pickup note (D-61):** `pickup_note` on the same call — spaces collapsed, at most `booking.note_max_chars` (120, `rides_pickup_note_too_long`), and no phone numbers (7+ digits, Arabic-Indic too) or links (`rides_pickup_note_contact`): the two talk through the app only. Stored in `rides.pickup_note`; the captain sees it on the request card and until the trip starts.
- **Changing the way of paying (R-61, `RidePaymentChangeService`):** `POST mobile/v1/rides/{uuid}/payment-method {payment_method}` + `Idempotency-Key`, while `searching | bidding | scheduled | accepted | arrived` — wallet ↔ cash; a card ride may move to either (moving *to* a card needs the payment screen, so it isn't offered: `rides_payment_change_unavailable`). In one transaction: the old method's fee and (for cash) the old debt leave the confirmed lines, the new method's fee (R-48) and — for cash — a debt still owed join them; the agreed price, the snapshot's `extras` / `hold_minor` / breakdown follow; a wallet ride holds the new amount (not enough balance → `rides_insufficient_balance` and nothing changes). Event `payment_changed`; the driver gets `ride.payment_changed`. The ride carries `payment_change` = the methods allowed now (switch, category's `cash_allowed`, `cash.allowed`).
- **Tier badge (R-81):** the rider's `driver.tier` + `tier_label` (`rides.tiers.*`: new / bronze / silver / gold / diamond) from `driver_ride_profiles.tier`. **How a tier is earned (D-239..D-243) isn't built yet** — every captain shows "New captain" until it is.
- **Free waiting ends soon (D-122, `WaitingAlerts`):** `rides:dispatch-tick` sends `ride.free_wait_ending` (rider: minutes left and the per-minute price) and `ride.free_wait_ending_driver` once, `waiting.warn_before_s` (60) before the free minutes end — from the booked time for a booking (R-108). `rides.wait_warned_at` keeps it once. `waiting.warn_before_s` is in the ride's `waiting`; both apps turn the countdown amber and pulsing in that window, red when paid.
- **Time and distance left (D-129, `RideProgress`):** `progress{to: pickup|stop|destination, distance_m, eta_s, at}` on both apps' ride while `accepted` / `in_progress` — from the captain's last position to the next point, the straight line × `gps_gap.straight_factor_bp`, at `eta.speed_kmh` (30), rounded up to the minute. No map call.
- **Apps:** DORR app (`RidesTripDetails.kt`) — a − n + riders stepper and the note on the booking sheet, the tier pill (gold / diamond shine), "Arrives in 4 min · 1.2 km", "Paying with … · Change" while searching / before the trip, the waiting pill. Driver app (`TripDetailViews.kt`) — riders and the note on the request card and the ride, the time-left bar above "Navigate", the waiting pill on the status band.

## Changing the trip during the ride (`RideChangeService`, `ride_change_requests`)
R-91..95, D-131..137, wireflow 6أ / 6ب / 6ج. Switch `change_destination` (one more stop: `multi_stop`). Not for open-destination rides; one at a time.
1. **Preview** `POST mobile/v1/rides/{uuid}/changes {type: destination|add_stop, lat, lng, address}` → the new route (origin → stops → destination) and `distance_delta_m`, `duration_delta_s`, `price_delta_minor`, `new_total_minor` — nothing changes yet (lives 3 minutes).
2. **Send** `…/changes/{change}/send` (the rider's consent; a wallet ride must cover the difference) → the captain's current ride carries `change_request{place, distance_delta_m, earning_delta_minor, seconds_left}` for `change.driver_response_s` (30 s). `…/cancel` drops it.
3. **Captain** `driver/v1/rides/{uuid}/changes/{change}/accept|reject`. Accepted: the destination (or a new stop), the planned route and — for a fixed price — `agreed_price_minor` and the snapshot's breakdown change together; new price lines in phase `rev.N` (the confirmed ones stay), a `destination_change` / `stop_added` adjustment, `change_applied` event, the wallet holds the difference (else taken at the end). Bidding rides move by what the extra route costs at the city's rates. Metered rides keep paying by distance. Declined or no answer (`rides:dispatch-tick` expires it): nothing changes.
Both apps show the request's state from the ride (`change_request`): the rider a draining ring while waiting, then "✅ The captain agreed!" with the new destination, distance, time and price; the captain a full card with a 30-second ring, old → new, extra distance and earnings, "Agree +X" / "Decline".

## Ride chat (`RideChatService`, `ride_messages`)
Rider ↔ driver, text and ready-made messages (`lang/*/rides.php → quick`), open only while a driver is on the ride; when it ends nobody can write (`409 rides_chat_closed`) but the conversation stays for the admin. Live on `rides.{uuid}` as `ride.message`, plus a push to the other side; a `client_id` makes a re-send land once. API: `GET|POST mobile/v1/rides/{uuid}/messages` and `driver/v1/rides/{uuid}/messages` (`{open, quick_replies, messages}`).

## Wasl (`Services/Regulator/Wasl`)
Jawad's production integration (docs/rides-plan.md §11), keys in `.env` (`WASL_*`), every call in `wasl_logs` (never the keys):
- **Register** (`WaslDriverService::register`, `/dispatching/v2/drivers`): identity number, phone, birth date in Gregorian and Umm al-Qura Hijri (`Support\Hijri`, PHP intl), the three plate letters, plate number / type, sequence number. Missing details are named first (`rides_regulator_missing_data`). The answer fills `driver_regulator_status` (driver / vehicle eligibility, expiries — the vehicle licence comes back in Hijri — rejection codes) and Wasl's spelling of the name and the gender.
- **Daily check** (`rides:wasl-eligibility`, 00:01 Riyadh): `/drivers/eligibility` for every driver of a Wasl country, the criminal record handled as in Jawad (Absher approval reminder; declined / expired → registered again; `DONE_RESULT_NOT_OK` → no new registration for 11 months, `rides_regulator_blocked`), and a warning 30 days before an approval ends.
- **Trips** (`rides:wasl-trips`, every minute): completed rides with `wasl_status = pending`, retried after 1, 5, 10, 30, 60 minutes; `VAL_ERR` / `DUPLICATE_TRIP` stop at once (`failed`). City names only from `ride_regulator_cities` — a place without one is `CITY_UNMAPPED` and nothing is sent; a name Wasl accepted is locked.
- **Locations** (`rides:wasl-locations`, every minute): approved drivers who are online or on a trip and sent a point in the last 2 minutes, with `hasCustomer`, up to 1000 per request.
- **No exception by nationality** (owner's plan §11.4): Jawad skipped non-citizens; here every driver of the country is sent, and only a valid Wasl approval lets a driver work. Reasons come back translated (`lang/*/wasl.php`, Jawad's 38 codes).
- `RIDES_REGULATOR_ENFORCED=false` lifts the check on a development machine only.

## API (phase 1)
Rider (`user_api`, prefix `mobile/v1/rides`):
| Method | Path | Body / query | Returns |
|---|---|---|---|
| POST | `quotes` | `origin{lat,lng,address?}`, `destination?`, `stops?[]`, `category?`, `coupon_code?` | One quote per category: `uuid`, `fingerprint`, `expires_at`, `category`, `pricing_mode`, `total_minor` or `estimate{min,max}`, `hold_minor`, `route`, rider-visible `lines`, `coupon`, `payment_fees`, `policy_summary` |
| POST | `{uuid}/tip` | `amount_minor`, header `Idempotency-Key` | The ride with `tip` (R-144) |
| POST | `{uuid}/payment-method` | `payment_method` (`wallet`/`cash`), header `Idempotency-Key` | The ride, re-priced and re-held (R-61) |
| POST | `/` | `quote_uuid`, `fingerprint`, `payment_method` (`wallet`/`card`/`cash`), `passengers?`, `pickup_note?`, header `Idempotency-Key` | The ride (201); a card ride comes back `awaiting_payment` with `checkout_id` |
| GET | `current` · `/` · `{uuid}` | `per_page?` | The active ride (or empty) · history · one ride (driver, car, live location, PIN, lines, `rated`) |
| GET | `{uuid}/cancel/preview` | | `{stage, fee_minor, free_until}` |
| POST | `{uuid}/cancel` · `{uuid}/rate` | `reason_id?`, `note?` · `stars` (1–5), `tags?[]`, `comment?` | |
| GET | `cancel-reasons` · `rating-tags` | | Lists for the sheets |

Driver (`driver_api`, prefix `driver/v1`):
| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `offers` | | Open request cards: `seconds_left`, `ttl_s`, pickup distance / ETA, `earning_minor`, rider rating, the trip |
| POST | `offers/{uuid}/accept` · `decline` | `reason?` | Accept is atomic; a retry returns the same ride |
| GET | `rides/current` · `rides` · `rides/{uuid}` | | Includes `navigation.google_maps_url` (navigation opens outside the app), `earning_minor`, `cash_to_collect_minor` |
| POST | `rides/{uuid}/arrive` · `start` · `end` | `lat`, `lng` (+ `pin` · + `odometer_m`) | |
| POST | `rides/{uuid}/stops/{seq}` | `action` (`arrived`/`departed`) | |
| POST | `rides/{uuid}/collect-cash` | `received_minor` | Above the total → the change goes to the rider's wallet |
| POST | `rides/{uuid}/cancel` · `no-show` · `rate` | `reason_id` · — · `stars`, `tags?`, `comment?` | |
| GET | `earnings` | | Today / week / month, the last 7 days, pending earnings, wallet, rating, points |
| GET | `regulator` | | Wasl approval: driver / vehicle status, expiry dates, criminal record, `reasons[{code, message}]` in the driver's language, `blocked_until`, `work_problem` |
| POST | `regulator/register` · `regulator/check` | | Register the driver and the vehicle at Wasl · check again (5 an hour) |

## Admin (`admin/v1`, module `passenger_ride` in the sidebar)
| Screen | API | Permission |
|---|---|---|
| Rides | `GET rides` (search, status, payment, `wasl_status`, dates) · `GET rides/{id}` (timeline, confirmed & final lines, offers, holds / adjustments / settlement, track, Wasl with its logs, chat) · `POST rides/{id}/wasl-retry` | `rides.view / update` |
| Drivers · Document types | see Modules/Driver | `drivers.*`, `driver-document-types.*` |
| Switches | `ride-feature-rules` (+ `/options`): the service, each category, each feature, per country with city exceptions | `ride-feature-rules.*` |
| Categories | `GET ride-categories` · `PUT ride-categories/{id}` (names / taglines ar+en, colour, capacity, status, flags, driver & vehicle rules) | `ride-categories.view / update` |
| Prices & rules | `GET ride-policies?country_id&city_id&category_id` → `{own, inherited, effective}` · `PUT ride-policies` `{…scope, data}` · `GET ride-policies/scopes` · `/options` (defaults) | `ride-policies.view / update` |
| Wasl | `GET wasl-logs` (type, success, subject, result code) · `GET wasl-logs/{id}` (request / response); the trip queue is Rides filtered by `wasl_status` | `wasl-logs.view` |
| Reasons & tags | `ride-lists/{cancel-reasons|rating-tags}` CRUD (labels ar+en) | `ride-lists.*` |
| Settings | `ride-settings` (Google key, free estimate) | `ride-settings.view / update` |

The policy form shows money in the currency (stored in minor units), rates in percent (stored in basis points), times in seconds; each key can be overridden or left to inherit.

## Shared pieces added outside the module
- `audit_logs` + `App\Services\General\AuditLogger` (append-only; actor `admin` or `system`).

## Tests
`RidesTripDetailsTest` (5): riders over the seats, a phone number (Latin or Arabic-Indic digits) or a long note refused, then riders and a tidied note on the captain's request card and ride; wallet → cash (hold released, the cash fee on the lines and price, idempotent) → wallet (held again) with card refused, not enough balance changing nothing, no change once the trip started and the fee reaching the end; cash switched off; the gold tier and the time / distance left (to the pickup, none while waiting, then to the destination); "free waiting ends soon" not too early and only once.

`RidesExtrasTest` (8) also checks the quote's `terms` (none, the general pages, then the rides service's own terms winning); `MobileContentTest` the `fallback` of `legal-pages`. `RidesExtrasTest`: the service fee and a fixed coupon on the quote, spent at confirmation, the driver's net on the whole fare, the ledger (`discount_expenses`, `trip_customer_fees`), no second use; a cash ride with a capped percent coupon and a cash fee — the driver's wallet + the cash in hand = their net; the card fee added to the agreed price and the hold; a cancelled ride giving the coupon back and the refused codes (unknown, other purpose, expired, under the minimum, switch off); the cancellation / no-show / refund summary; a tip once, idempotent, all to the driver now (ride, history, earnings); a tip needing the switch, the withdrawable balance and the 24-hour window.

`RidesSafetyCenterTest` (8): contacts (limit, not yourself, per person) and preferences; every-ride contacts get the link on accept (not the night-only one at noon), "arrived safely" at the end, the ride code by choice; a blocked captain never offered again; a farther favourite asked first; "not this car" cancels free; reports and the accident guidance; a long stop asks both — the rider says OK, the captain's silence reaches the team and his contacts, not asked again straight away; the check-in after arriving and its "help" (an SOS).

`RidesSafetyTest` (4): the family link follows the ride (first names, plate, no phone numbers, the car's place only while it runs), ends with "arrived safely" and dies an hour later; revoking, the captain's own link, someone else's ride, the switch; SOS from both sides (a repeat within a minute is the same alert) handled by the safety team; the permission.

`RidesChangeTest` (4): a farther destination previewed (nothing changes), sent, shown to the captain with what they earn more, accepted — the route, the fixed price, the adjustment, the lines and the final charge follow; one request at a time, declined and unanswered requests change nothing; one more stop; the switch and someone else's ride.

`RidesScheduledTest` (10): booking ahead holds the money without a search or a rider lock (and riding now still works), the hold outliving the wallet's one-day valve for a booking days ahead, the policy window / overlap / switch, free cancellation then the fee, the reminder then the search at lead time, a captain reserving (taken by one only) and heading out with the waiting starting at the time, an offline captain losing the reservation, giving it back early vs late, a card booking waiting once paid, a rider still busy missing the booking with nothing charged.

`RidesBiddingTest` (6): the floor and a batch of three; accepting the rider's price assigns at once (fixed fare to the end); below-rider / over-cap / one-live-offer refusals, cheapest-first board, decline then a lower re-offer, the rider takes it with the wallet holding the difference and everyone else freed; a timer running out; a raise (and no money = no ride); the visible limit queue.

`RidesAdminTest` (6): switches per country with a city exception, a city+category price overriding only what it changes (with what it inherits), a category's names / flags, a ride's whole story and the Wasl queue filter, reasons & tags, and permissions.

`RidesWaslTest` (9): Jawad's registration body and headers (Hijri birth date, plate letters), a refusal in the driver's language with no nationality exception, missing details named before sending, the 11-month criminal-record block, an expired approval stopping work, a trip with the official city name, retries and a final code, an unmapped city never guessed, locations with `hasCustomer`. `RidesFlowTest` also covers card rides (paid from the balance, through a gateway callback, and expiring unpaid).

`RidesFlowTest` (10): a wallet ride from booking to the released earning (idempotent booking, holds, ledger, locks), a cash ride (commission + tax off the driver, change to the rider), one driver per ride and the next round after a timeout, one active ride per rider and the free cancel, the late-cancel fee to the driver, a driver stepping back, the no-show wait, open destination metered by the odometer, ratings both ways, switched-off features.

`RidesFoundationTest` (14): feature switches, the features endpoint by city, polygons before nearest city, policy merging / validation / audit, the seeders (twice), geometry, Google Routes, the admin key over `.env`, the free estimate, the city from Google then our list, the Wasl official name, and the settings screen.
