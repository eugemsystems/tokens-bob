<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'pf_payment_id',
        'customer_email',
        'customer_phone',
        'amount',
        'status',
        'gateway',
        'gateway_payment_id',
        'is_webhook_purchase',
    ];

    protected function casts(): array
    {
        return [
            'amount'              => 'decimal:2',
            'status'              => TransactionStatus::class,
            'is_webhook_purchase' => 'boolean',
        ];
    }

    public function token(): HasOne
    {
        return $this->hasOne(Token::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }
}
