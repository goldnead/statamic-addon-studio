<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    /*
     * Rybbit — self-hosted, cookieless reach measurement.
     *
     * Read by the layout, which renders the snippet only when `site_id` is
     * set. Unset locally on purpose: development clicks would otherwise land
     * in the public demo's statistics, which is the same statistic the
     * Insights addon shows in the Control Panel.
     */
    'rybbit' => [
        'host' => env('RYBBIT_HOST', 'https://tr.adriangoldner.com'),
        'site_id' => env('RYBBIT_SITE_ID'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
