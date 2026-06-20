<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Mail\TokenPurchased;
use App\Models\Setting;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WhopWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();

        Log::info('Whop: webhook received', ['id' => $request->header('webhook-id')]);

        if (! $this->verifySignature($request, $body)) {
            Log::warning('Whop: invalid webhook signature');

            return response('Invalid signature', 400);
        }

        $payload = json_decode($body, true);
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? [];

        Log::info('Whop: webhook event', ['type' => $type]);

        if (! in_array($type, ['payment.succeeded', 'payment.failed'])) {
            return response('OK', 200);
        }

        $transactionId = $data['metadata']['transaction_id'] ?? null;

        if (! $transactionId) {
            Log::warning('Whop: webhook missing transaction_id in metadata', ['type' => $type]);

            return response('Missing transaction_id', 400);
        }

        $transaction = Transaction::find($transactionId);

        if (! $transaction) {
            Log::warning('Whop: transaction not found', ['transaction_id' => $transactionId]);

            return response('Transaction not found', 404);
        }

        if ($transaction->status === TransactionStatus::Completed) {
            return response('OK', 200);
        }

        $whopPaymentId = $data['id'] ?? null;
        $tokensSold = false;

        DB::transaction(function () use ($transaction, $type, $whopPaymentId, &$tokensSold): void {
            if ($type === 'payment.succeeded') {
                $transaction->update([
                    'status'             => TransactionStatus::Completed,
                    'gateway_payment_id' => $whopPaymentId ?? $transaction->gateway_payment_id,
                ]);

                $updated = Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Sold]);

                $tokensSold = $updated > 0;
            } else {
                $transaction->update(['status' => TransactionStatus::Failed]);

                Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
            }
        });

        if ($type === 'payment.succeeded') {
            if ($tokensSold) {
                Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction->fresh()));
            }

            if ($transaction->is_webhook_purchase) {
                $this->fireWebhook($transaction->customer_email);
            }
        }

        return response('OK', 200);
    }

    private function fireWebhook(string $email): void
    {
        $url = Setting::get('webhook_url', '');

        if (! $url) {
            return;
        }

        try {
            Http::post($url, ['email' => $email]);

            Log::info('Whop: webhook URL fired', ['url' => $url]);
        } catch (\Throwable $e) {
            Log::error('Whop: webhook URL failed', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }

    private function verifySignature(Request $request, string $body): bool
    {
        $webhookId = $request->header('webhook-id', '');
        $webhookTimestamp = $request->header('webhook-timestamp', '');
        $webhookSignature = $request->header('webhook-signature', '');

        if (! $webhookId || ! $webhookTimestamp || ! $webhookSignature) {
            return false;
        }

        $secret = config('whop.webhook_secret', '');

        if (! $secret) {
            return false;
        }

        $signedContent = "{$webhookId}.{$webhookTimestamp}.{$body}";
        // Whop signs with the full ws_xxx secret string verbatim (no decoding)
        $expectedHash  = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        foreach (explode(' ', $webhookSignature) as $sig) {
            if (str_starts_with($sig, 'v1,') && hash_equals($expectedHash, substr($sig, 3))) {
                return true;
            }
        }

        return false;
    }
}
