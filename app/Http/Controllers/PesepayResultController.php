<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Jobs\SendPurchaseEmail;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\Gateways\PesepayGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PesepayResultController extends Controller
{
    public function __construct(private readonly PesepayGateway $pesepay) {}

    public function result(Request $request): Response
    {
        $encryptedPayload = $request->input('payload');

        Log::info('PesePay: result URL called', [
            'has_payload' => ! empty($encryptedPayload),
            'method' => $request->method(),
        ]);

        if (! $encryptedPayload) {
            return response('', 200);
        }

        $data = $this->pesepay->decryptPayload($encryptedPayload);

        if (! $data) {
            Log::warning('PesePay: could not decrypt result payload');

            return response('', 200);
        }

        $referenceNumber = $data['referenceNumber'] ?? null;
        $status = $data['transactionStatus'] ?? null;
        $merchantRef = $data['merchantReference'] ?? null;

        Log::info('PesePay: result payload decrypted', [
            'reference_number' => $referenceNumber,
            'status' => $status,
            'merchant_ref' => $merchantRef,
        ]);

        // Supports "VGP-31-aBcDeFgH" (current) and legacy "TXN-31" formats
        preg_match('/(?:VGP|TXN)-(\d+)/', $merchantRef ?? '', $refMatches);
        $transactionId = isset($refMatches[1]) ? (int) $refMatches[1] : null;

        if (! $transactionId) {
            Log::warning('PesePay: no transaction ID in merchant reference', ['merchant_ref' => $merchantRef]);

            return response('', 200);
        }

        $transaction = Transaction::find($transactionId);

        if (! $transaction) {
            Log::warning('PesePay: transaction not found', ['transaction_id' => $transactionId]);

            return response('', 200);
        }

        if ($transaction->status === TransactionStatus::Completed) {
            return response('', 200);
        }

        $isSuccess = in_array($status, ['SUCCESS', 'PROCESSED'], true);

        DB::transaction(function () use ($transaction, $referenceNumber, $isSuccess): void {
            if ($isSuccess) {
                $transaction->update([
                    'status' => TransactionStatus::Completed,
                    'gateway_payment_id' => $referenceNumber,
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

        if ($isSuccess) {
            SendPurchaseEmail::dispatch($transaction->id, 'token');
        }

        return response('', 200);
    }
}
