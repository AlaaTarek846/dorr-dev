<?php

return [
    'names' => [
        'football' => 'Football',
        'basketball' => 'Basketball',
        'volleyball' => 'Volleyball',
        'handball' => 'Handball',
        'hockey' => 'Hockey',
        'rugby' => 'Rugby',
        'formula1' => 'Formula 1',
        'mma' => 'MMA',
        'baseball' => 'Baseball',
    ],

    'errors' => [
        'off' => 'DORR Sports isn\'t available here yet.',
        'not_found' => 'Not found.',
        'pending' => 'On its way: try again in a little while.',
        'too_many_follows' => 'You can follow up to :max teams and competitions.',
        'provider' => 'Couldn\'t reach the sports data provider. Try again in a moment.',
        'budget' => 'Today\'s request budget is used up.',
        'predictions_off' => 'Predictions are off for now.',
        'prediction_locked' => 'Predictions close at kick-off.',
        'prediction_invalid' => 'Pick a winner or a score.',
        'rating_not_yet' => 'You can rate it after the final whistle.',
        'room_needs_members' => 'Pick at least one person.',
        'contest_not_open' => 'This contest isn\'t open.',
        'contest_not_over' => 'Its matches aren\'t over yet.',
        'contest_locked' => 'This contest can\'t be changed now.',
        'prize_unpayable' => 'This prize can\'t be paid.',
        'prize_already_paid' => 'Already paid.',
    ],

    'push' => [
        'reminder' => 'Kick-off in :minutes min',
        'kickoff' => 'Kick-off! The match has started',
        'goal' => 'GOAL! :score',
        'goal_by' => 'GOAL :team! :scorer :minute\' — :score',
        'red_card' => 'Red card: :scorer :minute\'',
        'half_time' => 'Half time: :score',
        'finished' => 'Full time: :score',
        'postponed' => 'Postponed',
        'cancelled' => 'Cancelled',
        'suspended' => 'Suspended',
        'time_changed' => 'New kick-off time: :time',
        'lineups' => 'Line-ups are out',
        'race_start' => 'Lights out — the race is on!',
        'race_finished' => 'Chequered flag — :winner wins',
        'digest_title' => 'While you were away',
        'ft_short' => 'FT',
        'hidden' => 'There\'s an update — open to see it',
    ],

    'poll' => [
        'question' => 'Who wins?',
        'draw' => 'Draw',
    ],

    'contest' => [
        'default_name' => 'DORR prediction contest',
        'won_title' => 'You won!',
        'won_wallet' => 'Your prize for ":title" is in your wallet — spend it in the app.',
        'won_coupon' => 'Your prize for ":title": a coupon is waiting in your wallet.',
        'won_badge' => 'You won ":title"!',
    ],
];
