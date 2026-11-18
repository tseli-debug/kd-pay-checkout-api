<?php

return [
    'checkout_url' => env('KD_PAY_CHECKOUT_URL', 'http://localhost:3000'),
    'token_lifetime_minutes' => 60,
    'currency' => 'LSL',
    'mpesa' => [
        'enabled' => env('MPESA_ENABLED', false),
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        'api_key' => env('MPESA_API_KEY'),
        'public_key' => env('MPESA_PUBLIC_KEY'),
        'service_provider_code' => env('MPESA_SERVICE_PROVIDER_CODE'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
    ],
    'providers' => [
        'mpesa' => [
            'name' => 'M-Pesa',
            'enabled' => env('MPESA_ENABLED', false),
        ],
        'ecocash' => [
            'name' => 'EcoCash',
            'enabled' => false,
        ],
    ],
];
