<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SnapScanGateway implements PaymentGateway
{
    public function getKey(): string
    {
        return 'snapscan';
    }

    public function getName(): string
    {
        return 'SnapScan';
    }

    public function getCheckoutType(): string
    {
        return 'qr';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        $snapCode = config('snapscan.snap_code');

        if (empty($snapCode)) {
            return ['success' => false, 'checkout_type' => 'qr', 'data' => [], 'message' => 'SnapScan is not configured.'];
        }

        $amountInCents = (int) round((float) $transaction->amount * 100);
        $reference     = 'TXN-'.$transaction->id;

        $qrUrl = config('snapscan.base_url').'/qr/'.urlencode($snapCode)
            .'?id='.urlencode($reference)
            .'&amount='.$amountInCents
            .'&strict=true';

        Log::info('SnapScan: generated QR', [
            'transaction_id' => $transaction->id,
            'amount_cents'   => $amountInCents,
            'reference'      => $reference,
            'description'    => $description,
        ]);

        return [
            'success'       => true,
            'checkout_type' => 'qr',
            'data'          => ['qr_url' => $qrUrl, 'reference' => $reference],
            'message'       => '',
        ];
    }

    /**
     * Query SnapScan API to check if the payment with this reference has been completed.
     */
    public function checkPayment(string $reference): ?array
    {
        $apiKey = config('snapscan.api_key');

        $response = Http::withBasicAuth($apiKey, '')
            ->get(config('snapscan.base_url').'/merchant/api/v1/payments', [
                'merchantReference' => $reference,
            ]);

        if ($response->failed()) {
            return null;
        }

        $payments = $response->json();

        if (empty($payments)) {
            return null;
        }

        return $payments[0];
    }
}
