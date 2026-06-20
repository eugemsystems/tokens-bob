<?php

namespace Database\Factories;

use App\Enums\TokenStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Token>
 */
class TokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'token_code' => strtoupper(fake()->bothify('????-????-????-????')),
            'status' => TokenStatus::Available,
            'transaction_id' => null,
        ];
    }

    public function sold(): static
    {
        return $this->state(fn () => [
            'status' => TokenStatus::Sold,
            'transaction_id' => Transaction::factory()->completed(),
        ]);
    }

    public function reserved(): static
    {
        return $this->state(fn () => [
            'status' => TokenStatus::Reserved,
        ]);
    }
}
