<?php

return [
    'integration_key' => env('PESEPAY_INTEGRATION_KEY'),
    'encryption_key' => env('PESEPAY_ENCRYPTION_KEY'),
    'sandbox' => env('PESEPAY_SANDBOX', true),
    'currency_code' => env('PESEPAY_CURRENCY_CODE', 'USD'),
];
