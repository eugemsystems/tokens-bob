<?php

namespace App\Actions;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\GatewayManager;
use Illuminate\Support\Facades\DB;

class InitiateCartCheckout
{
    public function __construct(private readonly GatewayManager $gateways) {}

    /**
     * Reserve tokens for token-type categories, create one transaction, and initiate payment.
     * Webhook-type categories (is_token = false) are included in the total but don't reserve tokens.
     *
     * @param  array<int, int>  $cart  category_id => quantity
     * @param  array{email: string, phone: string, ip: string|null}  $customerData
     * @return array{success: bool, checkout_type: string, data: array<string, mixed>, transaction_id: int|null, message: string}
     */
    public function execute(array $cart, array $customerData): array
    {
        $gateway = $this->gateways->active();

        try {
            ['transaction' => $transaction, 'description' => $description] = DB::transaction(
                function () use ($cart, $customerData, $gateway): array {
                    $totalAmount = 0.0;
                    $reservedTokens = [];
                    $categoryNames = [];
                    $hasWebhookItems = false;
                    $webhookCategoryId = null;

                    foreach ($cart as $categoryId => $qty) {
                        $category = Category::findOrFail($categoryId);
                        $categoryNames[] = $qty > 1 ? "{$category->name} ×{$qty}" : $category->name;
                        $totalAmount += (float) $category->price * $qty;

                        if (! $category->is_token) {
                            $hasWebhookItems = true;
                            $webhookCategoryId = $category->id;

                            continue;
                        }

                        for ($i = 0; $i < $qty; $i++) {
                            $token = Token::where('category_id', $categoryId)
                                ->where('status', TokenStatus::Available)
                                ->lockForUpdate()
                                ->first();

                            if (! $token) {
                                throw new \RuntimeException("No tokens available for {$category->name}.");
                            }

                            $token->update(['status' => TokenStatus::Reserved]);
                            $reservedTokens[] = $token;
                        }
                    }

                    $transaction = Transaction::create([
                        'customer_email' => $customerData['email'],
                        'customer_phone' => $customerData['phone'],
                        'customer_ip' => $customerData['ip'] ?? null,
                        'amount' => $totalAmount,
                        'status' => TransactionStatus::Pending,
                        'gateway' => $gateway->getKey(),
                        'is_webhook_purchase' => $hasWebhookItems,
                        'category_id' => $webhookCategoryId,
                    ]);

                    foreach ($reservedTokens as $token) {
                        $token->update(['transaction_id' => $transaction->id]);
                    }

                    return [
                        'transaction' => $transaction,
                        'description' => implode(', ', $categoryNames),
                    ];
                }
            );
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'checkout_type' => $gateway->getCheckoutType(),
                'data' => [],
                'transaction_id' => null,
                'message' => $e->getMessage(),
            ];
        }

        $result = $gateway->initiate($transaction, $customerData, $description);

        if (! $result['success']) {
            DB::transaction(function () use ($transaction): void {
                Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
                $transaction->update(['status' => TransactionStatus::Failed]);
            });

            return [
                'success' => false,
                'checkout_type' => $gateway->getCheckoutType(),
                'data' => [],
                'transaction_id' => null,
                'message' => $result['message'],
            ];
        }

        return [
            'success' => true,
            'checkout_type' => $result['checkout_type'],
            'data' => $result['data'],
            'transaction_id' => $transaction->id,
            'message' => '',
        ];
    }
}
