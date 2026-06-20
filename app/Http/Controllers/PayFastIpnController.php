<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Mail\TokenPurchased;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayFastIpnController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->all();

        Log::info('PayFast IPN received', [
            'payment_status' => $data['payment_status'] ?? null,
            'm_payment_id'   => $data['m_payment_id'] ?? null,
            'pf_payment_id'  => $data['pf_payment_id'] ?? null,
            'amount_gross'   => $data['amount_gross'] ?? null,
        ]);

        if (! $this->verifySignature($data)) {
            Log::warning('PayFast IPN: invalid signature', ['data' => $data]);

            return response('Invalid signature', 400);
        }

        $transactionId = $data['m_payment_id'] ?? null;
        $paymentStatus = $data['payment_status'] ?? null;
        $pfPaymentId   = $data['pf_payment_id'] ?? null;

        if (! $transactionId || ! $paymentStatus) {
            return response('Missing required fields', 400);
        }

        $transaction = Transaction::find($transactionId);

        if (! $transaction) {
            Log::warning('PayFast IPN: transaction not found', ['transaction_id' => $transactionId]);

            return response('Transaction not found', 404);
        }

        $wasAlreadyCompleted = $transaction->status === TransactionStatus::Completed;

        DB::transaction(function () use ($transaction, $paymentStatus, $pfPaymentId): void {
            if ($paymentStatus === 'COMPLETE') {
                if ($transaction->status !== TransactionStatus::Completed) {
                    $transaction->update([
                        'pf_payment_id' => $pfPaymentId,
                        'status'        => TransactionStatus::Completed,
                    ]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Sold]);

                    Log::info('PayFast IPN: transaction completed', ['transaction_id' => $transaction->id]);
                } elseif ($pfPaymentId && ! $transaction->pf_payment_id) {
                    $transaction->update(['pf_payment_id' => $pfPaymentId]);
                }
            } elseif (in_array($paymentStatus, ['FAILED', 'CANCELLED'])) {
                if ($transaction->status !== TransactionStatus::Completed) {
                    $transaction->update(['status' => TransactionStatus::Failed]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);

                    Log::info('PayFast IPN: transaction failed/cancelled', ['transaction_id' => $transaction->id]);
                }
            }
        });

        if (! $wasAlreadyCompleted && $paymentStatus === 'COMPLETE') {
            Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction->fresh()));
        }

        return response('OK', 200);
    }

    /** @param  array<string, mixed>  $data */
    private function verifySignature(array $data): bool
    {
        $signature = $data['signature'] ?? null;

        if (! $signature) {
            return false;
        }

        $passphrase = config('payfast.passphrase', '');

        // PayFast signs ALL posted fields in received order, including empty-string fields.
        // The /payfast/notify route is excluded from ConvertEmptyStringsToNull middleware
        // so empty strings remain as '' here (not null).
        $queryString = '';
        foreach ($data as $key => $value) {
            if ($key !== 'signature' && $value !== null) {
                $queryString .= $key.'='.urlencode(trim((string) $value)).'&';
            }
        }
        $queryString = rtrim($queryString, '&');

        if ($passphrase !== '') {
            $queryString .= '&passphrase='.urlencode(trim($passphrase));
        }

        return hash_equals(md5($queryString), $signature);
    }
}
