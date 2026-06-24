<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Contracts\SeamlessGateway;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PesepayGateway implements PaymentGateway, SeamlessGateway
{
    public function getKey(): string
    {
        return 'pesepay';
    }

    public function getName(): string
    {
        return 'PesePay';
    }

    public function getCheckoutType(): string
    {
        return 'seamless';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        Log::info('PesePay: initiating seamless checkout', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
        ]);

        return [
            'success' => true,
            'checkout_type' => 'seamless',
            'data' => ['payment_methods' => $this->paymentMethods()],
            'message' => '',
        ];
    }

    public function makePayment(Transaction $transaction, string $paymentMethodCode, string $phoneNumber): array
    {
        $body = [
            'amountDetails' => [
                'amount' => $this->toUsd((float) $transaction->amount),
                'currencyCode' => config('pesepay.currency_code', 'USD'),
            ],
            'merchantReference' => 'TXN-'.$transaction->id,
            'reasonForPayment' => 'Token purchase #'.$transaction->id,
            'resultUrl' => rtrim(config('app.url'), '/').'/pesepay/result',
            'returnUrl' => rtrim(config('app.url'), '/').'/order/'.$transaction->id,
            'paymentMethodCode' => $paymentMethodCode,
            'customer' => [
                'email' => $transaction->customer_email,
                'phoneNumber' => $transaction->customer_phone ?? '',
                'name' => '',
            ],
            'paymentMethodRequiredFields' => $paymentMethodCode === 'PZW211'
                ? ['customerPhoneNumber' => $phoneNumber]
                : new \stdClass,
        ];

        $encrypted = $this->encrypt($body);

        if ($encrypted === null) {
            Log::error('PesePay: payload encryption failed', ['transaction_id' => $transaction->id]);

            return ['success' => false, 'reference_number' => '', 'poll_url' => '', 'message' => 'Encryption failed. Please try again.'];
        }

        Log::info('PesePay: making payment', [
            'transaction_id' => $transaction->id,
            'payment_method_code' => $paymentMethodCode,
        ]);

        $response = Http::withHeaders([
            'authorization' => config('pesepay.integration_key'),
            'Content-Type' => 'application/json',
        ])->post($this->makePaymentUrl(), ['payload' => $encrypted]);

        Log::info('PesePay: make payment response', [
            'transaction_id' => $transaction->id,
            'http_status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($response->failed()) {
            return ['success' => false, 'reference_number' => '', 'poll_url' => '', 'message' => 'Payment request failed. Please try again.'];
        }

        $data = $this->decryptPayload($response->json('payload') ?? '');

        if ($data === null) {
            return ['success' => false, 'reference_number' => '', 'poll_url' => '', 'message' => 'Invalid response from payment gateway.'];
        }

        Log::info('PesePay: payment accepted', [
            'transaction_id' => $transaction->id,
            'reference_number' => $data['referenceNumber'] ?? null,
            'status' => $data['transactionStatus'] ?? null,
        ]);

        return [
            'success' => true,
            'reference_number' => $data['referenceNumber'] ?? '',
            'poll_url' => $data['pollUrl'] ?? '',
            'message' => '',
        ];
    }

    public function checkStatus(string $referenceNumber): ?array
    {
        $response = Http::withHeaders([
            'authorization' => config('pesepay.integration_key'),
            'Content-Type' => 'application/json',
        ])->get($this->baseUrl().'/payments/check-payment', [
            'referenceNumber' => $referenceNumber,
        ]);

        Log::info('PesePay: check payment status', [
            'reference_number' => $referenceNumber,
            'http_status' => $response->status(),
        ]);

        if ($response->failed()) {
            return null;
        }

        $data = $this->decryptPayload($response->json('payload') ?? '');

        if ($data === null) {
            return null;
        }

        return [
            'transaction_status' => $data['transactionStatus'] ?? '',
            'reference_number' => $data['referenceNumber'] ?? $referenceNumber,
        ];
    }

    public function paymentMethods(): array
    {
        return [
            ['code' => 'PZW211', 'name' => 'EcoCash USD', 'requires_phone' => true,  'is_card' => false],
            ['code' => 'PZW212', 'name' => 'Innbucks',    'requires_phone' => false, 'is_card' => false],
            ['code' => 'PZW204', 'name' => 'Visa',        'requires_phone' => false, 'is_card' => true],
            ['code' => 'PZW205', 'name' => 'Mastercard',  'requires_phone' => false, 'is_card' => true],
        ];
    }

    public function makeCardPayment(Transaction $transaction, string $paymentMethodCode, string $cardNumber, string $cardExpiry, string $cvv): array
    {
        $body = [
            'amountDetails' => [
                'amount' => $this->toUsd((float) $transaction->amount),
                'currencyCode' => config('pesepay.currency_code', 'USD'),
            ],
            'merchantReference' => 'TXN-'.$transaction->id,
            'reasonForPayment' => 'Token purchase #'.$transaction->id,
            'resultUrl' => rtrim(config('app.url'), '/').'/pesepay/result',
            'returnUrl' => rtrim(config('app.url'), '/').'/order/'.$transaction->id,
            'paymentMethodCode' => $paymentMethodCode,
            'customer' => [
                'email' => $transaction->customer_email,
                'phoneNumber' => $transaction->customer_phone ?? '',
                'name' => '',
            ],
            'paymentMethodRequiredFields' => [
                'creditCardNumber' => $cardNumber,
                'creditCardExpiryDate' => $cardExpiry,
                'creditCardSecurityNumber' => $cvv,
            ],
        ];

        $encrypted = $this->encrypt($body);

        if ($encrypted === null) {
            return ['success' => false, 'reference_number' => '', 'message' => 'Encryption failed. Please try again.'];
        }

        $response = Http::withHeaders([
            'authorization' => config('pesepay.integration_key'),
            'Content-Type' => 'application/json',
        ])->post($this->makeCardPaymentUrl(), ['payload' => $encrypted]);

        Log::info('PesePay: make card payment response', [
            'transaction_id' => $transaction->id,
            'http_status' => $response->status(),
            'url' => $this->makePaymentUrl(),
            'body' => $response->body(),
        ]);

        if ($response->failed()) {
            $apiMessage = $response->json('message');
            $message = ($apiMessage && ! str_contains($apiMessage, 'No message')) ? $apiMessage : 'Card payment request failed. Please try again.';

            return ['success' => false, 'reference_number' => '', 'message' => $message];
        }

        $data = $this->decryptPayload($response->json('payload') ?? '');

        if ($data === null) {
            return ['success' => false, 'reference_number' => '', 'message' => 'Invalid response from payment gateway.'];
        }

        // referenceNumber is null for card payments; extract it from pollUrl query string
        $referenceNumber = $data['referenceNumber'] ?? null;

        if (! $referenceNumber && ! empty($data['pollUrl'])) {
            parse_str((string) parse_url($data['pollUrl'], PHP_URL_QUERY), $params);
            $referenceNumber = $params['referenceNumber'] ?? null;
        }

        return [
            'success' => true,
            'reference_number' => (string) ($referenceNumber ?? ''),
            'transaction_status' => $data['transactionStatus'] ?? '',
            'message' => '',
        ];
    }

    public function initiateTransaction(Transaction $transaction): array
    {
        $body = [
            'amountDetails' => [
                'amount' => $this->toUsd((float) $transaction->amount),
                'currencyCode' => config('pesepay.currency_code', 'USD'),
            ],
            'merchantReference' => 'TXN-'.$transaction->id,
            'reasonForPayment' => 'Token purchase #'.$transaction->id,
            'resultUrl' => rtrim(config('app.url'), '/').'/pesepay/result',
            'returnUrl' => rtrim(config('app.url'), '/').'/order/'.$transaction->id,
        ];

        $encrypted = $this->encrypt($body);

        if ($encrypted === null) {
            return ['success' => false, 'reference_number' => '', 'redirect_url' => '', 'message' => 'Encryption failed. Please try again.'];
        }

        $response = Http::withHeaders([
            'authorization' => config('pesepay.integration_key'),
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl().'/payments/initiate', ['payload' => $encrypted]);

        Log::info('PesePay: initiate transaction response', [
            'transaction_id' => $transaction->id,
            'http_status' => $response->status(),
        ]);

        if ($response->failed()) {
            return ['success' => false, 'reference_number' => '', 'redirect_url' => '', 'message' => 'Failed to initiate card payment. Please try again.'];
        }

        $data = $this->decryptPayload($response->json('payload') ?? '');

        if ($data === null) {
            return ['success' => false, 'reference_number' => '', 'redirect_url' => '', 'message' => 'Invalid response from payment gateway.'];
        }

        return [
            'success' => true,
            'reference_number' => $data['referenceNumber'] ?? '',
            'redirect_url' => $data['redirectUrl'] ?? '',
            'message' => '',
        ];
    }

    public function decryptPayload(string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }

        $key = config('pesepay.encryption_key');
        $iv = substr($key, 0, 16);

        $decrypted = openssl_decrypt($payload, 'AES-256-CBC', $key, 0, $iv);

        if ($decrypted === false) {
            Log::warning('PesePay: decryption failed');

            return null;
        }

        $data = json_decode($decrypted, true);

        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    private function encrypt(array $data): ?string
    {
        $key = config('pesepay.encryption_key');
        $iv = substr($key, 0, 16);

        $encrypted = openssl_encrypt(json_encode($data), 'AES-256-CBC', $key, 0, $iv);

        return $encrypted !== false ? $encrypted : null;
    }

    private function toUsd(float $zarAmount): float
    {
        $rate = (float) Setting::get('pesepay_exchange_rate', '18.00');

        return $rate > 0 ? round($zarAmount / $rate, 2) : $zarAmount;
    }

    private function baseUrl(): string
    {
        return config('pesepay.sandbox')
            ? 'https://api.test.sandbox.pesepay.com/payments-engine/v1'
            : 'https://api.pesepay.com/api/payments-engine/v1';
    }

    private function makePaymentUrl(): string
    {
        return config('pesepay.sandbox')
            ? 'https://api.test.sandbox.pesepay.com/payments-engine/v1/payments/make-payment'
            : 'https://api.pesepay.com/api/payments-engine/v2/payments/make-payment';
    }

    private function makeCardPaymentUrl(): string
    {
        return config('pesepay.sandbox')
            ? 'https://api.test.sandbox.pesepay.com/payments-engine/v2/payments/make-payment'
            : 'https://api.pesepay.com/api/payments-engine/v2/payments/make-payment';
    }
}
