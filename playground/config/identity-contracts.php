<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resolve from the auth guard
    |--------------------------------------------------------------------------
    |
    | When enabled, IdentityContext::current() falls back to the authenticated
    | user of the default guard. Turn this off in headless applications that
    | always pass the actor explicitly (API hubs, import pipelines).
    |
    */

    'resolve_from_auth' => env('IDENTITY_RESOLVE_FROM_AUTH', true),

    /*
    |--------------------------------------------------------------------------
    | System actor id
    |--------------------------------------------------------------------------
    |
    | Written as the actor for anything happening without a human: schedulers,
    | webhooks, queue workers, imports.
    |
    */

    'system_id' => env('IDENTITY_SYSTEM_ID', 'system'),

    /*
    |--------------------------------------------------------------------------
    | Anonymous visitor id
    |--------------------------------------------------------------------------
    |
    | Pseudonymous id for activity that happens before someone identifies
    | themselves. `persist` stores a uuid in the existing session; with it
    | disabled a one-way hash of the session id is used and nothing is written.
    | Set `enabled` to false to never emit an anonymous id at all.
    |
    */

    'anonymous' => [
        'enabled' => env('IDENTITY_ANONYMOUS_ENABLED', true),
        'persist' => env('IDENTITY_ANONYMOUS_PERSIST', true),
        'session_key' => 'identity_anonymous_id',
    ],

];
