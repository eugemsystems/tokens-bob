<?php

namespace App\Models;

use App\Enums\TokenStatus;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'description',
        'is_token',
    ];

    protected function casts(): array
    {
        return [
            'price'    => 'decimal:2',
            'is_token' => 'boolean',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    public function availableTokens(): HasMany
    {
        return $this->hasMany(Token::class)->where('status', TokenStatus::Available);
    }
}
