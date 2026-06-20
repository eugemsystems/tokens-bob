<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use App\Services\PayFastService;
use Illuminate\Support\Facades\Log;

class PayFastGateway implements PaymentGateway
{
    public function __construct(private readonly PayFastService $payFast) {}

    public function getKey(): string
    {
        return 'payfast';
    }

    public function getName(): string
    {
        return 'PayFast';
    }

    public function getCheckoutType(): string
    {
        return 'onsite';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        Log::info('PayFast: initiating payment', [
            'transaction_id' => $transaction->id,
            'amount'         => $transaction->amount,
            'description'    => $description,
            'email'          => $customerData['email'],
        ]);

        $result = $this->payFast->initiatePayment([
            'amount'         => $transaction->amount,
            'item_name'      => $description,
            'customer_email' => $customerData['email'],
            'reference'      => (string) $transaction->id,
        ]);

        Log::info('PayFast: initiation response', [
            'transaction_id' => $transaction->id,
            'success'        => $result['success'],
            'uuid'           => $result['uuid'] ?? null,
            'message'        => $result['message'] ?? null,
        ]);

        if (! $result['success']) {
            return ['success' => false, 'checkout_type' => 'onsite', 'data' => [], 'message' => $result['message']];
        }

        return [
            'success'       => true,
            'checkout_type' => 'onsite',
            'data'          => ['uuid' => $result['uuid']],
            'message'       => '',
        ];
    }
}
