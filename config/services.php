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
        'region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],

    'siigo' => [
        'username' => env('SIIGO_USERNAME'),
        'access_key' => env('SIIGO_ACCESS_KEY'),
        'partner_id' => env('SIIGO_PARTNER_ID', 'SistemaDeposito'),
        'pause_ms' => env('SIIGO_PAUSE_MS', 1000),
        'auto_sync' => (bool) env('SIIGO_AUTO_SYNC', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
