<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayFastService
{
    private string $merchantId;

    private string $merchantKey;

    private string $passphrase;

    private string $baseUrl;

    public function __construct()
    {
        $this->merchantId = config('payfast.merchant_id');
        $this->merchantKey = config('payfast.merchant_key');
        $this->passphrase = config('payfast.passphrase');
        $this->baseUrl = config('payfast.base_url');
    }

    /**
     * POST order details to PayFast's onsite/process endpoint and return the UUID
     * used by the client-side engine.js to open the payment overlay.
     *
     * @param  array{amount: float, item_name: string, customer_email: string, reference: string}  $orderData
     * @return array{success: bool, uuid: string|null, message: string}
     */
    public function initiatePayment(array $orderData): array
    {
        $payload = $this->buildOrderPayload($orderData);
        $payload['signature'] = $this->generateSignature($payload);

        $body = '';
        foreach ($payload as $key => $value) {
            if ($value !== '') {
                $body .= $key.'='.urlencode(trim((string) $value)).'&';
            }
        }
        $body = rtrim($body, '&');

        Log::info('PayFast: sending onsite/process request', [
            'url'       => $this->baseUrl.'/onsite/process',
            'reference' => $orderData['reference'],
            'amount'    => $orderData['amount'],
            'item_name' => $orderData['item_name'],
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->withBody($body, 'application/x-www-form-urlencoded')
            ->post($this->baseUrl.'/onsite/process');

        Log::info('PayFast: onsite/process response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->failed()) {
            return [
                'success' => false,
                'uuid'    => null,
                'message' => 'Payment gateway error ('.$response->status().'): '.$response->body(),
            ];
        }

        $uuid = $response->json('uuid');

        if (empty($uuid)) {
            return [
                'success' => false,
                'uuid'    => null,
                'message' => 'Unexpected response from payment gateway: '.$response->body(),
            ];
        }

        return ['success' => true, 'uuid' => $uuid, 'message' => ''];
    }

    /**
     * merchant_id and merchant_key must come first — field order determines the signature string.
     *
     * @param  array{amount: float, item_name: string, customer_email: string, reference: string}  $orderData
     * @return array<string, string>
     */
    private function buildOrderPayload(array $orderData): array
    {
        return [
            'merchant_id'   => $this->merchantId,
            'merchant_key'  => $this->merchantKey,
            'return_url'    => config('app.url'),
            'cancel_url'    => config('app.url'),
            'notify_url'    => config('payfast.notify_url') ?? rtrim(config('app.url'), '/').'/payfast/notify',
            'email_address' => $orderData['customer_email'],
            'm_payment_id'  => $orderData['reference'],
            'amount'        => number_format((float) $orderData['amount'], 2, '.', ''),
            'item_name'     => $orderData['item_name'],
        ];
    }

    /**
     * MD5 signature following the official PayFast algorithm:
     * iterate fields in declaration order, append passphrase, MD5.
     *
     * @param  array<string, string>  $payload
     */
    public function generateSignature(array $payload): string
    {
        $pfOutput = '';
        foreach ($payload as $key => $value) {
            if ($value !== '') {
                $pfOutput .= $key.'='.urlencode(trim((string) $value)).'&';
            }
        }

        $getString = substr($pfOutput, 0, -1);

        if ($this->passphrase !== '') {
            $getString .= '&passphrase='.urlencode(trim($this->passphrase));
        }

        return md5($getString);
    }
}
