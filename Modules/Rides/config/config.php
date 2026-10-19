<?php

return [
    'name' => 'Rides',

    /*
    | The DORR service this module is (service_categories.module_name).
    */
    'service_module_name' => 'passenger_ride',

    /*
    | Google Maps Platform (owner's decision): the key never leaves the server.
    */
    'google' => [
        'key' => env('GOOGLE_MAPS_SERVER_KEY'),
        'routes_url' => env('GOOGLE_ROUTES_URL', 'https://routes.googleapis.com/directions/v2:computeRoutes'),
        'geocode_url' => env('GOOGLE_GEOCODE_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
        'timeout' => 10,
        // When Google refuses (the key, billing, a used-up quota) or can't be reached, it is left alone for
        // this many minutes — the straight-line estimate answers meanwhile — and then tried again.
        'pause_refused_min' => 30,
        'pause_unreachable_min' => 5,
    ],

    /*
    | Mapbox — the other map provider the admin can pick instead of Google (O-02; the free OpenStreetMap
    | fallback was removed, O-01). The token is the admin's (Rides → Settings) or MAPBOX_TOKEN: a public
    | `pk.` token, since the apps draw the map with it too.
    */
    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
        'directions_url' => env('MAPBOX_DIRECTIONS_URL', 'https://api.mapbox.com/directions/v5/mapbox/driving'),
        'geocode_url' => env('MAPBOX_GEOCODE_URL', 'https://api.mapbox.com/geocoding/v5/mapbox.places'),
        'timeout' => 8,
    ],

    /*
    | A point belongs to the nearest admin city within this many km when no service-area polygon
    | covers it (docs/rides-structure.md §0.4 — same idea as the chat's city rules).
    */
    'city_radius_km' => 60,

    /*
    | The regulator of each country (by ISO code). In a country listed here no driver goes online
    | without its approval (owner's decision, docs/rides-plan.md §11.4). RIDES_REGULATOR_ENFORCED=false
    | lifts the check on a development machine until the Wasl keys are in — never in production.
    */
    'regulators' => ['SA' => 'wasl'],
    'regulator_enforced' => (bool) env('RIDES_REGULATOR_ENFORCED', true),

    /*
    | Wasl (TGA, through ELM) — exactly Jawad's connection (docs/rides-plan.md §11.1). The keys live
    | in .env only. Without them nothing is sent: drivers stay "pending" and trips wait.
    */
    'wasl' => [
        'url' => rtrim((string) env('WASL_HOSTED_URL', 'https://rabet-wasl.api.elm.sa/api'), '/'),
        'app_id' => env('WASL_APP_ID'),
        'app_key' => env('WASL_APP_KEY'),
        'client_id' => env('WASL_CLIENT_ID'),
        'timeout' => 15,
        'timezone' => 'Asia/Riyadh',
        // A trip is tried this many times, waiting this long between tries (Jawad's job).
        'trip_backoff_s' => [60, 300, 600, 1800, 3600],
        // Answers that will never succeed by trying again.
        'trip_final_codes' => ['VAL_ERR', 'DUPLICATE_TRIP'],
        // After DONE_RESULT_NOT_OK, no new registration for this long (Jawad: 11 months).
        'criminal_block_months' => 11,
        'expiry_warning_days' => 30,
        'locations_per_request' => 1000,
    ],

    /*
    | The day a driver's stats count in, by country (a city's own time zone wins when it has one).
    */
    'default_timezone' => 'Asia/Riyadh',

    /*
    | Every key a ride policy may set, with its default (docs/rides-structure.md §12). A policy row
    | stores only what it overrides; the most specific row wins per key (§2.4). Unknown keys are
    | refused. Money in minor units, rates in basis points, distances in metres, times in seconds.
    | Defaults marked "Jawad" come from the app running in the market; the rest are assumptions the
    | admin is expected to set.
    */
    'policy_defaults' => [
        'pricing_mode' => 'upfront',                      // owner: an admin option (upfront | metered)
        'quote_ttl_s' => 300,
        'material_change_bp' => 1000,

        'price.base_minor' => 0,
        'price.per_km_minor' => 0,
        'price.per_min_minor' => 0,
        'price.min_fare_minor' => 0,
        'price.commission_type' => 'percent',             // Jawad: percent | fixed
        'price.commission_value' => 2000,                 // basis points when percent, minor units when fixed
        'price.cancel_fee_minor' => 0,
        'price.waiting_per_min_minor' => 50,              // Wireflow: 0.5 SAR / minute
        'price.stop_wait_per_min_minor' => 50,
        'price.customer_fee_minor' => 0,                   // R-47: the rider's service fee (DORR's), fixed…
        'price.customer_fee_bp' => 0,                      // …plus a share of the fare; 0 + 0 = no line

        'waiting.free_s' => 180,                          // Wireflow: 3 free minutes
        'waiting.warn_before_s' => 60,                    // D-122: "free waiting ends soon" this long before
        'eta.speed_kmh' => 30,                            // D-129: the average city speed the remaining time is worked out at
        'booking.note_max_chars' => 120,                  // D-61: the pickup note — what's needed only
        'circle.ask_after_s' => 90,                       // R-INN-07: no favourite took it this long → the rider is asked before it goes to everyone
        'places.recent_max' => 5,                         // R-5: recent destinations offered as shortcuts
        'places.favorites_max' => 12,                     // R-174: saved favourite places shown as shortcuts
        'cases.max_age_h' => 0,                           // R-133, D-229: a case can be opened this long after the ride (0 = no limit)
        'cases.appeal_allowed' => false,                  // R-140, D-237: the person may ask for a decision to be looked at again (off until the country allows it)
        'cases.appeal_window_h' => 0,                     // …within this long of the decision (0 = no limit)
        'driving.window_h' => 24,                         // D-42: driving time is counted over the last this-many hours…
        'driving.warn_s' => 0,                            // …a rest reminder at this much (0 = no reminder)
        'driving.max_s' => 0,                             // D-43: the mandatory limit — no going online / new requests at this much (0 = no limit)
        'auto_accept.price_filter' => false,              // D-36: a captain may set a minimum fare / per-km for Auto-Accept (off until the country allows it)
        'auto_accept.payment_filter' => false,            // D-37: …and the payment methods Auto-Accept takes
        'earnings.report_max_days' => 366,                // D-197: the longest range the earnings report takes
        'pickup.match_m' => 40,                           // R-16: a place this close to an approved point is that point

        'queue.entry' => 'manual',                        // D-277: how a captain gets in line at an airport — auto | manual
        'queue.leave_grace_s' => 120,                     // D-280: outside the zone this long → out of the line
        'queue.stale_after_s' => 300,                     // D-280: no location from the captain this long → out
        'queue.max_wait_s' => 0,                          // D-280: out after waiting this long; 0 = no limit
        'queue.min_samples' => 5,                         // D-279: the wait estimate needs this many finished waits
        'queue.stats_days' => 14,                         // …from the last this-many days
        'zones.alert_cooldown_s' => 600,                  // D-283: the same restricted-area alert at most this often per captain
        'stops.max' => 3,
        'stops.free_wait_s' => 120,
        'open_destination.hold_minor' => 10000,

        'search.min_radius_m' => 5000,                    // Jawad
        'search.radius_step_m' => 5000,                   // Jawad
        'search.max_radius_m' => 50000,                   // Jawad
        'search.round_interval_s' => 10,                  // Jawad
        'search.offer_ttl_s' => 15,
        'search.batch_size' => 3,                         // O-36 (owner): a request goes to this many captains at once; the first to accept takes it
        'search.max_open_offers_per_driver' => 3,         // O-36 (owner): a captain may hold this many requests at once and pick one
        'search.ask_rider_after_s' => 240,                // Jawad: 4 minutes
        'search.max_search_s' => 600,                     // Jawad: 10 minutes
        'payment.await_s' => 900,                         // a card ride not paid within 15 minutes expires
        'search.refuse_cooldown_s' => 300,                // Jawad

        'driver.stale_after_s' => 900,                    // Jawad: 15 minutes
        'driver.min_balance_minor' => -5000,              // Jawad: driver_min_to_get_ride
        'arrival.radius_m' => 100,                        // Jawad
        'arrival.destination_radius_m' => 500,            // O-39/O-40: "end ride" within this of the destination, else only as an early end (repriced)

        'cancel.rider_daily_free' => 3,                   // Jawad
        'cancel.driver_daily_free' => 3,                  // Jawad
        'cancel.free_after_accept_s' => 120,
        'no_show.wait_s' => 300,
        'pin.required' => false,
        'with_me.radius_min_m' => 1000,                   // O-30: how far off his road a With Me captain may set (min…
        'with_me.radius_max_m' => 5000,                   // …max; equal = the app doesn't show the control)
        'with_me.driver_share_bp' => 2000,                // O-32: the captain's part of each joining rider's price (20%)
        'with_me.split' => 'proportional',                // O-32 (owner: an admin option): proportional (by the distance shared) / equal
        'hourly.enabled' => false,                        // O-17: this category can also be booked by the hour (owner: motorcycles)
        'hourly.hour_price_minor' => 0,                   // O-17: the price of one hour (a fixed fare: hours × this)
        'hourly.min_hours' => 1,
        'hourly.max_hours' => 8,
        'hourly.overtime_per_min_minor' => 0,             // O-17: each minute past the hours booked
        'with_me.min_pay_bp' => 5000,                     // O-32: sharing never takes a fare under this part of its price
        'bus.search_radius_m' => 15000,                   // O-22: a bus trip matches a place this close to its start / end
        'bus.max_seats_per_booking' => 10,
        'bus.free_cancel_before_s' => 3600,               // O-23: a ticket is refunded in full up to this long before it leaves
        'marine.search_radius_m' => 60000,                // O-25: marinas this close to the guest are shown
        'marine.free_cancel_before_s' => 86400,           // O-25: a sea trip / hire is refunded in full up to this long before it starts
        'calls.reveal_phone' => true,                     // O-09 (owner): each side may call the other's number from outside the app while the ride is on
        'rating.window_h' => 72,                          // a ride can be rated for 3 days
        'rating.driver_criteria' => ['speed', 'cleanliness', 'driving', 'behavior', 'car'], // O-41: what the rider scores the captain on
        'rating.rider_criteria' => ['behavior', 'punctuality', 'cleanliness'],             // O-41: what the captain scores the rider on

        'scheduled.lead_time_s' => 1800,                  // the search (or the reserved captain) starts this long before
        'scheduled.free_cancel_before_s' => 3600,         // R-106: 60 minutes
        'scheduled.min_ahead_s' => 3600,                  // book at least an hour ahead
        'scheduled.max_ahead_s' => 604800,                // and at most 7 days ahead
        'scheduled.max_per_rider' => 5,                   // upcoming scheduled rides a rider may hold
        'scheduled.overlap_gap_s' => 1800,                // two bookings (rider or captain) at least this far apart, after the trip
        'scheduled.reminder_before_s' => 3600,            // R-170 / D-266
        'scheduled.driver_start_before_s' => 3600,        // D-268: the captain may head to the pickup from then
        'scheduled.driver_free_release_before_s' => 7200, // a captain giving a reservation back later counts as a cancel (D-169)
        'scheduled.start_grace_s' => 900,                 // the rider still on another ride: retry until this long after the time

        'bids.rider_max_discount_bp' => 2000,             // owner: 20% under the suggested price
        'bids.counter_cap_bp' => 5000,
        'bids.counter_steps_minor' => [500, 1000, 1500],  // owner: +5 / +10 / +15
        'bids.ttl_s' => 20,                               // owner: 20 seconds
        'bids.max_visible' => 0,                          // owner: 0 = all offers show
        'bids.max_attempts_per_driver' => 0,              // 0 = no limit
        'bids.batch_size' => 3,                           // D-86
        'bids.batch_interval_s' => 10,
        'bids.suggest_raise_after_s' => 60,
        'bids.request_ttl_s' => 60,                       // how long a driver keeps a bidding request on screen

        'location.idle_interval_s' => 30,                 // owner: every 30 s
        'location.trip_send_interval_s' => 5,
        'location.trip_store_interval_s' => 15,
        'location.retention_days' => 30,

        'gps_gap.strategy' => 'route',                    // route | straight
        'gps_gap.straight_factor_bp' => 13000,

        'cash.allowed' => true,
        'cash.rider_max_debt_minor' => 0,                 // Jawad: max_debt_for_cash
        'payment.card_fee_minor' => 0,                    // R-48: a fee for paying by card (DORR's), fixed…
        'payment.card_fee_bp' => 0,                       // …plus a share of what is charged
        'payment.cash_fee_minor' => 0,                    // R-48: a fixed fee for paying cash
        'tips.window_h' => 24,                            // R-144: a tip can be added this long after the ride
        'tips.presets_minor' => [500, 1000, 2000],        // the ready amounts on the tip screen
        'tips.max_minor' => 10000,                        // the highest tip in one go
        'settlement.pending_days' => 2,
        'change.driver_response_s' => 30,                 // wireflow 6ب: the captain has 30 s to accept a new destination
        'safety.emergency_number' => '911',               // R-110: the SOS button dials it (Saudi: 911, unified)
        'safety.share_ttl_after_end_s' => 3600,           // a shared link shows "arrived safely" this long after the end
        'safety.night_start_h' => 21,                     // "at night" for PIN / auto-share: from 21:00…
        'safety.night_end_h' => 5,                        // …to 05:00, local time
        'safety.max_contacts' => 5,                       // trusted contacts per person (R-220)
        'safety.contacts_sms' => true,                    // trusted contacts who aren't on DORR get an SMS
        'safety.stop_alert_s' => 300,                     // R-222: stopped this long mid-ride (away from a stop) → "Are you OK?"
        'safety.deviation_m' => 1500,                     // R-112: this far from the planned route → "Are you OK?"
        'safety.check_answer_s' => 120,                   // no answer in time → the safety team and the trusted contacts
        'safety.check_cooldown_s' => 900,                 // at most one "Are you OK?" of a kind per ride in this time
        'detour.enabled' => false,
        'detour.tolerance_bp' => 2000,
    ],

    /*
    | Cases (complaints, disputes, unpaid-ride reports; docs/rides_tasks.md R-133..R-140, D-229..D-238).
    | The lists are what the person picks from; `priority` is the routing for the team's queue (anything not
    | listed is `normal`) — a starting point to be confirmed (NEEDS-DECISION).
    */
    'cases' => [
        'complaint_categories' => ['driver', 'vehicle', 'price', 'payment', 'safety', 'privacy'],
        'dispute_reasons' => ['waiting_fee', 'cancel_fee', 'no_show_fee', 'fare'],
        'priority' => ['safety' => 'urgent', 'privacy' => 'high', 'payment' => 'high'],
        'max_files' => 6,
        'max_file_kb' => 5120,
    ],
];
