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
        'milestones.min_amount_minor' => null,
        'project.min_budget_minor' => null,
        'project.max_budget_minor' => null,
        'earnings.hold_days' => 0,
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
        'max_reopens' => 3,
        'max_duration_days' => 365,
        'attachment_types' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip'],
    ],

    // Offers (FL-301): how long the text must be, how many files.
    'offers' => ['details_min' => 30, 'details_max' => 3000, 'max_attachments' => 5],

    // Portfolio images are shown through links that expire after this many minutes (FL-105).
    'portfolio' => ['link_minutes' => (int) env('FREELANCE_PORTFOLIO_LINK_MINUTES', 30)],

    // Identity documents (FL-107): biggest file, in KB.
    'verification' => ['max_file_kb' => (int) env('FREELANCE_VERIFICATION_MAX_FILE_KB', 8192)],

    // The terms the freelancer must accept; raise it when the terms change and everyone accepts again.
    'terms_version' => env('FREELANCE_TERMS_VERSION', '1'),
];
