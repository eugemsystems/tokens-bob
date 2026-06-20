<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Mail\TokenPurchased;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\Gateways\PeachPaymentsGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PeachCallbackController extends Controller
{
    public function __construct(private readonly PeachPaymentsGateway $peach) {}

    public function return(Request $request): RedirectResponse
    {
        // Peach POSTs form data; PHP converts 'result.code' → 'result_code' during parsing.
        // We rely on server-side status query (queryStatus) for authoritative result verification.
        $checkoutId    = $request->input('checkoutId');
        $merchantRef   = $request->input('merchantTransactionId');
        $transactionId = $merchantRef ? str_replace('TXN-', '', $merchantRef) : null;

        Log::info('PeachPayments: return callback received', [
            'checkoutId'            => $checkoutId,
            'merchantTransactionId' => $merchantRef,
            'transaction_id'        => $transactionId,
            'all_params'            => $request->all(),
        ]);

        if (! $checkoutId || ! $transactionId) {
            return redirect()->route('shop')->with('error', 'Invalid payment callback.');
        }

        $transaction = Transaction::find($transactionId);

        if (! $transaction) {
            Log::warning('PeachPayments: transaction not found', ['transaction_id' => $transactionId]);

            return redirect()->route('shop')->with('error', 'Transaction not found.');
        }

        $wasAlreadyCompleted = $transaction->status === TransactionStatus::Completed;

        $statusData = $this->peach->queryStatus($checkoutId);

        if ($statusData === null) {
            Log::error('PeachPayments: status query failed', ['checkout_id' => $checkoutId, 'transaction_id' => $transactionId]);

            return redirect()->route('shop')->with('error', 'Could not verify payment. Please contact support with reference #'.$transaction->id.'.');
        }

        $resultCode = data_get($statusData, 'result.code', '');
        $success    = $this->peach->isSuccessCode($resultCode);

        Log::info('PeachPayments: status verified', [
            'transaction_id' => $transactionId,
            'result_code'    => $resultCode,
            'success'        => $success,
        ]);

        DB::transaction(function () use ($transaction, $success, $checkoutId): void {
            if ($success) {
                if ($transaction->status !== TransactionStatus::Completed) {
                    $transaction->update([
                        'status'             => TransactionStatus::Completed,
                        'gateway_payment_id' => $checkoutId,
                    ]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Sold]);
                }
            } else {
                if ($transaction->status !== TransactionStatus::Completed) {
                    $transaction->update(['status' => TransactionStatus::Failed]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
                }
            }
        });

        if ($success) {
            if (! $wasAlreadyCompleted) {
                Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction->fresh()));
            }

            return redirect()->route('order', $transaction->id);
        }

        return redirect()->route('shop')->with('error', 'Payment could not be completed. Please contact support with reference #'.$transaction->id.'.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $merchantRef   = $request->input('merchantTransactionId', $request->query('merchantTransactionId'));
        $transactionId = $merchantRef ? str_replace('TXN-', '', $merchantRef) : null;

        Log::info('PeachPayments: cancel callback received', [
            'merchantTransactionId' => $merchantRef,
            'transaction_id'        => $transactionId,
        ]);

        if ($transactionId) {
            $transaction = Transaction::find($transactionId);

            if ($transaction && $transaction->status !== TransactionStatus::Completed) {
                DB::transaction(function () use ($transaction): void {
                    $transaction->update(['status' => TransactionStatus::Failed]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
                });
            }
        }

        return redirect()->route('shop');
    }
}
