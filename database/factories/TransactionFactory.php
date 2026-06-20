<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pf_payment_id' => fake()->optional()->uuid(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => fake()->numerify('07########'),
            'amount' => fake()->randomFloat(2, 50, 500),
            'status' => TransactionStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'pf_payment_id' => fake()->uuid(),
            'status' => TransactionStatus::Completed,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => TransactionStatus::Failed,
        ]);
    }
}
