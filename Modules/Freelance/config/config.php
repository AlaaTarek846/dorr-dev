<?php

return [
    'name' => 'Freelance',

    // service_categories.module_name of the service this module powers.
    'service_module_name' => 'freelance',

    // Sanctum token name prefix for the freelancer (one token per device: "freelance:android"…).
    'token_name' => 'freelance',

    // The API contract (docs/modules/freelance/API.md): v1 stays; a change that breaks an app goes to v2.
    'api_version' => 'v1',
    // The oldest app build the server still serves; the apps compare it with their own (FL-004).
    'min_app_version' => env('FREELANCE_MIN_APP_VERSION', '1.0.0'),

    /*
    | One currency for all freelance work, whatever the country (owner, 2026-11-04): every price, policy amount, plan, pack, agreement
    | and payment is in `currency`. The money sits in an international wallet — a wallet on the hidden `country_code` row (status off,
    | so it shows in no country list) whose currency is `currency`. A freelancer has only that wallet; a client has it next to their
    | local one, tops it up and withdraws from it, but can't transfer from it. The record's own country stays for geography.
    */
    'money' => [
        'currency' => env('FREELANCE_CURRENCY', 'USD'),
        // The tests point this at their own country row (phpunit.xml), so the old flows keep running on one wallet.
        'country_code' => env('FREELANCE_MONEY_COUNTRY', 'INT'),
        'country_name' => ['ar' => 'دولي', 'en' => 'International'],
        'flag_code' => 'un',
    ],

    // Biggest file a project, offer or delivery may carry (KB).
    'upload_max_kb' => (int) env('FREELANCE_UPLOAD_MAX_KB', 20480),

    // FL-1205: every upload is checked by its real bytes (programs, scripts, disguised files, PDFs with active content, archives hiding
    // programs or macros). That is not an antivirus: set the ClamAV daemon below and each file is also streamed to it.
    'uploads' => [
        'clamav' => [
            'enabled' => (bool) env('FREELANCE_CLAMAV_ENABLED', false),
            'host' => env('FREELANCE_CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('FREELANCE_CLAMAV_PORT', 3310),
            'timeout' => (int) env('FREELANCE_CLAMAV_TIMEOUT', 10),
            // A daemon that can't be reached refuses the file (true) or lets it through with a warning in the log (false).
            'fail_closed' => (bool) env('FREELANCE_CLAMAV_FAIL_CLOSED', true),
        ],
    ],

    /*
    | The numbers freelance work runs on (FL-008) — what a country without its own policy gets. The admin
    | overrides any of them per country, or per country + specialty (Admin → Freelance → Prices & rules).
    | Rates are basis points (1000 = 10%); money is the minor unit of the country's currency, and null
    | means "no limit" (a single amount can't suit every currency, so none is set here).
    | The meaning and checks of every key: Modules\Freelance\Services\Policy\FreelancePolicyData::KEYS.
    */
    'policy_defaults' => [
        'commission.type' => 'percent',
        'commission.value' => 1000,
        'commission.min_minor' => null,
        'commission.max_minor' => null,
        'client_fee.type' => 'percent',
        'client_fee.value' => 200,
        'client_fee.min_minor' => null,
        'client_fee.max_minor' => null,
        'offers.free_monthly_quota' => 10,
        'offers.window_days' => 14,
        'offers.max_per_project' => 50,
        'review.days' => 7,
        'review.auto_approve' => true,
        'review.free_revisions' => 2,
        'milestones.max_count' => 5,
        // A part is at least 50.00 in the country's currency when the work is split (owner, 2026-10-22); the admin changes it per country.
        'milestones.min_amount_minor' => 5000,
        'project.min_budget_minor' => null,
        'project.max_budget_minor' => null,
        // The freelancer's share (30%) of a part whose work started and then was cancelled by both sides.
        'cancellation.started_freelancer_bp' => 3000,
        // Disputes (FL-704 / FL-507): the days each side has to show what they have, and to appeal a decision before the money moves.
        // Rating each other after a project (FL-701): the days both have; then the ratings show.
        'ratings.window_days' => 14,
        // A change to an active agreement (FL-404 / FL-405): the days the other side has to answer.
        'changes.response_days' => 3,
        'disputes.response_days' => 5,
        'disputes.appeal_days' => 3,
        'earnings.hold_days' => 0,
        // Private projects (FL-205): the most freelancers one project can invite.
        'invitations.max_per_project' => 10,
        // Ready-made services (FL-L04): checked by the staff before clients see one, how many one freelancer offers at once (published or
        // waiting for review), and the hours the freelancer has to accept an order before it ends by itself.
        'services.require_review' => true,
        'services.max_active' => 5,
        'services.answer_hours' => 48,
        // Paid consultations (FL-L07): the client cancels with everything back up to these hours before the start, has these hours after
        // it to complain (then it is paid out), books at least these hours ahead and no further than these days.
        'consultations.require_review' => true,
        'consultations.max_active' => 5,
        'consultations.free_cancel_hours' => 24,
        'consultations.complaint_hours' => 24,
        'consultations.response_hours' => 24,
        'consultations.min_notice_hours' => 2,
        'consultations.max_days_ahead' => 30,
        // Special offers to past clients (FL-L09): how many one freelancer sends a day, how many may wait for one client at once, the days
        // before a client who said no can get another, and the longest an offer stays open.
        'special_offers.daily_limit' => 10,
        'special_offers.open_per_client' => 1,
        'special_offers.cooldown_days' => 7,
        'special_offers.max_valid_days' => 14,
        // Loyalty programs (FL-L09, part B): the biggest discount a freelancer may give a returning client (basis points: 3000 = 30%) and
        // the most steps one program has.
        'loyalty.max_discount_bp' => 3000,
        'loyalty.max_tiers' => 4,
        // Workroom (FS-150a): the most tasks one project may hold. null = no limit (the owner's decision: open at first, the admin sets it later).
        'workroom.tasks_max' => null,
        // Workroom File Hub (FS-150b): the most files, and the most storage in megabytes, one project may hold. null = no limit, like the tasks.
        'workroom.files_max' => null,
        'workroom.storage_mb' => null,
        // Teams (FS-201, D1): a freelancer is the Owner of one team and may join up to five more; the admin raises or clears (null = no limit) these.
        // A team's size has no cap at first.
        'teams.owned_max' => 1,
        'teams.joined_max' => 5,
        'teams.members_max' => null,
        'teams.offers_monthly' => null,
        // FS-211: the projects one team may run at the same time (an agreement that is `active`). These three team numbers are what a team with no
        // plan gets; a team plan raises them (never lowers: an empty policy = no cap stays no cap).
        'teams.projects_max' => null,
    ],

    // Skill suggestions (FL-102): how many new skills one freelancer may suggest a day.
    'skills' => ['suggest_daily_limit' => (int) env('FREELANCE_SKILL_SUGGEST_DAILY_LIMIT', 5)],

    // Ownership and licences (FL-L13): the terms an offer or a service gets when its freelancer says nothing (full transfer to the client), the
    // longest a licence may last (months), the most outside materials one delivery lists, and the days a freelancer waits before asking again to
    // show a finished work in their portfolio.
    'rights' => ['default_model' => 'transfer', 'max_term_months' => 120, 'max_materials' => 10, 'ask_again_days' => 3],

    // The consultation's video session (FL-L16): the room opens this many minutes before the start, both are warned this long before the end,
    // the client may say the freelancer did not come this long after the start, the recordings are kept this many days after the end (or
    // after the staff's decision on a complaint), and a download link lasts this long. The bucket is the chat's (`chat.recordings`): LiveKit
    // records into it, then the file moves to our private disk.
    'sessions' => [
        'open_before_minutes' => 5, 'warn_before_end_minutes' => 5, 'no_show_minutes' => 10, 'keep_days' => 7, 'link_minutes' => 15,
        'empty_timeout_seconds' => 600, 'prefix' => 'freelance-consultations',
    ],

    // The professional community (FL-L12): the longest post / comment, the most images on a post, what one member may write a day, how many
    // members reporting one thing hide it until the staff look, the longest mute, and how long an image link lasts.
    'community' => [
        'body_min' => 10, 'body_max' => 3000, 'comment_max' => 1500, 'max_images' => 4,
        'posts_per_day' => 5, 'comments_per_day' => 60, 'reports_per_day' => 20,
        'auto_hide_reports' => 3, 'max_mute_days' => 90, 'image_link_minutes' => 30, 'per_page' => 15,
    ],

    // Ready-made services (FL-L04): the most images, extras and listed features one service has, and how long an image link lasts.
    'services' => ['max_images' => 5, 'max_addons' => 5, 'max_features' => 10, 'image_link_minutes' => 30],

    // What a freelancer can list on the profile (FL-104).
    'profile' => ['max_specialties' => 5, 'max_skills' => 30, 'max_experiences' => 20, 'max_certificates' => 20, 'max_portfolio' => 30, 'max_portfolio_images' => 10, 'max_time_off' => 12],

    // A client's projects (FL-201 / FL-202): how long the text must be to send it, how many files / skills / open projects.
    'projects' => [
        'title_min' => 10,
        'description_min' => 30,
        'description_max' => 5000,
        'max_skills' => 10,
        'max_attachments' => 10,
        'max_open' => 20,
        'max_questions' => 5,
        'max_reopens' => 3,
        'max_duration_days' => 365,
        'attachment_types' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip'],
        // FL-210: when the final preview warns — a range whose top is over N times its bottom; a very short or very long project
        // next to a budget that is many times / hardly over the country's smallest budget.
        'preview' => ['range_ratio' => 5, 'short_days' => 3, 'long_days' => 180, 'big_budget_times' => 10, 'small_budget_times' => 2],
    ],

    // FL-208: the most freelancers told about one new project (the best fits first).
    'matching' => ['max_recipients' => 100],

    // FS-104: the people an owner may bring into one project (docs/modules/freelance/PERMISSIONS.md).
    'client_team' => ['max_members' => (int) env('FREELANCE_CLIENT_TEAM_MAX', 10)],

    // Offers (FL-301): how long the text must be, how many files.
    'offers' => ['details_min' => 30, 'details_max' => 3000, 'max_attachments' => 5],

    // Portfolio images are shown through links that expire after this many minutes (FL-105).
    'portfolio' => ['link_minutes' => (int) env('FREELANCE_PORTFOLIO_LINK_MINUTES', 30)],

    // Identity documents (FL-107): biggest file, in KB.
    'verification' => ['max_file_kb' => (int) env('FREELANCE_VERIFICATION_MAX_FILE_KB', 8192)],

    // The terms the freelancer must accept; raise it when the terms change and everyone accepts again.
    'terms_version' => env('FREELANCE_TERMS_VERSION', '1'),

    /*
    | FL-708: warnings, limits and suspensions — never automatic, always a person with a reason, and always open to an appeal that
    | another person decides. Days are the defaults proposed to the owner (2026-10-26); change them here.
    */
    'sanctions' => [
        'warning_days' => 90,      // a warning stays on the account this long (no limit, only a notice)
        'limit_max_days' => 30,    // a chat / work limit lasts 1..this many days
        'suspension_max_days' => 365, // a suspension may have an end, or none (until lifted)
        'appeal_days' => 14,       // the days to appeal after it starts
    ],

    /*
    | FL-707: the internal quality score (0-100) and risk level, for staff only. They never act on an account by themselves.
    | Weights are shares of the score (the parts that have no data yet are left out and the others scaled up);
    | penalties are points off for each case in `window_days`.
    */
    'quality' => [
        'window_days' => 90,
        'weights' => ['rating' => 40, 'on_time' => 30, 'completion' => 30],
        'penalties' => ['dispute_lost' => 10, 'report_upheld' => 5, 'sanction' => 10],
        // What raises the level; the level is the highest any rule reaches, and every rule that matched is shown as a reason.
        'risk' => [
            'high' => ['reports_upheld' => 3, 'disputes_lost' => 2, 'suspensions' => 1],
            'medium' => ['reports_upheld' => 1, 'disputes_lost' => 1, 'sanctions' => 1, 'cancel_rate' => 30, 'cancel_min' => 3],
        ],
    ],

    /*
    | FL-L01: freelancer plans. What each plan gives, and the default price of the paid ones. The seeder turns `usd` into every country's own
    | currency (by the currencies' exchange rates, rounded to a friendly number) and writes only the prices that are missing - an admin's
    | price is never overwritten. Yearly = 12 months less `plan_yearly_discount_bp`. Free (rank 0) keeps every number null: the policy rules.
    */
    'plans' => [
        'free' => ['rank' => 0, 'name' => ['ar' => 'مجانية', 'en' => 'Free'], 'description' => ['ar' => 'ابدأ وقدّم عروضك بالحصة الشهرية المجانية.', 'en' => 'Start and send offers with the free monthly quota.']],
        'plus' => [
            'rank' => 1, 'name' => ['ar' => 'بلس', 'en' => 'Plus'],
            'description' => ['ar' => 'عروض أكتر وعمولة أقل للمحترف النشيط.', 'en' => 'More offers and a lower commission for an active freelancer.'],
            'offers_monthly' => 40, 'commission_bp' => 800, 'trial_days' => 14, 'usd' => 9,
            'badge' => true, 'boost' => 1, 'analytics' => 1, 'services_max' => 15,
        ],
        'pro' => [
            'rank' => 2, 'name' => ['ar' => 'برو', 'en' => 'Pro'],
            'description' => ['ar' => 'عروض بلا حد وأقل عمولة للمحترف المتفرّغ.', 'en' => 'Unlimited offers and the lowest commission for a full-time freelancer.'],
            'offers_unlimited' => true, 'commission_bp' => 600, 'trial_days' => 14, 'usd' => 24,
            'badge' => true, 'boost' => 2, 'analytics' => 2, 'services_max' => 30,
        ],
        /*
        | FS-211: Team Starter, a plan a freelancers' team's Owner takes for the team (`audience` team), paid from his own wallet (D7). Priced per
        | team - one price whatever the number of people - and it opens room: the monthly offers, the people and the projects running at once.
        | These numbers are only where the admin starts (they can all be changed on the plans desk). It is seeded NOT offered (`status` false):
        | the limits it raises are the policies `teams.members_max`, `teams.projects_max` and `teams.offers_monthly`, which are open (no cap)
        | until the admin sets them, so the plan would sell nothing before that. The admin turns it on once those are set.
        */
        'team_starter' => [
            'audience' => 'team', 'rank' => 1, 'name' => ['ar' => 'ستارتر للفرق', 'en' => 'Team Starter'],
            'description' => ['ar' => 'عروض ومشاريع وأعضاء أكتر لفريقك بسعر واحد للفريق كله.', 'en' => 'More offers, projects and people for your team, at one price for the whole team.'],
            'offers_monthly' => 60, 'team_members_max' => 10, 'team_projects_max' => 5, 'trial_days' => 0, 'usd' => 29, 'status' => false,
        ],
    ],
    /*
    | FL-L01 phase 4: the performance numbers a plan opens (`plans.*.analytics`): the days each level can look back over, and how many
    | specialties the full level lists. `boost` (0-3) is a plan's weight in the «recommended» order; the badge and the boost go only with a
    | subscription that gives its plan right now, and never with a work limit or a suspension.
    */
    // The freelancer app's home (GET home): how many promo banners and running projects it shows; the client's hub
    // (mobile/v1/freelance/home): how many running projects and ready-made services.
    'home' => ['promos_max' => 6, 'work_max' => 3, 'client_projects_max' => 3, 'client_services_max' => 8],

    'insights' => ['days' => [1 => [30], 2 => [30, 90, 365]], 'top_specialties' => 5],
    /*
    | FL-L11: visits to a profile and to a ready-made service are counted per day, never per person; the same client opening the same
    | thing again within `dedupe_minutes` counts once (0 = count every open).
    */
    'profile_views' => ['dedupe_minutes' => 10],
    // Capacity and the waiting list (FL-L10): the highest cap a freelancer may choose, and how long a freed place is kept for the first in line.
    'capacity' => ['max_choice' => 20, 'hold_hours' => 24],
    // Monthly service subscriptions (FL-L05): a month's length; the client confirms the next one from `renew_window_days` before it ends
    // until `grace_days` after; ending it asked `cancel_notice_days` or more before the month ends lands on that month's end, else the next.
    'subscriptions' => ['period_days' => 30, 'renew_window_days' => 3, 'grace_days' => 2, 'cancel_notice_days' => 7],
    // Skill tests (FL-L08): a badge is valid for this many days after the pass (0 = never ends); a test with no setting of its own uses `defaults`.
    'skill_tests' => ['badge_valid_days' => 365, 'defaults' => ['questions_count' => 10, 'minutes' => 15, 'pass_percent' => 70, 'retake_hours' => 24]],
    'plan_boost_max' => 3,
    'plan_yearly_discount_bp' => 2000, // 20% off the 12 months
    'plan_grace_days' => 3,   // a failed renewal keeps the plan this many days (one retry a day), then it ends
    'plan_remind_days' => 3,  // the renewal reminder goes this many days before
    // Currencies whose stored rate is only a placeholder (0.01): units of the currency per 1 USD.
    'plan_usd_rates' => ['JPY' => 150, 'KWD' => 0.31, 'BHD' => 0.38, 'OMR' => 0.385, 'JOD' => 0.71],

    /*
    | Extra offers for sale (FL-L01, phase 2): packs a freelancer buys when the month's free offers are used up. `FreelanceOfferPackDefaults`
    | prices each pack in every country from `usd` (never overwriting an admin's price). A pack costs a little more per offer than Plus does,
    | so the plan stays the better deal. Bought offers are spent after the free ones and end after `offer_credit_valid_days` (0 = never).
    */
    'offer_packs' => [
        'small' => ['sort_order' => 1, 'offers' => 10, 'usd' => 3, 'name' => ['ar' => 'حزمة صغيرة', 'en' => 'Small pack']],
        'medium' => ['sort_order' => 2, 'offers' => 25, 'usd' => 6, 'name' => ['ar' => 'حزمة متوسطة', 'en' => 'Medium pack']],
        'large' => ['sort_order' => 3, 'offers' => 50, 'usd' => 12, 'name' => ['ar' => 'حزمة كبيرة', 'en' => 'Large pack']],
    ],
    'offer_credit_valid_days' => 180,
];
