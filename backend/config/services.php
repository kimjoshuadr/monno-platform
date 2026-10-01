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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Canadian platform (Optional)
        'ca_secret_key' => env('STRIPE_CA_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ca_public_key' => env('STRIPE_CA_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ca_webhook_secret' => env('STRIPE_CA_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Irish platform (Optional)
        'ie_secret_key' => env('STRIPE_IE_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ie_public_key' => env('STRIPE_IE_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ie_webhook_secret' => env('STRIPE_IE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Primary platform for new organizers
        'primary_platform' => env('STRIPE_PRIMARY_PLATFORM'),
    ],
    /*
    | PayRam (crypto payments, self-hosted at pay.monno.io)
    |
    | The API key is the per-project key from the PayRam dashboard; it is also
    | the HMAC key PayRam signs webhook bodies with (X-Payram-Signature).
    */
    'payram' => [
        'base_url' => rtrim(env('PAYRAM_BASE_URL', 'https://pay.monno.io'), '/'),
        'api_key' => env('PAYRAM_API_KEY'),
        'webhook_secret' => env('PAYRAM_WEBHOOK_SECRET', env('PAYRAM_API_KEY')),
        'enabled' => (bool) env('PAYRAM_ENABLED', true),
        'timeout' => (int) env('PAYRAM_TIMEOUT', 20),

        // Operator markup PayRam collects on-chain at sweep time (bps).
        // Must match the fee configured for this chain in the PayRam dashboard,
        // because we gross the buyer's amount up by it so the organizer nets
        // their sticker price.
        'fee_bps' => (int) env('PAYRAM_FEE_BPS', 250),
    ],
    'open_exchange_rates' => [
        'app_id' => env('OPEN_EXCHANGE_RATES_APP_ID'),
    ],
    'geo' => [
        'provider' => env('GEO_PROVIDER', 'google'),
        'google' => [
            'api_key' => env('GOOGLE_MAPS_API_KEY'),
        ],
    ],
];
