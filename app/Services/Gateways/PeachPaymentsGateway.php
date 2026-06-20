<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PeachPaymentsGateway implements PaymentGateway
{
    public function getKey(): string
    {
        return 'peach';
    }

    public function getName(): string
    {
        return 'Peach Payments';
    }

    public function getCheckoutType(): string
    {
        return 'redirect';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return ['success' => false, 'checkout_type' => 'redirect', 'data' => [], 'message' => 'Failed to authenticate with Peach Payments.'];
        }

        $redirectUrl = $this->createCheckout($transaction, $customerData, $token);

        if (! $redirectUrl) {
            return ['success' => false, 'checkout_type' => 'redirect', 'data' => [], 'message' => 'Failed to create Peach Payments checkout.'];
        }

        return [
            'success'       => true,
            'checkout_type' => 'redirect',
            'data'          => ['redirect_url' => $redirectUrl],
            'message'       => '',
        ];
    }

    public function getAccessToken(): ?string
    {
        $response = Http::post(config('peachpayments.auth_url').'/api/oauth/token', [
            'clientId'     => config('peachpayments.client_id'),
            'clientSecret' => config('peachpayments.client_secret'),
            'merchantId'   => config('peachpayments.merchant_id'),
        ]);

        Log::info('PeachPayments: auth response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('token') ?? $response->json('access_token');
    }

    private function createCheckout(Transaction $transaction, array $customerData, string $token): ?string
    {
        $payload = [
            'authentication'        => ['entityId' => config('peachpayments.entity_id')],
            'amount'                => number_format((float) $transaction->amount, 2, '.', ''),
            'currency'              => 'ZAR',
            'nonce'                 => Str::uuid()->toString(),
            'merchantTransactionId' => 'TXN-'.$transaction->id,
            'shopperResultUrl'      => rtrim(config('app.url'), '/').'/peach/return',
            'cancelUrl'             => rtrim(config('app.url'), '/').'/peach/cancel',
            'customer'              => ['email' => $customerData['email']],
        ];

        Log::info('PeachPayments: creating checkout', [
            'transaction_id' => $transaction->id,
            'amount'         => $payload['amount'],
        ]);

        $response = Http::withToken($token)
            ->post(config('peachpayments.base_url').'/v2/checkout', $payload);

        Log::info('PeachPayments: checkout response', [
            'transaction_id' => $transaction->id,
            'status'         => $response->status(),
            'body'           => $response->body(),
        ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('redirectUrl');
    }

    public function queryStatus(string $checkoutId): ?array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return null;
        }

        $response = Http::withToken($token)
            ->get(config('peachpayments.base_url')."/v2/checkout/{$checkoutId}/status");

        Log::info('PeachPayments: status query', [
            'checkout_id' => $checkoutId,
            'status'      => $response->status(),
            'body'        => $response->body(),
        ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json();
    }

    public function isSuccessCode(string $code): bool
    {
        return (bool) preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[1][12]0)/', $code);
    }
}
