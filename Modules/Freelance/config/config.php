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
    ],

    // Skill suggestions (FL-102): how many new skills one freelancer may suggest a day.
    'skills' => ['suggest_daily_limit' => (int) env('FREELANCE_SKILL_SUGGEST_DAILY_LIMIT', 5)],

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
];
