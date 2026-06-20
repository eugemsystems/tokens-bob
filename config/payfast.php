<?php

return [
    'merchant_id'  => env('PAYFAST_MERCHANT_ID', ''),
    'merchant_key' => env('PAYFAST_MERCHANT_KEY', ''),
    'passphrase'   => env('PAYFAST_PASSPHRASE', ''),
    'sandbox'      => (bool) env('PAYFAST_SANDBOX', true),

    'base_url' => env('PAYFAST_SANDBOX', true)
        ? 'https://sandbox.payfast.co.za'
        : 'https://www.payfast.co.za',

    'js_url' => env('PAYFAST_SANDBOX', true)
        ? 'https://sandbox.payfast.co.za/onsite/engine.js'
        : 'https://www.payfast.co.za/onsite/engine.js',

    // Separate from APP_URL so Livewire keeps working on the local domain
    // while PayFast can still POST the ITN to a publicly reachable address.
    'notify_url' => env('PAYFAST_NOTIFY_URL', null),
];
