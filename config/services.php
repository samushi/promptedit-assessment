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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        // One API key + webhook signing secret per environment (local, deployed).
        'api_key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),
        'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),
        // Recurring pixelforge:reconcile sweep, as a cron expression ("false" turns it
        // off). Unset: every minute outside production, off in production.
        'reconcile_schedule' => env('PIXELFORGE_RECONCILE_SCHEDULE'),
        // Abuse guards for a public demo: generations per UTC day (0 = no limit).
        'limits' => [
            'per_user_per_day' => (int) env('PIXELFORGE_USER_DAILY_LIMIT', 30),
            'per_day' => (int) env('PIXELFORGE_DAILY_LIMIT', 200),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
