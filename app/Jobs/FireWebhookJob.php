<?php

namespace App\Jobs;

use App\Models\WebhookLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FireWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $url,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $product,
        public readonly string $ip,
    ) {}

    public function handle(): void
    {
        $payload = [
            'email' => $this->email,
            'phone' => $this->phone,
            'product' => $this->product,
            'ip' => $this->ip,
        ];

        $response = Http::post($this->url, $payload);

        Log::info('FireWebhookJob: webhook delivered', [
            'url' => $this->url,
            'email' => $this->email,
            'http_status' => $response->status(),
        ]);

        WebhookLog::create([
            'source' => 'partner',
            'event_type' => 'notification',
            'payload' => json_encode($payload),
            'headers' => json_encode(['url' => $this->url]),
            'status' => $response->successful() ? 'processed' : 'failed',
            'response_code' => $response->status(),
            'ip_address' => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FireWebhookJob: failed after retries', [
            'url' => $this->url,
            'email' => $this->email,
            'error' => $e->getMessage(),
        ]);

        WebhookLog::create([
            'source' => 'partner',
            'event_type' => 'notification',
            'payload' => json_encode(['email' => $this->email]),
            'headers' => json_encode(['url' => $this->url]),
            'status' => 'failed',
            'response_code' => null,
            'ip_address' => null,
        ]);
    }

    public function displayName(): string
    {
        return 'Fire Webhook';
    }
}
