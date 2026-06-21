<?php

namespace App\Models;

use App\Enums\TokenStatus;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'description',
        'image',
        'is_token',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_token' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->image) {
                    return null;
                }
                if (str_starts_with($this->image, 'http://') || str_starts_with($this->image, 'https://')) {
                    return $this->image;
                }

                return Storage::url($this->image);
            }
        );
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
