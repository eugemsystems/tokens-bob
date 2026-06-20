<?php

namespace App\Actions;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\PayFastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessCheckout
{
    public function __construct(private readonly PayFastService $payFast) {}

    /**
     * Claim a token, charge the customer, and fulfil the order atomically.
     *
     * @param  array{email: string, phone: string}  $customerData
     * @param  array{name: string, number: string, expiry_month: string, expiry_year: string, cvv: string}  $cardData
     * @return array{success: bool, token_code: string|null, transaction_id: int|null, message: string}
     */
    public function execute(Category $category, array $customerData, array $cardData): array
    {
        return DB::transaction(function () use ($category, $customerData, $cardData): array {
            // Exclusive lock prevents two concurrent requests from claiming the same token
            $token = Token::where('category_id', $category->id)
                ->where('status', TokenStatus::Available)
                ->lockForUpdate()
                ->first();

            if (! $token) {
                return [
                    'success' => false,
                    'token_code' => null,
                    'transaction_id' => null,
                    'message' => 'No tokens are currently available for this category.',
                ];
            }

            $token->update(['status' => TokenStatus::Reserved]);

            $transaction = Transaction::create([
                'customer_email' => $customerData['email'],
                'customer_phone' => $customerData['phone'],
                'amount' => $category->price,
                'status' => TransactionStatus::Pending,
            ]);

            $payFastResult = $this->payFast->processPayment(
                orderData: [
                    'amount' => $category->price,
                    'item_name' => $category->name,
                    'customer_email' => $customerData['email'],
                    'customer_phone' => $customerData['phone'],
                    'reference' => (string) Str::uuid(),
                ],
                cardData: $cardData,
            );

            // Card data no longer needed after this point
            unset($cardData);

            if ($payFastResult['success']) {
                $transaction->update([
                    'pf_payment_id' => $payFastResult['pf_payment_id'],
                    'status' => TransactionStatus::Completed,
                ]);

                $token->update([
                    'status' => TokenStatus::Sold,
                    'transaction_id' => $transaction->id,
                ]);

                return [
                    'success' => true,
                    'token_code' => $token->token_code,
                    'transaction_id' => $transaction->id,
                    'message' => 'Payment successful.',
                ];
            }

            $transaction->update(['status' => TransactionStatus::Failed]);
            $token->update(['status' => TokenStatus::Available]);

            return [
                'success' => false,
                'token_code' => null,
                'transaction_id' => null,
                'message' => $payFastResult['message'],
            ];
        });
    }
}
