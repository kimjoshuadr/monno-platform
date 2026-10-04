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

        // PayRam's own settlement fee (bps). It is taken on-chain at sweep time
        // by PayRam's sweep contract and passed to the buyer as a visible
        // markup, so the buyer pays ticket / (1 - bps). PayRam controls this
        // rate (capped at 500 bps), which is why the settlement record
        // reconciles this estimate against the legs actually swept.
        'settlement_fee_bps' => (int) env('PAYRAM_SETTLEMENT_FEE_BPS', env('PAYRAM_FEE_BPS', 250)),

        // Monno's operator fee (bps). It is taken on-chain by the same sweep
        // contract, but the organizer bears it — it is never grossed up onto
        // the buyer. The settlement record shows it as its own leg so the
        // organizer can see exactly what left and what arrived.
        'operator_fee_bps' => (int) env('PAYRAM_OPERATOR_FEE_BPS', env('PAYRAM_FEE_BPS', 250)),

        // The shared operator hot wallet (EVM family). Every new project is
        // attached to it at provisioning so buyer funds can sweep to the
        // organizer's cold wallet. One wallet serves every project: it only pays
        // gas, so sharing it does not commingle anyone's funds. 0 = disabled.
        'hot_wallet_id' => (int) env('PAYRAM_HOT_WALLET_ID', 0),

        // Smallest crypto invoice we will create, in USD.
        //
        // PayRam invoices a USD amount but the buyer pays in a coin it chooses,
        // and its checkout displays the coin quantity rounded to a precision we
        // can neither predict nor pin (the `currency` parameter is accepted and
        // ignored — every invoice comes back in native ETH). At tiny amounts one
        // display step is a material share of the invoice, so the buyer can send
        // exactly what they are shown and still be judged short: a $0.17 invoice
        // shown as "0.00006 ETH" arrives as ~$0.1578, PayRam marks it
        // PARTIALLY_FILLED, and a partial can never be topped up or settled.
        //
        // This floor keeps that error small without blocking small orders. It is
        // deliberately low — the shortfall is caught by the worklist and logs if
        // it still happens, rather than by refusing the sale.
        'min_invoice_usd' => (float) env('PAYRAM_MIN_INVOICE_USD', 1.00),

        // Operator account: used only to provision organizer merchant accounts
        // (project, dashboard login, role, API key). Never used for payments.
        'operator_email' => env('PAYRAM_OPERATOR_EMAIL'),
        'operator_password' => env('PAYRAM_OPERATOR_PASSWORD'),
    ],
    'open_exchange_rates' => [
        'app_id' => env('OPEN_EXCHANGE_RATES_APP_ID'),
    ],
    // Keyless fallback rate source, used when no Open Exchange Rates key is set.
    'currency' => [
        'keyless_api_url' => env('CURRENCY_KEYLESS_API_URL', 'https://open.er-api.com/v6/latest/USD'),
        'keyless_enabled' => (bool) env('CURRENCY_KEYLESS_ENABLED', true),
    ],
    'geo' => [
        'provider' => env('GEO_PROVIDER', 'google'),
        'google' => [
            'api_key' => env('GOOGLE_MAPS_API_KEY'),
        ],
    ],
];
