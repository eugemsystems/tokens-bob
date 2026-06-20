<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Mail\TokenPurchased;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\Gateways\PaystackGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaystackCallbackController extends Controller
{
    public function __construct(private readonly PaystackGateway $paystack) {}

    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->query('reference') ?? $request->query('trxref');
        $txnId     = (int) $request->query('txn');

        Log::info('Paystack: 3DS callback received', [
            'reference' => $reference,
            'txn'       => $txnId,
        ]);

        $transaction = $txnId
            ? Transaction::find($txnId)
            : Transaction::where('gateway_payment_id', $reference)->first();

        if (! $transaction) {
            return redirect()->route('shop');
        }

        if ($transaction->status === TransactionStatus::Completed) {
            return redirect()->route('order', $transaction->id);
        }

        $verified = false;

        if ($reference) {
            $data = $this->paystack->verifyTransaction($reference);

            $verified = $data
                && $data['status'] === 'successful'
                && $data['tx_ref'] === $transaction->gateway_payment_id
                && $data['amount'] >= (float) $transaction->amount
                && $data['currency'] === 'ZAR';
        }

        DB::transaction(function () use ($transaction, $verified, $reference): void {
            if ($verified) {
                $transaction->update([
                    'status'             => TransactionStatus::Completed,
                    'gateway_payment_id' => $reference,
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
