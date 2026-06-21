<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'source',
        'event_type',
        'payload',
        'headers',
        'status',
        'response_code',
        'ip_address',
    ];

    public function decodedPayload(): array
    {
        return json_decode($this->payload ?? '{}', true) ?? [];
    }

    public function decodedHeaders(): array
    {
        return json_decode($this->headers ?? '{}', true) ?? [];
    }
}
