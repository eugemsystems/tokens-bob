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

class SnapScanWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Log::info('SnapScan webhook received', [
            'headers' => $request->headers->all(),
            'payload' => $request->json()->all(),
        ]);

        if (! $this->verifySignature($request)) {
            Log::warning('SnapScan webhook: invalid signature');

            return response('Invalid signature', 400);
        }

        $payload   = $request->json()->all();
        $reference = $payload['merchantReference'] ?? null;
        $status    = $payload['status'] ?? null;

        if (! $reference || ! $status) {
            return response('Missing required fields', 400);
        }

        $transactionId = str_replace('TXN-', '', $reference);
        $transaction   = Transaction::find($transactionId);

        if (! $transaction) {
            Log::warning('SnapScan webhook: transaction not found', ['transaction_id' => $transactionId]);

            return response('Transaction not found', 404);
        }

        $wasAlreadyCompleted = $transaction->status === TransactionStatus::Completed;

        DB::transaction(function () use ($transaction, $status): void {
            if ($status === 'completed') {
                if ($transaction->status !== TransactionStatus::Completed) {
                    $transaction->update(['status' => TransactionStatus::Completed]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Sold]);

                    Log::info('SnapScan webhook: transaction completed', ['transaction_id' => $transaction->id]);
                }
            } elseif ($status === 'failed') {
                if ($transaction->status !== TransactionStatus::Completed) {
                    $transaction->update(['status' => TransactionStatus::Failed]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);

                    Log::info('SnapScan webhook: transaction failed', ['transaction_id' => $transaction->id]);
                }
            }
        });

        if (! $wasAlreadyCompleted && $status === 'completed') {
            Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction->fresh()));
        }

        return response('OK', 200);
    }

    private function verifySignature(Request $request): bool
    {
        $secret    = config('snapscan.api_key', '');
        $signature = $request->header('X-Snapscan-Signature');

        if (! $signature || ! $secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
