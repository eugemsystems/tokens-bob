<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Mail\TokenPurchased;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\Gateways\FlutterwaveGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FlutterwaveCallbackController extends Controller
{
    public function __construct(private readonly FlutterwaveGateway $flutterwave) {}

    public function callback(Request $request): RedirectResponse
    {
        $transactionId    = (int) $request->query('txn');
        $flwTransactionId = $request->query('transaction_id');
        $status           = $request->query('status');

        Log::info('Flutterwave: 3DS callback received', [
            'txn'             => $transactionId,
            'flw_txn'         => $flwTransactionId,
            'status'          => $status,
        ]);

        $transaction = Transaction::find($transactionId);

        if (! $transaction) {
            return redirect()->route('shop');
        }

        if ($transaction->status === TransactionStatus::Completed) {
            return redirect()->route('order', $transaction->id);
        }

        $verified = false;

        if ($flwTransactionId && $status === 'successful') {
            $data = $this->flutterwave->verifyTransaction($flwTransactionId);

            $verified = $data
                && $data['status'] === 'successful'
                && $data['tx_ref'] === $transaction->gateway_payment_id
                && $data['amount'] >= (float) $transaction->amount
                && $data['currency'] === 'ZAR';

            if ($verified) {
                $flwTransactionId = $data['id'];
            }
        }

        DB::transaction(function () use ($transaction, $verified, $flwTransactionId): void {
            if ($verified) {
                $transaction->update([
                    'status'             => TransactionStatus::Completed,
                    'gateway_payment_id' => (string) $flwTransactionId,
                ]);

                Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Sold]);
            } else {
                $transaction->update(['status' => TransactionStatus::Failed]);

                Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
            }
        });

        if ($verified) {
            Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction->fresh()));

            return redirect()->route('order', $transaction->id);
        }

        return redirect()->route('shop');
    }
}
