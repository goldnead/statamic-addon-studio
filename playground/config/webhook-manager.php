<?php

use Goldnead\WebhookManager\WebhookManagerServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Enable or disable major modules of the addon. Each toggle hides the
    | module's CP screens, navigation entries and runtime wiring, so you can
    | run the addon as outbound-only (or any subset) without uninstalling.
    */
    'features' => [
        'outbound' => true,
        'inbound' => true,
        'rules' => true,
        'templates' => true,
        'debug_tools' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Event Triggers
    |--------------------------------------------------------------------------
    |
    | Turn ANY Laravel/Statamic event class into a webhook trigger without
    | writing a listener. Each entry registers a generic trigger (so it shows
    | up in the CP trigger picker) and attaches ONE listener to the event that
    | re-emits the normal dispatch pipeline. The array key is used as the
    | trigger handle unless an explicit `handle` is given.
    |
    | Each entry declares:
    |   'event'       (required) FQCN of the event class to listen for.
    |   'handle'      (optional) unique trigger handle; defaults to the key.
    |   'label'       (optional) human label shown in the CP; defaults to handle.
    |   'source_type' (optional) source category, e.g. "order"; default "event".
    |   'payload'     (optional) how to map the event → array payload. A Closure,
    |                 an invokable class-string, or a [class, method] pair. When
    |                 omitted the listener serialises the event via toArray(),
    |                 else its public properties (arrays are passed through).
    |   'description' (optional) human description.
    |
    | Example:
    |   'order.shipped' => [
    |       'event'       => \App\Events\OrderShipped::class,
    |       'label'       => 'Order — shipped',
    |       'source_type' => 'order',
    |       'payload'     => \App\Webhooks\OrderShippedPayload::class,
    |   ],
    */
    'event_triggers' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Where the webhook *configuration* (outbound webhooks, inbound endpoints,
    | rules and templates) is stored. Delivery records and logs are runtime
    | telemetry and always live in the database regardless of this setting.
    |
    | - "eloquent" (default): config lives in database tables. Requires
    |   running `php artisan migrate`.
    |
    | - "flat": config lives as human-readable YAML files under
    |   content/webhooks/, true to Statamic's flat-file philosophy and
    |   git-versionable alongside the rest of your site.
    |
    | Move existing config between drivers with:
    |   php artisan webhook-manager:storage:migrate --from=eloquent --to=flat
    */
    'storage' => [
        'driver' => env('WEBHOOK_MANAGER_DRIVER', 'eloquent'),

        'flat' => [
            // Where the config YAML files live.
            'path' => env('WEBHOOK_MANAGER_FLAT_PATH', base_path('content/webhooks')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Outbound deliveries default to the queue ("queue-first") to keep the
    | CP responsive. Sync is only intended for testing.
    */
    'queue' => [
        'connection' => env('WEBHOOK_MANAGER_QUEUE_CONNECTION'),
        'name' => env('WEBHOOK_MANAGER_QUEUE_NAME', 'default'),
        'sync_in_console' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Defaults
    |--------------------------------------------------------------------------
    */
    'retry' => [
        /*
         * Put `webhook-manager:dispatch-retries` on the scheduler (every
         * minute). This is what actually RUNS the retries the RetryPlanner
         * schedules — without it a failed delivery gets a `next_retry_at`,
         * the CP shows "next retry in 30 seconds", and nothing ever happens.
         *
         * Set to false only if you drive the command yourself. The host app
         * still needs the usual `schedule:run` cron.
         */
        'schedule' => true,

        'strategy' => 'exponential', // none | linear | exponential
        'max_attempts' => 3,
        'base_delay_seconds' => 30,
        'max_delay_seconds' => 3600,
        'retry_on_status' => [408, 425, 429, 500, 502, 503, 504],
        'retry_on_network_errors' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Logging
    |--------------------------------------------------------------------------
    |
    | Controls how request/response bodies are stored. `partial` keeps the
    | first N bytes, `none` stores nothing, `full` stores everything (use
    | with caution on large payloads).
    */
    'logging' => [
        'mode' => 'partial', // full | partial | none
        'partial_bytes' => 4096,
        'mask_headers' => [
            'authorization',
            'x-api-key',
            'x-auth-token',
            'cookie',
            'set-cookie',
        ],
        'mask_payload_keys' => [
            'password',
            'secret',
            'token',
            'api_key',
            'apikey',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Runs via the `webhook-manager:prune` console command, ideally in the
    | scheduler. Set to 0 to disable.
    */
    'pruning' => [
        'deliveries_after_days' => 30,
        'logs_after_days' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */
    'inbound' => [
        /*
         * Public prefix of the inbound endpoint. The CP renders endpoint URLs
         * from this value, so what an operator copies out of the CP is what is
         * actually routed.
         */
        'route_prefix' => WebhookManagerServiceProvider::DEFAULT_INBOUND_PREFIX,

        /*
         * Prefixes that stay routable for senders configured against an older
         * release. Set to [] once no sender uses the old URL any more.
         */
        'legacy_route_prefixes' => [
            WebhookManagerServiceProvider::LEGACY_INBOUND_PREFIX,
        ],

        /*
         * The COMPLETE middleware stack of the inbound endpoint — not a list
         * appended to `web`. Adding 'web' here re-introduces CSRF validation
         * and every external delivery starts failing with 419 again.
         */
        'middleware' => WebhookManagerServiceProvider::DEFAULT_INBOUND_MIDDLEWARE,

        'max_payload_kb' => 512,

        /*
         * Requests per minute PER ENDPOINT, enforced as the first step of
         * InboundRequestProcessor — before the method allowlist, before auth,
         * before anything that costs work. Exceeding it answers 429 with
         * Retry-After; every other response carries X-RateLimit-Limit and
         * X-RateLimit-Remaining so a well-behaved sender can back off first.
         *
         * The counter is keyed by endpoint id, so the legacy `!/` prefix shares
         * the bucket with the canonical URL and cannot be used to route around
         * the limit. A single endpoint can override this with
         * `rate_limit_config: {"per_minute": N}`.
         *
         * 0 disables throttling entirely.
         */
        'rate_limit_per_minute' => 60,

        'replay_protection_ttl_seconds' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */
    'security' => [
        'hash_algorithms' => ['sha256', 'sha512'],
        'default_hash_algorithm' => 'sha256',
        'signature_header' => 'X-Webhook-Signature',
        'timestamp_header' => 'X-Webhook-Timestamp',
        'timestamp_tolerance_seconds' => 300,
        'mask_secrets_in_ui' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout_seconds' => 15,
        'connect_timeout_seconds' => 5,
        'follow_redirects' => true,
        'max_redirects' => 3,
        'user_agent' => 'Statamic-Webhook-Manager/1.0',
        'verify_ssl' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure alerts
    |--------------------------------------------------------------------------
    |
    | Notify an admin when a delivery exhausts its retries and fails for good.
    | `throttle_minutes` caps how often a single webhook may alert, so a broken
    | endpoint doesn't flood your inbox.
    */
    'alerts' => [
        'enabled' => env('WEBHOOK_MANAGER_ALERTS', true),
        'throttle_minutes' => (int) env('WEBHOOK_MANAGER_ALERT_THROTTLE', 15),
        'mail' => [
            'enabled' => env('WEBHOOK_MANAGER_ALERT_MAIL', true),
            // Comma-separated list, e.g. WEBHOOK_MANAGER_ALERT_EMAILS=a@x.com,b@x.com
            'recipients' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WEBHOOK_MANAGER_ALERT_EMAILS', ''))
            ))),
        ],
        'slack' => [
            // A Slack/Discord/Teams incoming-webhook URL to post failure alerts to.
            'webhook_url' => env('WEBHOOK_MANAGER_ALERT_SLACK_URL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Auto-disable an outbound webhook after this many consecutive terminal
    | failures, so a dead endpoint stops being hammered. The counter resets to
    | zero on the next success. Set threshold to 0 to never auto-disable.
    */
    'circuit_breaker' => [
        'enabled' => env('WEBHOOK_MANAGER_CIRCUIT_BREAKER', true),
        'threshold' => (int) env('WEBHOOK_MANAGER_CIRCUIT_THRESHOLD', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    */
    'debug' => [
        'expose_full_response_in_dev' => true,
    ],

];
