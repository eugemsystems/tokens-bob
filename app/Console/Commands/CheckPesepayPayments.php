<?php

namespace App\Console\Commands;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Jobs\SendPurchaseEmail;
use App\Models\PesepayLog;
use App\Models\PesepayStatusCheck;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\Gateways\PesepayGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('pesepay:check-payments')]
#[Description('Check PesePay payment status for pending transactions created in the last hour and complete any that have succeeded.')]
class CheckPesepayPayments extends Command
{
    public function __construct(private readonly PesepayGateway $pesepay)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchId = Str::uuid()->toString();

        $transactions = Transaction::where('status', TransactionStatus::Pending)
            ->where('gateway', 'pesepay')
            ->where('created_at', '>=', now()->subHour())
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('No pending PesePay transactions within the last hour.');

            return self::SUCCESS;
        }

        $this->info("Batch {$batchId}: checking {$transactions->count()} transaction(s).");

        $checked = 0;
        $updated = 0;

        foreach ($transactions as $transaction) {
            $referenceNumber = $this->resolveReferenceNumber($transaction);

            if (! $referenceNumber) {
                PesepayStatusCheck::create([
                    'batch_id' => $batchId,
                    'transaction_id' => $transaction->id,
                    'reference_number' => null,
                    'status_before' => $transaction->status->value,
                    'status_returned' => null,
                    'status_after' => null,
                    'was_updated' => false,
                    'error_message' => 'No reference number found for transaction.',
                    'checked_at' => now(),
                ]);
                $checked++;

                continue;
            }

            $result = $this->pesepay->checkStatus($referenceNumber);

            if ($result === null) {
                PesepayStatusCheck::create([
                    'batch_id' => $batchId,
                    'transaction_id' => $transaction->id,
                    'reference_number' => $referenceNumber,
                    'status_before' => $transaction->status->value,
                    'status_returned' => null,
                    'status_after' => null,
                    'was_updated' => false,
                    'error_message' => 'PesePay API returned no result.',
                    'checked_at' => now(),
                ]);
                $checked++;

                continue;
            }

            $statusReturned = $result['transaction_status'];
            $isSuccess = in_array($statusReturned, ['SUCCESS', 'PROCESSED'], true);
            $wasUpdated = false;

            if ($isSuccess) {
                DB::transaction(function () use ($transaction, $referenceNumber): void {
                    $transaction->update([
                        'status' => TransactionStatus::Completed,
                        'gateway_payment_id' => $referenceNumber,
                    ]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Sold]);
                });

                SendPurchaseEmail::dispatch($transaction->id, 'token');

                $wasUpdated = true;
                $updated++;
            }

            PesepayStatusCheck::create([
                'batch_id' => $batchId,
                'transaction_id' => $transaction->id,
                'reference_number' => $referenceNumber,
                'status_before' => TransactionStatus::Pending->value,
                'status_returned' => $statusReturned,
                'status_after' => $wasUpdated ? TransactionStatus::Completed->value : null,
                'was_updated' => $wasUpdated,
                'error_message' => null,
                'checked_at' => now(),
            ]);

            $checked++;
        }

        $this->info("Done. Checked: {$checked}, Updated: {$updated}.");

        return self::SUCCESS;
    }

    private function resolveReferenceNumber(Transaction $transaction): ?string
    {
        if ($transaction->gateway_payment_id) {
            return $transaction->gateway_payment_id;
        }

        return PesepayLog::where('transaction_id', $transaction->id)
            ->whereIn('event', ['make_payment', 'initiate_transaction', 'card_payment'])
            ->whereNotNull('reference_number')
            ->latest()
            ->value('reference_number');
    }
}
