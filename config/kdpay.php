<?php

return [
    'checkout_url' => env('KD_PAY_CHECKOUT_URL', 'http://localhost:3000'),
    'token_lifetime_minutes' => 60,
    'currency' => 'LSL',
    'supported_currencies' => ['LSL', 'USD'],
    'mpesa' => [
        'enabled' => env('MPESA_ENABLED', false),
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        'api_key' => env('MPESA_API_KEY'),
        'public_key' => env('MPESA_PUBLIC_KEY'),
        'service_provider_code' => env('MPESA_SERVICE_PROVIDER_CODE'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
        'callback_token' => env('MPESA_CALLBACK_TOKEN'),
    ],
    'paypal' => [
        'enabled' => env('PAYPAL_ENABLED', env('PAYMENT_PROVIDER') === 'paypal'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],
    'providers' => [
        'mpesa' => [
            'name' => 'M-Pesa',
            'enabled' => env('MPESA_ENABLED', false),
        ],
        'paypal' => [
            'name' => 'PayPal',
            'enabled' => env('PAYPAL_ENABLED', env('PAYMENT_PROVIDER') === 'paypal'),
        ],
        'ecocash' => [
            'name' => 'EcoCash',
            'enabled' => false,
        ],
    ],
];
