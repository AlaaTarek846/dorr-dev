<?php

return [
    'errors' => [
        'off' => 'DORR Discover isn\'t available here yet.',
        'not_found' => 'This event isn\'t available.',
        'travel_range' => 'Pick up to :max days.',
        'too_many_follows' => 'You can follow up to :max places.',
        'invalid_status' => 'Unknown event status.',
        'invalid_time' => 'Check the event\'s start and end time.',
        'submissions_off' => 'Adding events is closed for now.',
        'organizer_suspended' => 'Your organizer account is suspended.',
        'not_organizer' => 'Ask to become an organizer first — then you can add events.',
        'duplicate' => 'This event is already on DORR Discover.',
        'ai_off' => 'Ask DORR AI isn\'t available in Discover right now.',
        'event_over' => 'This event is over or cancelled.',
        'in_use' => 'Events use this — move or delete them first.',
        'room_needs_members' => 'Pick at least one person to go with.',
    ],

    'push' => [
        'status' => [
            'confirmed' => 'It\'s on — confirmed for :date at :time',
            'postponed' => 'Postponed — we\'ll tell you the new date',
            'moved' => 'New time: :date at :time',
            'cancelled' => 'Cancelled by the organizer',
            'sold_out' => 'Sold out',
        ],
        'review' => [
            'organizer_verified_title' => '✔ You\'re a verified organizer',
            'organizer_verified_body' => 'You can add your events to DORR Discover now.',
            'organizer_rejected_title' => 'Your organizer request was declined',
            'organizer_rejected_body' => 'Open your organizer page for the details.',
            'organizer_suspended_title' => 'Your organizer account is suspended',
            'organizer_suspended_body' => 'You can\'t add events until it\'s back.',
            'event_approved_title' => '✅ :title',
            'event_approved_body' => 'Your event is published — people can see it now.',
            'event_rejected_title' => '❌ :title',
            'event_rejected_body' => 'Your event was declined. Open it to see why.',
            'with_note' => 'From the team: :note',
        ],
        'alert_title' => '✨ Don\'t miss it',
        'alert_many' => ':title and :count more you might like',
    ],

    'poll' => [
        'question' => 'Who\'s going to ":title"?',
        'yes' => 'I\'m going',
        'maybe' => 'Maybe',
        'no' => 'Can\'t make it',
    ],
];
