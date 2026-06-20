<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Mail\TokenPurchased;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\Gateways\DpoGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DpoCallbackController extends Controller
{
    public function __construct(private readonly DpoGateway $dpo) {}

    public function return(Request $request): RedirectResponse
    {
        $token         = $request->query('TransactionToken');
        $companyRef    = $request->query('CompanyRef');
        $transactionId = $companyRef ? str_replace('TXN-', '', $companyRef) : null;

        Log::info('DPO return callback received', [
            'TransactionToken' => $token,
            'CompanyRef'       => $companyRef,
            'transaction_id'   => $transactionId,
            'all_query_params' => $request->query(),
        ]);

        if (! $token || ! $transactionId) {
            return redirect()->route('shop')->with('error', 'Invalid payment callback.');
        }

        $transaction = Transaction::find($transactionId);

        if (! $transaction) {
            Log::warning('DPO return: transaction not found', ['transaction_id' => $transactionId]);

            return redirect()->route('shop')->with('error', 'Transaction not found.');
        }

        $wasAlreadyCompleted = $transaction->status === TransactionStatus::Completed;

        $result = $this->dpo->verifyToken($token);

        Log::info('DPO return: verifyToken result', [
            'transaction_id' => $transactionId,
            'result'         => $result,
        ]);

        DB::transaction(function () use ($transaction, $result, $token): void {
            if ($result === '000') {
                if ($transaction->status !== TransactionStatus::Completed) {
                    $transaction->update([
                        'status'             => TransactionStatus::Completed,
                        'gateway_payment_id' => $token,
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

        if ($result === '000') {
            if (! $wasAlreadyCompleted) {
                Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction->fresh()));
            }

            if ($request->boolean('popup')) {
                return response()->view('dpo.done', ['transactionId' => $transaction->id]);
            }

            return redirect()->route('order', $transaction->id);
        }

        if ($request->boolean('popup')) {
            return response()->view('dpo.cancelled');
        }

        return redirect()->route('shop')->with('error', 'Payment could not be verified. Please contact support with reference #'.$transaction->id.'.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $companyRef    = $request->query('CompanyRef');
        $transactionId = $companyRef ? str_replace('TXN-', '', $companyRef) : null;

        Log::info('DPO cancel callback received', [
            'CompanyRef'     => $companyRef,
            'transaction_id' => $transactionId,
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

        if ($request->boolean('popup')) {
            return response()->view('dpo.cancelled');
        }

        return redirect()->route('shop');
    }
}
