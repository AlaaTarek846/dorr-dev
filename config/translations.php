<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base & source locales
    |--------------------------------------------------------------------------
    |
    | `base_locale` is the reference every uploaded translation is compared to
    | and the runtime fallback (keep it equal to APP_FALLBACK_LOCALE).
    | `source_locales` live in the codebase (lang/, resources/js/locales,
    | Android res/values*) and are never managed from the dashboard.
    |
    */

    'base_locale' => 'en',

    'reference_locale' => 'ar',

    'source_locales' => ['ar', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Translation files are Spatie Media Library items on this disk. It must be
    | a private disk — translation JSON is never served directly.
    |
    */

    'disk' => env('TRANSLATIONS_DISK', 'local'),

    'max_upload_kb' => (int) env('TRANSLATIONS_MAX_UPLOAD_KB', 2048),

    /*
    |--------------------------------------------------------------------------
    | Base sources
    |--------------------------------------------------------------------------
    */

    'vue_locales_path' => resource_path('js/locales'),

    'android_res_path' => env('TRANSLATIONS_ANDROID_RES_PATH', base_path('androidApp/app/src/main/res')),

];
