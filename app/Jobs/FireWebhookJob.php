<?php

namespace App\Jobs;

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
        Http::post($this->url, [
            'email' => $this->email,
            'phone' => $this->phone,
            'product' => $this->product,
            'ip' => $this->ip,
        ]);

        Log::info('FireWebhookJob: webhook delivered', ['url' => $this->url, 'email' => $this->email]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FireWebhookJob: failed after retries', [
            'url' => $this->url,
            'email' => $this->email,
            'error' => $e->getMessage(),
        ]);
    }

    public function displayName(): string
    {
        return 'Fire Webhook';
    }
}
