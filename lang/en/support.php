<?php

/**
 * Support automatic replies — the defaults used until the admin writes their own texts
 * (Dashboard → Support settings). Placeholders: :id (ticket number), :hours (working hours).
 */
return [
    'auto_ack' => 'Thank you for contacting Dorr support. We received your ticket #:id and our team usually replies within a few hours. You can add more details or photos here meanwhile.',
    'auto_away' => 'Our support team is offline right now. Working hours: :hours. We will reply to ticket #:id as soon as we are back.',

    // Sunday first, like Carbon's dayOfWeek.
    'days' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
];
