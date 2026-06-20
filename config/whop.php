<?php

return [
    'sandbox'        => env('WHOP_SANDBOX', false),
    'api_key'        => env('WHOP_API_KEY', ''),
    'company_id'     => env('WHOP_COMPANY_ID', ''),
    'webhook_secret' => env('WHOP_WEBHOOK_SECRET', ''),
    'currency'       => strtolower(env('WHOP_CURRENCY', 'usd')),
    'api_url'        => env('WHOP_SANDBOX', false)
        ? 'https://sandbox-api.whop.com/api/v1'
        : 'https://api.whop.com/api/v1',
];
