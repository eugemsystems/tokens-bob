<?php

return [
    'sandbox'       => (bool) env('PEACH_SANDBOX', true),
    'entity_id'     => env('PEACH_ENTITY_ID', ''),
    'secret_token'  => env('PEACH_SECRET_TOKEN', ''),
    'client_id'     => env('PEACH_CLIENT_ID', ''),
    'client_secret' => env('PEACH_CLIENT_SECRET', ''),
    'merchant_id'   => env('PEACH_MERCHANT_ID', ''),

    'base_url' => env('PEACH_SANDBOX', true)
        ? 'https://testsecure.peachpayments.com'
        : 'https://secure.peachpayments.com',

    'auth_url' => env('PEACH_SANDBOX', true)
        ? 'https://sandbox-dashboard.peachpayments.com'
        : 'https://dashboard.peachpayments.com',
];
