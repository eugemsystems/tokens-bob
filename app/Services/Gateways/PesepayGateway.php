<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Contracts\SeamlessGateway;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        // v1 seamless (mobile money) expects flat amount/currencyCode and referenceNumber
        $body = [
            'amount' => $this->toUsd((float) $transaction->amount),
            'currencyCode' => config('pesepay.currency_code', 'USD'),
            'referenceNumber' => 'VGP-'.$transaction->id.'-'.Str::random(8),
            'reasonForPayment' => 'Invoice #'.now()->format('YmdHis'),
            'resultUrl' => rtrim(config('app.url'), '/').'/pesepay/result',
            'returnUrl' => rtrim(config('app.url'), '/').'/order/'.$transaction->id,
            'paymentMethodCode' => $paymentMethodCode,
            'customer' => [
                'email' => $transaction->customer_email,
                'phoneNumber' => $transaction->customer_phone ?? '',
                'name' => '',
            ],
            'paymentMethodRequiredFields' => in_array($paymentMethodCode, ['PZW211', 'PZW212'], true)
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

        $response = $this->sendRequest('POST', $this->makePaymentUrl(), ['payload' => $encrypted]);

        if ($response === null) {
            Log::error('PesePay: request failed for make payment', ['transaction_id' => $transaction->id]);

            return ['success' => false, 'reference_number' => '', 'poll_url' => '', 'message' => 'Could not reach payment gateway. Please try again later.'];
        }

        Log::info('PesePay: make payment response', [
            'transaction_id' => $transaction->id,
            'http_status' => $response['status'],
            'body' => $response['body'],
        ]);

        if (! $response['succeeded']) {
            $apiMessage = $response['json']['message'] ?? null;
            $message = ($apiMessage && ! str_contains($apiMessage, 'No message')) ? $apiMessage : 'Payment request failed. Please try again.';

            return ['success' => false, 'reference_number' => '', 'poll_url' => '', 'message' => $message];
        }

        $data = $this->decryptPayload($response['json']['payload'] ?? '');

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
        $response = $this->sendRequest('GET', $this->baseUrl().'/payments/check-payment', [
            'referenceNumber' => $referenceNumber,
        ]);

        if ($response === null) {
            Log::error('PesePay: request failed for check status', ['reference_number' => $referenceNumber]);

            return null;
        }

        if (! $response['succeeded']) {
            Log::warning('PesePay: check payment status failed', [
                'reference_number' => $referenceNumber,
                'http_status' => $response['status'],
                'body' => $response['body'],
            ]);

            return null;
        }

        $data = $this->decryptPayload($response['json']['payload'] ?? '');

        Log::info('PesePay: check payment status', [
            'reference_number' => $referenceNumber,
            'http_status' => $response['status'],
            'transaction_status' => $data['transactionStatus'] ?? '(decrypt failed)',
            'status_code' => $data['transactionStatusCode'] ?? null,
            'status_description' => $data['transactionStatusDescription'] ?? null,
            'decrypt_ok' => $data !== null,
        ]);

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
            ['code' => 'PZW211', 'name' => 'EcoCash USD', 'requires_phone' => true, 'is_card' => false],
            ['code' => 'PZW212', 'name' => 'Innbucks',    'requires_phone' => true, 'is_card' => false],
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
            'merchantReference' => 'VGP-'.$transaction->id.'-'.Str::random(8),
            'reasonForPayment' => 'Invoice #'.now()->format('YmdHis'),
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
            return ['success' => false, 'reference_number' => '', 'transaction_status' => '', 'redirect_url' => '', 'message' => 'Encryption failed. Please try again.'];
        }

        $response = $this->sendRequest('POST', $this->makeCardPaymentUrl(), ['payload' => $encrypted]);

        if ($response === null) {
            Log::error('PesePay: request failed for card payment', ['transaction_id' => $transaction->id]);

            return ['success' => false, 'reference_number' => '', 'transaction_status' => '', 'redirect_url' => '', 'message' => 'Could not reach payment gateway. Please try again later.'];
        }

        Log::info('PesePay: make card payment response', [
            'transaction_id' => $transaction->id,
            'http_status' => $response['status'],
            'url' => $this->makeCardPaymentUrl(),
            'body' => $response['body'],
        ]);

        if (! $response['succeeded']) {
            $apiMessage = $response['json']['message'] ?? null;
            $message = ($apiMessage && ! str_contains($apiMessage, 'No message')) ? $apiMessage : 'Card payment request failed. Please try again.';

            return ['success' => false, 'reference_number' => '', 'transaction_status' => '', 'redirect_url' => '', 'message' => $message];
        }

        $data = $this->decryptPayload($response['json']['payload'] ?? '');

        if ($data === null) {
            return ['success' => false, 'reference_number' => '', 'transaction_status' => '', 'redirect_url' => '', 'message' => 'Invalid response from payment gateway.'];
        }

        Log::info('PesePay: card payment decrypted response', [
            'transaction_id' => $transaction->id,
            'transaction_status' => $data['transactionStatus'] ?? null,
            'status_code' => $data['transactionStatusCode'] ?? null,
            'status_description' => $data['transactionStatusDescription'] ?? null,
            'redirect_url' => $data['redirectUrl'] ?? null,
            'reference_number' => $data['referenceNumber'] ?? null,
            'poll_url' => $data['pollUrl'] ?? null,
            'transaction_metadata' => $data['transactionMetadata'] ?? null,
        ]);

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
            'redirect_url' => $data['redirectUrl'] ?? '',
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
            'merchantReference' => 'VGP-'.$transaction->id.'-'.(string) Str::uuid(),
            'reasonForPayment' => 'Invoice #'.now()->format('YmdHis'),
            'resultUrl' => rtrim(config('app.url'), '/').'/pesepay/result',
            'returnUrl' => rtrim(config('app.url'), '/').'/order/'.$transaction->id,
        ];

        $encrypted = $this->encrypt($body);

        if ($encrypted === null) {
            return ['success' => false, 'reference_number' => '', 'redirect_url' => '', 'message' => 'Encryption failed. Please try again.'];
        }

        $response = $this->sendRequest('POST', $this->initiateTransactionUrl(), ['payload' => $encrypted]);

        if ($response === null) {
            return ['success' => false, 'reference_number' => '', 'redirect_url' => '', 'message' => 'Could not reach payment gateway. Please try again later.'];
        }

        Log::info('PesePay: initiate transaction response', [
            'transaction_id' => $transaction->id,
            'http_status' => $response['status'],
            'body' => $response['succeeded'] ? null : $response['body'],
        ]);

        if (! $response['succeeded']) {
            $apiMessage = $response['json']['message'] ?? null;
            $message = ($apiMessage && ! str_contains($apiMessage, 'No message')) ? $apiMessage : 'Failed to initiate card payment. Please try again.';

            return ['success' => false, 'reference_number' => '', 'redirect_url' => '', 'message' => $message];
        }

        $data = $this->decryptPayload($response['json']['payload'] ?? '');

        if ($data === null) {
            return ['success' => false, 'reference_number' => '', 'redirect_url' => '', 'message' => 'Invalid response from payment gateway.'];
        }

        Log::info('PesePay: initiate transaction decrypted', [
            'transaction_id' => $transaction->id,
            'reference_number' => $data['referenceNumber'] ?? null,
            'redirect_url' => $data['redirectUrl'] ?? null,
            'transaction_status' => $data['transactionStatus'] ?? null,
        ]);

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

    /**
     * Send a request to PesePay using native curl.
     * Guzzle is avoided here because PesePay's nginx sends a folded
     * Strict-Transport-Security header that Guzzle/libcurl rejects as invalid,
     * causing it to throw AFTER the request was already processed by PesePay.
     * A Guzzle retry would then send the same payload twice, triggering
     * "Duplicate merchant reference" errors. Curl handles folded headers fine.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string, json: array<string, mixed>|null, succeeded: bool}|null
     */
    protected function sendRequest(string $method, string $url, array $payload = []): ?array
    {
        return $this->curlRequest($method, $url, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string, json: array<string, mixed>|null, succeeded: bool}|null
     */
    private function curlRequest(string $method, string $url, array $payload = []): ?array
    {
        $ch = curl_init();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'authorization: '.config('pesepay.integration_key'),
                'Content-Type: application/json',
            ],
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_URL] = $url;
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        } else {
            $opts[CURLOPT_URL] = empty($payload) ? $url : $url.'?'.http_build_query($payload);
        }

        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            Log::error('PesePay: native curl request failed', ['url' => $url, 'error' => $curlError]);

            return null;
        }

        $json = json_decode((string) $body, true);

        return [
            'status' => $httpCode,
            'body' => (string) $body,
            'json' => json_last_error() === JSON_ERROR_NONE ? $json : null,
            'succeeded' => $httpCode < 400,
        ];
    }

    private function isSandbox(): bool
    {
        $setting = Setting::get('pesepay_sandbox');

        return $setting !== null ? (bool) $setting : (bool) config('pesepay.sandbox', true);
    }

    private function toUsd(float $zarAmount): float
    {
        $rate = (float) Setting::get('pesepay_exchange_rate', '18.00');

        return $rate > 0 ? round($zarAmount / $rate, 2) : $zarAmount;
    }

    private function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.test.sandbox.pesepay.com/payments-engine/v1'
            : 'https://api.pesepay.com/api/payments-engine/v1';
    }

    private function makePaymentUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.test.sandbox.pesepay.com/payments-engine/v1/payments/make-payment'
            : 'https://api.pesepay.com/api/payments-engine/v1/payments/make-payment';
    }

    private function makeCardPaymentUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.test.sandbox.pesepay.com/payments-engine/v2/payments/make-payment'
            : 'https://api.pesepay.com/api/payments-engine/v2/payments/make-payment';
    }

    private function initiateTransactionUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.test.sandbox.pesepay.com/payments-engine/v1/payments/initiate'
            : 'https://api.pesepay.com/api/payments-engine/v1/payments/initiate';
    }
}
