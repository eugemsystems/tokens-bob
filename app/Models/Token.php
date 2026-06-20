<?php

namespace App\Models;

use App\Enums\TokenStatus;
use Database\Factories\TokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Token extends Model
{
    /** @use HasFactory<TokenFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'token_code',
        'status',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'token_code' => 'encrypted',
            'status' => TokenStatus::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
