<?php

namespace App\Observers;

use App\Enums\TransactionStatus;
use App\Jobs\FirePartnerWebhookJob;
use App\Models\Setting;
use App\Models\Transaction;

class TransactionObserver
{
    public function updated(Transaction $transaction): void
    {
        if (! $transaction->wasChanged('status')) {
            return;
        }

        if ($transaction->status !== TransactionStatus::Completed) {
            return;
        }

        $partnerData = $transaction->partner_data ?? [];

        if (empty($partnerData['reference'])) {
            return;
        }

        $webhookUrl = Setting::get('webhook_url', '');

        if (! $webhookUrl) {
            return;
        }

        FirePartnerWebhookJob::dispatch($webhookUrl, $transaction->id);
    }
}
