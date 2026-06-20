<?php

namespace App\Services\Gateways;

use App\Contracts\DirectCardGateway;
use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackGateway implements PaymentGateway, DirectCardGateway
{
    public function getKey(): string
    {
        return 'paystack';
    }

    public function getName(): string
    {
        return 'Paystack';
    }

    public function getCheckoutType(): string
    {
        return 'inline';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        $reference = 'TXN-'.$transaction->id.'-'.Str::random(8);

        $transaction->update(['gateway_payment_id' => $reference]);

        Log::info('Paystack: card form initiated', [
            'transaction_id' => $transaction->id,
            'reference'      => $reference,
            'amount'         => $transaction->amount,
        ]);

        return [
            'success'       => true,
            'checkout_type' => 'inline',
            'data'          => [],
            'message'       => '',
        ];
    }

    /** @return array{status: string, card_ref?: string, gateway_txn_id?: int|string, redirect_url?: string, message?: string} */
    public function chargeCard(array $cardData, Transaction $transaction): array
    {
        $payload = [
            'email'        => $transaction->customer_email,
            'amount'       => (int) round((float) $transaction->amount * 100),
            'currency'     => 'ZAR',
            'reference'    => $transaction->gateway_payment_id,
            'callback_url' => rtrim(config('app.url'), '/').'/paystack/callback?txn='.$transaction->id,
            'card'         => [
                'number'       => preg_replace('/\D/', '', $cardData['number']),
                'cvv'          => $cardData['cvv'],
                'expiry_month' => str_pad($cardData['expiry_month'], 2, '0', STR_PAD_LEFT),
                'expiry_year'  => substr($cardData['expiry_year'], -2),
            ],
        ];

        $response = Http::withToken(config('paystack.secret_key'))
            ->post(config('paystack.api_url').'/charge', $payload);

        Log::info('Paystack: card charge response', [
            'transaction_id' => $transaction->id,
            'http_status'    => $response->status(),
            'body'           => $response->json(),
        ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => 'Payment request failed. Please try again.'];
        }

        return $this->normalizeChargeResponse($response->json() ?? []);
    }

    /** @return array{status: string, card_ref?: string, gateway_txn_id?: int|string, message?: string} */
    public function submitPin(string $reference, string $pin, Transaction $transaction, array $cardData = []): array
    {
        $response = Http::withToken(config('paystack.secret_key'))
            ->post(config('paystack.api_url').'/charge/submit_pin', [
                'pin'       => $pin,
                'reference' => $reference,
            ]);

        Log::info('Paystack: PIN submission response', [
            'reference'   => $reference,
            'http_status' => $response->status(),
            'body'        => $response->json(),
        ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => 'PIN submission failed. Please try again.'];
        }

        return $this->normalizeChargeResponse($response->json() ?? []);
    }

    /** @return array{status: string, gateway_txn_id?: int|string, message?: string} */
    public function submitOtp(string $reference, string $otp): array
    {
        $response = Http::withToken(config('paystack.secret_key'))
            ->post(config('paystack.api_url').'/charge/submit_otp', [
                'otp'       => $otp,
                'reference' => $reference,
            ]);

        Log::info('Paystack: OTP submission response', [
            'reference'   => $reference,
            'http_status' => $response->status(),
            'body'        => $response->json(),
        ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => 'OTP validation failed.'];
        }

        return $this->normalizeChargeResponse($response->json() ?? []);
    }

    /** @return array{status: string, tx_ref: string, amount: float, currency: string, id: int|string}|null */
    public function verifyTransaction(int|string $transactionId): ?array
    {
        $response = Http::withToken(config('paystack.secret_key'))
            ->get(config('paystack.api_url')."/transaction/verify/{$transactionId}");

        Log::info('Paystack: verify transaction', [
            'reference'   => $transactionId,
            'http_status' => $response->status(),
            'body'        => $response->body(),
        ]);

        if ($response->failed() || $response->json('status') !== true) {
            return null;
        }

        $data = $response->json('data');

        if (! $data) {
            return null;
        }

        return [
            'status'   => $data['status'] === 'success' ? 'successful' : $data['status'],
            'tx_ref'   => $data['reference'] ?? '',
            'amount'   => (float) ($data['amount'] ?? 0) / 100,
            'currency' => strtoupper($data['currency'] ?? ''),
            'id'       => $data['reference'] ?? '',
        ];
    }

    /** @return array{status: string, card_ref?: string, gateway_txn_id?: int|string, redirect_url?: string, message?: string} */
    private function normalizeChargeResponse(array $body): array
    {
        $data   = $body['data'] ?? [];
        $status = $data['status'] ?? '';
        $ref    = $data['reference'] ?? '';

        return match ($status) {
            'success'  => ['status' => 'success', 'gateway_txn_id' => $ref, 'card_ref' => $ref],
            'send_pin' => ['status' => 'send_pin', 'card_ref' => $ref],
            'send_otp' => [
                'status'   => 'send_otp',
                'card_ref' => $ref,
                'message'  => $data['display_text'] ?? 'Enter the OTP sent to your registered phone or email.',
            ],
            'open_url' => ['status' => 'redirect', 'redirect_url' => $data['url'] ?? ''],
            default    => [
                'status'  => 'error',
                'message' => $data['gateway_response'] ?? $body['message'] ?? 'Payment failed. Please check your card details and try again.',
            ],
        };
    }
}
