<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Off means nothing is written and no producer fires. Useful for staging
    | copies of production data and for cutting a ledger over gradually.
    |
    */

    'enabled' => env('ACTIVITY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Source label
    |--------------------------------------------------------------------------
    |
    | Which system recorded the fact. Meaningful once more than one application
    | writes into the same ledger.
    |
    */

    'source' => env('ACTIVITY_SOURCE', env('APP_NAME')),

    /*
    |--------------------------------------------------------------------------
    | Queued writes
    |--------------------------------------------------------------------------
    |
    | When enabled, producers defer the insert to a queue job. The actor and
    | request context are always captured at dispatch time, never in the worker.
    |
    */

    'queue' => [
        'enabled' => env('ACTIVITY_QUEUE', false),
        'connection' => env('ACTIVITY_QUEUE_CONNECTION'),
        'queue' => env('ACTIVITY_QUEUE_NAME'),
        'unique_for' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Request context capture
    |--------------------------------------------------------------------------
    |
    | Each field is switchable because each carries a different privacy weight.
    | The user agent is only ever stored as a coarse category, never raw. IP
    | addresses are never captured — derive a country upstream and pass it in
    | explicitly if you need one.
    |
    */

    'context' => [
        'capture' => env('ACTIVITY_CAPTURE_CONTEXT', true),
        'utm' => true,
        'referrer' => true,
        'page_url' => true,
        'user_agent_category' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitizer
    |--------------------------------------------------------------------------
    |
    | Runs on every write. `strip_keys` are matched as substrings against
    | property and context keys at any depth. `blocked_event_types` are dropped
    | entirely — the enforcement point for "this domain never mirrors into a
    | central event store".
    |
    */

    'sanitizer' => [
        'strip_keys' => [
            'password', 'token', 'secret', 'authorization', 'api_key', 'apikey',
            'credit_card', 'card_number', 'cvv', 'iban', 'bic',
        ],
        'blocked_event_types' => [],
        'max_payload_bytes' => 60000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | `days` null keeps everything. `anonymize_after_days` strips the personal
    | fields while keeping the countable fact — usually what you want instead of
    | deletion. Per-event-type overrides win over the global value.
    |
    */

    'retention' => [
        'days' => env('ACTIVITY_RETENTION_DAYS'),
        'anonymize_after_days' => env('ACTIVITY_ANONYMIZE_AFTER_DAYS'),
        'per_event_type' => [
            // 'marketing.email_opened' => 90,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundled producers
    |--------------------------------------------------------------------------
    |
    | Only register when the sibling addon is actually installed. Your own events
    | go through Activity::registerProducer() instead.
    |
    */

    'producers' => [
        'marketing' => env('ACTIVITY_PRODUCER_MARKETING', true),
        'leadhub' => env('ACTIVITY_PRODUCER_LEADHUB', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | A read-only inspector: filter and read raw facts. It deliberately shows no
    | metrics, charts or aggregates — read models belong in a separate addon.
    |
    */

    'cp' => [
        'enabled' => env('ACTIVITY_CP', true),
        'per_page' => 50,
    ],

];
