<?php

return [
    'company_token' => env('DPO_COMPANY_TOKEN', ''),
    'service_type'  => env('DPO_SERVICE_TYPE', ''),
    'sandbox'       => (bool) env('DPO_SANDBOX', false),

    'api_url' => env('DPO_SANDBOX', false)
        ? 'https://secure1.sandbox.directpay.online/API/v6/'
        : 'https://secure.3gdirectpay.com/API/v6/',

    'payment_url' => env('DPO_SANDBOX', false)
        ? 'https://secure1.sandbox.directpay.online/dpopayment.php'
        : 'https://secure.3gdirectpay.com/dpopayment.php',
];
