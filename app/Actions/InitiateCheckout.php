<?php

namespace App\Actions;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\GatewayManager;
use Illuminate\Support\Facades\DB;

class InitiateCheckout
{
    public function __construct(private readonly GatewayManager $gateways) {}

    /**
     * Reserve a token (if is_token), create a pending transaction, and obtain gateway checkout data.
     *
     * @param  array{email: string, phone: string}  $customerData
     * @return array{success: bool, checkout_type: string, data: array<string, mixed>, transaction_id: int|null, token_id: int|null, message: string}
     */
    public function execute(Category $category, array $customerData): array
    {
        $gateway = $this->gateways->active();
        $isWebhookPurchase = ! $category->is_token;

        ['token' => $token, 'transaction' => $transaction] = DB::transaction(
            function () use ($category, $customerData, $gateway, $isWebhookPurchase): array {
                $token = null;

                if (! $isWebhookPurchase) {
                    $token = Token::where('category_id', $category->id)
                        ->where('status', TokenStatus::Available)
                        ->lockForUpdate()
                        ->first();

                    if (! $token) {
                        return ['token' => null, 'transaction' => null];
                    }
                }

                $transaction = Transaction::create([
                    'customer_email'      => $customerData['email'],
                    'customer_phone'      => $customerData['phone'],
                    'amount'              => $category->price,
                    'status'              => TransactionStatus::Pending,
                    'gateway'             => $gateway->getKey(),
                    'is_webhook_purchase' => $isWebhookPurchase,
                ]);

                if ($token) {
                    $token->update([
                        'status'         => TokenStatus::Reserved,
                        'transaction_id' => $transaction->id,
                    ]);
                }

                return ['token' => $token, 'transaction' => $transaction];
            }
        );

        if (! $isWebhookPurchase && ! $token) {
            return [
                'success'        => false,
                'checkout_type'  => $gateway->getCheckoutType(),
                'data'           => [],
                'transaction_id' => null,
                'token_id'       => null,
                'message'        => 'No tokens are currently available for this category.',
            ];
        }

        $result = $gateway->initiate($transaction, $customerData, $category->name);

        if (! $result['success']) {
            DB::transaction(function () use ($token, $transaction): void {
                if ($token) {
                    $token->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
                }

                $transaction->update(['status' => TransactionStatus::Failed]);
            });

            return [
                'success'        => false,
                'checkout_type'  => $gateway->getCheckoutType(),
                'data'           => [],
                'transaction_id' => null,
                'token_id'       => null,
                'message'        => $result['message'],
            ];
        }

        return [
            'success'        => true,
            'checkout_type'  => $result['checkout_type'],
            'data'           => $result['data'],
            'transaction_id' => $transaction->id,
            'token_id'       => $token?->id,
            'message'        => '',
        ];
    }
}
