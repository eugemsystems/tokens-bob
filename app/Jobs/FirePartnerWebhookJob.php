<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\WebhookLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirePartnerWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $url,
        public readonly int $transactionId,
    ) {}

    public function handle(): void
    {
        $transaction = Transaction::find($this->transactionId);

        if (! $transaction) {
            Log::warning('FirePartnerWebhookJob: transaction not found', ['transaction_id' => $this->transactionId]);

            return;
        }

        $partnerData = $transaction->partner_data ?? [];

        $payload = [
            'status' => 'paid',
            'email' => $transaction->customer_email,
            'reference' => $partnerData['reference'] ?? null,
            'bobtv_plan_id' => $partnerData['bobtv_plan_id'] ?? null,
            'product' => isset($partnerData['product']) ? (int) $partnerData['product'] : null,
            'transaction_id' => 'VG-'.str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT),
            'amount' => (float) $transaction->amount,
            'currency' => 'ZAR',
            'paid_at' => $transaction->updated_at?->toIso8601String(),
        ];

        $response = Http::post($this->url, $payload);

        Log::info('FirePartnerWebhookJob: delivered', [
            'url' => $this->url,
            'transaction_id' => $this->transactionId,
            'reference' => $partnerData['reference'] ?? null,
            'http_status' => $response->status(),
        ]);

        WebhookLog::create([
            'source' => 'partner',
            'event_type' => 'activation',
            'payload' => json_encode($payload),
            'headers' => json_encode(['url' => $this->url]),
            'status' => $response->successful() ? 'processed' : 'failed',
            'response_code' => $response->status(),
            'ip_address' => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FirePartnerWebhookJob: failed after retries', [
            'url' => $this->url,
            'transaction_id' => $this->transactionId,
            'error' => $e->getMessage(),
        ]);

        WebhookLog::create([
            'source' => 'partner',
            'event_type' => 'activation',
            'payload' => json_encode(['transaction_id' => $this->transactionId]),
            'headers' => json_encode(['url' => $this->url]),
            'status' => 'failed',
            'response_code' => null,
            'ip_address' => null,
        ]);
    }

    public function displayName(): string
    {
        return 'Fire Partner Webhook';
    }
}
