<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scope per reason (D1)
    |--------------------------------------------------------------------------
    |
    | Whether a reason blocks an address everywhere or only in the brand that
    | recorded it.
    |
    | The split below is by kind of fact, not by convenience. A hard bounce is a
    | property of the mailbox: it will bounce identically from every brand, and
    | each brand that re-learns it does so at the cost of a sending reputation
    | they share. A complaint is a property of a relationship: the recipient
    | objected to *this* sender, which says nothing about anybody else and would
    | contradict the consent-bleed rule the subscription schema already carries.
    |
    | Reversing this is a config change, never a migration. `brand_id` is stored
    | explicitly on every row, and 0 simply means "every brand" — so forcing
    | everything global means always writing 0, and forcing everything
    | brand-scoped means never writing it.
    |
    */

    'scopes' => [
        'hard_bounce' => 'global',
        'invalid_email' => 'global',
        'soft_bounce_threshold' => 'global',
        'provider_import' => 'global',
        'complaint' => 'brand',
        'manual' => 'brand',
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft bounce threshold
    |--------------------------------------------------------------------------
    |
    | Individual soft bounces are never suppressions — a full mailbox is a fact
    | about today. Only a run of them inside the window promotes an address.
    |
    | 5 in 30 days is a starting value chosen before any real data existed, and
    | it lives here rather than inline for exactly that reason: it is meant to be
    | tuned once there is something to tune it against.
    |
    */

    'soft_bounce' => [
        'threshold' => (int) env('SUPPRESSION_SOFT_BOUNCE_THRESHOLD', 5),
        'window_days' => (int) env('SUPPRESSION_SOFT_BOUNCE_WINDOW_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | The minimum length of the stated reason a deliberate complaint release must
    | carry. It exists so that "ok" does not satisfy the requirement — the point
    | of the field is that it still means something to whoever reads it a year
    | later, and a one-word entry is a checkbox wearing a text input's clothes.
    |
    */

    'release' => [
        'min_reason_length' => (int) env('SUPPRESSION_MIN_REASON_LENGTH', 20),
    ],

];
