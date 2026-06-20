<?php

namespace App\Services\Gateways;

use App\Contracts\DirectCardGateway;
use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlutterwaveGateway implements PaymentGateway, DirectCardGateway
{
    public function getKey(): string
    {
        return 'flutterwave';
    }

    public function getName(): string
    {
        return 'Flutterwave';
    }

    public function getCheckoutType(): string
    {
        return 'inline';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        $txRef = 'TXN-'.$transaction->id.'-'.Str::random(8);

        $transaction->update(['gateway_payment_id' => $txRef]);

        Log::info('Flutterwave: card form initiated', [
            'transaction_id' => $transaction->id,
            'tx_ref'         => $txRef,
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
        $raw = $this->performCharge($cardData, $transaction);

        return $this->normalizeChargeResponse($raw);
    }

    /** @return array{status: string, card_ref?: string, gateway_txn_id?: int|string, message?: string} */
    public function submitPin(string $reference, string $pin, Transaction $transaction, array $cardData = []): array
    {
        $raw = $this->performCharge($cardData, $transaction, ['mode' => 'pin', 'pin' => $pin]);

        return $this->normalizeChargeResponse($raw);
    }

    /** @return array{status: string, gateway_txn_id?: int|string, message?: string} */
    public function submitOtp(string $reference, string $otp): array
    {
        $response = Http::withToken(config('flutterwave.secret_key'))
            ->post(config('flutterwave.api_url').'/validate-charge', [
                'otp'     => $otp,
                'flw_ref' => $reference,
            ]);

        Log::info('Flutterwave: OTP validation', [
            'flw_ref'     => $reference,
            'http_status' => $response->status(),
            'body'        => $response->json(),
        ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => 'OTP validation failed.'];
        }

        $body = $response->json() ?? [];
        $dataStatus = $body['data']['status'] ?? '';

        if ($dataStatus === 'successful') {
            return ['status' => 'success', 'gateway_txn_id' => $body['data']['id'] ?? 0];
        }

        return ['status' => 'error', 'message' => $body['message'] ?? 'OTP validation failed.'];
    }

    /** @return array{status: string, tx_ref: string, amount: float, currency: string, id: int|string}|null */
    public function verifyTransaction(int|string $transactionId): ?array
    {
        $response = Http::withToken(config('flutterwave.secret_key'))
            ->get(config('flutterwave.api_url')."/transactions/{$transactionId}/verify");

        Log::info('Flutterwave: verify transaction', [
            'flw_transaction_id' => $transactionId,
            'status'             => $response->status(),
            'body'               => $response->body(),
        ]);

        if ($response->failed() || $response->json('status') !== 'success') {
            return null;
        }

        $data = $response->json('data');

        if (! $data) {
            return null;
        }

        return [
            'status'   => $data['status'] ?? '',
            'tx_ref'   => $data['tx_ref'] ?? '',
            'amount'   => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper($data['currency'] ?? ''),
            'id'       => $data['id'] ?? 0,
        ];
    }

    /** @return array<string, mixed> */
    private function performCharge(array $cardData, Transaction $transaction, array $authorization = []): array
    {
        $payload = [
            'card_number'  => preg_replace('/\D/', '', $cardData['number']),
            'cvv'          => $cardData['cvv'],
            'expiry_month' => str_pad($cardData['expiry_month'], 2, '0', STR_PAD_LEFT),
            'expiry_year'  => substr($cardData['expiry_year'], -2),
            'currency'     => 'ZAR',
            'amount'       => number_format((float) $transaction->amount, 2, '.', ''),
            'fullname'     => $cardData['name'],
            'email'        => $transaction->customer_email,
            'phone_number' => preg_replace('/\D/', '', $transaction->customer_phone),
            'tx_ref'       => $transaction->gateway_payment_id,
            'redirect_url' => rtrim(config('app.url'), '/').'/flutterwave/callback?txn='.$transaction->id,
        ];

        if (! empty($authorization)) {
            $payload['authorization'] = $authorization;
        }

        $response = Http::withToken(config('flutterwave.secret_key'))
            ->post(config('flutterwave.api_url').'/charges?type=card', [
                'client' => $this->encryptPayload($payload),
            ]);

        Log::info('Flutterwave: card charge response', [
            'transaction_id' => $transaction->id,
            'http_status'    => $response->status(),
            'body'           => $response->json(),
        ]);

        if ($response->failed()) {
            return ['status' => 'error', 'message' => 'Payment request failed. Please try again.'];
        }

        return $response->json() ?? [];
    }

    /** @return array{status: string, card_ref?: string, gateway_txn_id?: int|string, redirect_url?: string, message?: string} */
    private function normalizeChargeResponse(array $raw): array
    {
        if (($raw['status'] ?? '') === 'error') {
            return $raw;
        }

        $dataStatus = $raw['data']['status'] ?? '';
        $authMode   = $raw['meta']['authorization']['mode'] ?? '';

        if ($dataStatus === 'successful') {
            return ['status' => 'success', 'gateway_txn_id' => $raw['data']['id'] ?? 0];
        }

        if ($authMode === 'pin') {
            return ['status' => 'send_pin', 'card_ref' => ''];
        }

        if ($authMode === 'otp') {
            return [
                'status'   => 'send_otp',
                'card_ref' => $raw['data']['flw_ref'] ?? '',
                'message'  => $raw['meta']['authorization']['validate_instructions']
                    ?? $raw['message']
                    ?? 'Enter the OTP sent to your registered phone or email.',
            ];
        }

        if ($authMode === 'redirect') {
            return [
                'status'       => 'redirect',
                'redirect_url' => $raw['meta']['authorization']['redirect'] ?? '',
            ];
        }

        return [
            'status'  => 'error',
            'message' => $raw['message'] ?? ($raw['data']['processor_response'] ?? 'Payment failed. Please check your card details and try again.'),
        ];
    }

    private function encryptPayload(array $payload): string
    {
        $key  = config('flutterwave.encryption_key');
        $data = json_encode($payload);

        $blockSize = 8;
        $padLen    = $blockSize - (strlen($data) % $blockSize);
        $data      .= str_repeat(chr($padLen), $padLen);

        $encrypted = openssl_encrypt($data, 'DES-EDE3', $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);

        return base64_encode($encrypted);
    }
}
