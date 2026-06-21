<?php

namespace App\Http\Controllers;

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Jobs\FireWebhookJob;
use App\Jobs\SendPurchaseEmail;
use App\Models\Setting;
use App\Models\Token;
use App\Models\Transaction;
use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhopWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);
        $type = $payload['type'] ?? null;

        $log = WebhookLog::create([
            'source' => 'whop',
            'event_type' => $type,
            'payload' => $body,
            'headers' => json_encode($request->headers->all()),
            'status' => 'received',
            'ip_address' => $request->ip(),
        ]);

        Log::info('Whop: webhook received', ['id' => $request->header('webhook-id'), 'log_id' => $log->id]);

        if (! $this->verifySignature($request, $body)) {
            Log::warning('Whop: invalid webhook signature');
            $log->update(['status' => 'failed', 'response_code' => 400]);

            return response('Invalid signature', 400);
        }

        $data = $payload['data'] ?? [];

        Log::info('Whop: webhook event', ['type' => $type]);

        if (! in_array($type, ['payment.succeeded', 'payment.failed'])) {
            $log->update(['status' => 'processed', 'response_code' => 200]);

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
                    'status' => TransactionStatus::Completed,
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
                SendPurchaseEmail::dispatch($transaction->id, 'token');
            }

            if ($transaction->is_webhook_purchase) {
                $url = Setting::get('webhook_url', '');

                if ($url) {
                    FireWebhookJob::dispatch($url, $transaction->customer_email);
                }

                $categoryId = $transaction->fresh()->category_id;

                if ($categoryId) {
                    SendPurchaseEmail::dispatch($transaction->id, 'activation', $categoryId);
                }
            }
        }

        $log->update(['status' => 'processed', 'response_code' => 200]);

        return response('OK', 200);
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
        $expectedHash = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        foreach (explode(' ', $webhookSignature) as $sig) {
            if (str_starts_with($sig, 'v1,') && hash_equals($expectedHash, substr($sig, 3))) {
                return true;
            }
        }

        return false;
    }
}
