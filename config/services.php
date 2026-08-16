<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | FruitionHR's own platform credentials — tenants never supply their own,
    | since this is subscription billing rather than a marketplace. Keys live
    | in .env and are never committed.
    |
    */

    'billing' => [
        'default_gateway' => env('BILLING_DEFAULT_GATEWAY', 'paystack'),
        // Where the gateway sends the customer's browser back to. Must be a web
        // page, never this API — the gateway redirects a browser, not a client.
        'callback_url' => env('BILLING_CALLBACK_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/billing/callback'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    'nomba' => [
        'account_id' => env('NOMBA_ACCOUNT_ID'),
        'client_id' => env('NOMBA_CLIENT_ID'),
        'private_key' => env('NOMBA_PRIVATE_KEY'),
        'webhook_secret' => env('NOMBA_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
