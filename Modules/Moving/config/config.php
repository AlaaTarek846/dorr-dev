<?php

return [
    'name' => 'Moving',

    /*
    | The values each country sets in the admin (docs/moving_tasks.md B-01). Every one is "waiting for the client's
    | decision" (requirements DEC-020), so none has a made-up default: null means the rule is not applied yet.
    | A request keeps the values in force when it was published (REQ-003).
    */
    'policy_keys' => [
        'offers.window_minutes',        // a published request stops taking offers after this; null = until its time
        'offers.max_validity_hours',    // the longest an offer may stay valid
        'broadcast.max_radius_km',      // the widest a branch's area may reach
        'schedule.block_hours',         // a vehicle or driver can't take two jobs closer than this (REQ-027)
        'assignment.lead_minutes',      // the job must have a driver this long before its time
        'delivery.objection_hours',     // after delivery, the customer may report within this; then the money moves
        'dispute.max_hold_bp',          // a report holds at most this share of the job (basis points, REQ-052)
        'commission.bp',                // Dorr's share of the job price (basis points)
        'commission.min_minor',
        'commission.max_minor',
        'tax.bp',                       // tax on the job price, shown apart (REQ-094)
        'media.max_mb',                 // largest photo or video a customer may upload (REQ-117)
        // Cancelling after an offer is accepted (docs/moving_business.md §15): the customer's fee by how far the job went
        // (basis points of the price, paid to the establishment) and when "near the time" starts.
        'cancel.near_hours',
        'cancel.customer.early_bp',     // confirmed, more than near_hours before the time
        'cancel.customer.near_bp',      // confirmed or assigned, within near_hours
        'cancel.customer.on_way_bp',    // the driver set out
        'cancel.customer.arrived_bp',   // the driver is at the door
        // Waiting at the door (REQ-040): free minutes after arriving, then a fee per hour up to a cap — asked, never taken.
        'waiting.grace_minutes',
        'waiting.fee_per_hour_minor',
        'waiting.cap_minor',
        // A ticket the establishment hasn't solved can go to Dorr after this (REQ-063).
        'tickets.escalate_hours',
    ],

    /*
    | Why a side cancels (§15). A reason that blames the other side or is force majeure opens an exception for the
    | admin instead of the fee table (REQ-041, REQ-042).
    */
    'cancel_reasons' => [
        'user' => ['changed_plans', 'found_another', 'other', 'driver_late', 'extra_money_asked', 'vehicle_mismatch'],
        'provider' => ['vehicle_breakdown', 'driver_unavailable', 'other', 'load_differs', 'customer_absent', 'unsafe_place'],
    ],
    'exception_reasons' => ['driver_late', 'extra_money_asked', 'vehicle_mismatch', 'vehicle_breakdown', 'load_differs', 'customer_absent', 'unsafe_place'],

    // A customer's report after delivery (§9).
    'report_reasons' => ['damage', 'missing_item', 'incomplete_service', 'other'],
];
