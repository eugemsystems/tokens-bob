<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PesepayLog extends Model
{
    protected $fillable = [
        'transaction_id',
        'event',
        'reference_number',
        'payment_method',
        'http_status',
        'transaction_status',
        'status_code',
        'status_description',
        'raw_payload',
        'success',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'success' => 'boolean',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
