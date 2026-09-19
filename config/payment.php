<?php

use App\Payments\FakePaymentGateway;
use App\Payments\PaymobGateway;

return [
    'driver' => env('PAYMENT_DRIVER', 'fake'),
    'drivers' => [
        'fake' => FakePaymentGateway::class,
        'paymob' => PaymobGateway::class,
    ],
    'currency' => env('PAYMENT_CURRENCY', 'EGP'),
    'minor_units' => (int) env('PAYMENT_MINOR_UNITS', 2),
    'fake_signature_secret' => env('PAYMENT_FAKE_SIGNATURE_SECRET', 'local-fake-payment-secret'),
    'providers' => [
        'paymob' => [
            'api_key' => env('PAYMOB_API_KEY', ''),
            'integration_id' => env('PAYMOB_INTEGRATION_ID', ''),
            'hmac_secret' => env('PAYMOB_HMAC_SECRET', ''),
            'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com'),
            'public_key' => env('PAYMOB_PUBLIC_KEY', ''),
            'secret_key' => env('PAYMOB_SECRET_KEY', ''),
        ],
    ],
];
