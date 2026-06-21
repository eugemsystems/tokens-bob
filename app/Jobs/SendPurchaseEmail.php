<?php

namespace App\Jobs;

use App\Mail\AccountActivationPending;
use App\Mail\TokenPurchased;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendPurchaseEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $transactionId,
        public readonly string $type, // 'token' | 'activation'
        public readonly ?int $categoryId = null,
    ) {}

    public function handle(): void
    {
        $transaction = Transaction::find($this->transactionId);

        if (! $transaction) {
            return;
        }

        if ($this->type === 'token') {
            Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction));

            return;
        }

        if ($this->type === 'activation' && $this->categoryId) {
            $category = Category::find($this->categoryId);

            if ($category) {
                Mail::to($transaction->customer_email)->send(new AccountActivationPending($transaction, $category));
            }
        }
    }

    public function displayName(): string
    {
        return match ($this->type) {
            'token' => 'Send Token Purchase Email',
            'activation' => 'Send Account Activation Email',
            default => 'Send Purchase Email',
        };
    }
}
