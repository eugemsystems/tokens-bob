<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PesepayStatusCheck extends Model
{
    protected $fillable = [
        'batch_id',
        'transaction_id',
        'reference_number',
        'status_before',
        'status_returned',
        'status_after',
        'was_updated',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'was_updated' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
