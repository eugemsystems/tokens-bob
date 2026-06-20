<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    private const SERVICES = [
        ['name' => 'Netflix Premium 1 Month', 'price' => 249.00],
        ['name' => 'Netflix Standard 1 Month', 'price' => 189.00],
        ['name' => 'Showmax Pro 1 Month', 'price' => 149.00],
        ['name' => 'Amazon Prime Video 1 Month', 'price' => 99.00],
        ['name' => 'IPTV Premium 1 Month', 'price' => 129.00],
        ['name' => 'IPTV Premium 3 Months', 'price' => 349.00],
    ];

    public function definition(): array
    {
        $service = fake()->randomElement(self::SERVICES);

        return [
            'name'        => $service['name'],
            'price'       => $service['price'],
            'description' => fake()->sentence(),
            'is_token'    => true,
        ];
    }
}
