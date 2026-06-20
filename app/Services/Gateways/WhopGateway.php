<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhopGateway implements PaymentGateway
{
    public function getKey(): string
    {
        return 'whop';
    }

    public function getName(): string
    {
        return 'Whop';
    }

    public function getCheckoutType(): string
    {
        return 'whop';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        $payload = [
            'plan' => [
                'company_id'    => config('whop.company_id'),
                'initial_price' => (float) $transaction->amount,
                'plan_type'     => 'one_time',
                'currency'      => config('whop.currency'),
            ],
            'metadata' => [
                'transaction_id' => (string) $transaction->id,
            ],
        ];

        Log::info('Whop: creating checkout configuration', [
            'transaction_id' => $transaction->id,
            'amount'         => $transaction->amount,
        ]);

        $response = Http::withToken(config('whop.api_key'))
            ->post(config('whop.api_url').'/checkout_configurations', $payload);

        Log::info('Whop: checkout configuration response', [
            'transaction_id' => $transaction->id,
            'http_status'    => $response->status(),
        ]);

        if ($response->failed()) {
            Log::error('Whop: checkout configuration request failed', [
                'transaction_id' => $transaction->id,
                'body'           => $response->body(),
            ]);

            return ['success' => false, 'checkout_type' => 'whop', 'data' => [], 'message' => 'Failed to initiate Whop payment.'];
        }

        $checkoutId = $response->json('id');
        $planId = $response->json('plan.id');

        if (! $checkoutId || ! $planId) {
            Log::error('Whop: missing checkout ID or plan ID in response', [
                'transaction_id' => $transaction->id,
                'body'           => $response->body(),
            ]);

            return ['success' => false, 'checkout_type' => 'whop', 'data' => [], 'message' => 'Failed to create Whop checkout session.'];
        }

        $transaction->update(['gateway_payment_id' => $checkoutId]);

        Log::info('Whop: checkout session created', [
            'transaction_id' => $transaction->id,
            'checkout_id'    => $checkoutId,
            'plan_id'        => $planId,
        ]);

        return [
            'success'       => true,
            'checkout_type' => 'whop',
            'data'          => [
                'checkout_id' => $checkoutId,
                'plan_id'     => $planId,
            ],
            'message' => '',
        ];
    }
}
