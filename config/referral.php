<?php

use Modules\Provider\Models\Provider;
use Modules\User\Models\User;

return [
    /*
    | Who may own a referral code. Aliases are stored in *_type columns — never
    | a class name, and never Relation::morphMap() (see App\Support\Referral\ReferrableType).
    | Driver is added here when that module exists; no schema change.
    */
    'morph_map' => [
        'user' => User::class,
        'provider' => Provider::class,
    ],

    /*
    | Who the mobile app may mint a code for and attach as the referred party today.
    | Provider codes can still be generated in tests / later APIs.
    */
    'enabled' => ['user'],

    'prefix' => 'DORRFC-',
    'suffix_length' => 6,
];
