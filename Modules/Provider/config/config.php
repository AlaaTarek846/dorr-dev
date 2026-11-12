<?php

return [
    'name' => 'Provider',

    // A branch saved without an area served reaches requests this far (km) — without one it would reach none.
    'default_radius_km' => 100,

    /*
    | What a member of an establishment may see and do (docs/moving_business.md §17). The owner holds every key and
    | nobody can take one from them; a member can only hand out keys they hold. The server checks each key.
    */
    'permissions' => [
        'requests' => ['requests.view', 'offers.send', 'offers.manage', 'offers.approve', 'requests.questions'],
        'jobs' => ['jobs.assign', 'jobs.track', 'jobs.change_price', 'jobs.cancel'],
        'driving' => ['driving.work', 'driving.instant'],
        'fleet' => ['fleet.vehicles', 'fleet.drivers'],
        'money' => ['wallet.view', 'wallet.withdraw'],
        'customers' => ['tickets.handle', 'ratings.view'],
        'management' => ['branches.manage', 'members.manage', 'reports.view'],
    ],

    // Ready-made sets the owner starts from when adding someone, then adds or removes keys.
    'permission_templates' => [
        'branch_manager' => [
            'requests.view', 'offers.send', 'offers.manage', 'requests.questions',
            'jobs.assign', 'jobs.track', 'jobs.change_price', 'jobs.cancel',
            'fleet.vehicles', 'fleet.drivers', 'tickets.handle', 'ratings.view', 'reports.view',
        ],
        'quoter' => ['requests.view', 'offers.send', 'offers.manage', 'requests.questions'],
        'driver' => ['driving.work'],
    ],
];
