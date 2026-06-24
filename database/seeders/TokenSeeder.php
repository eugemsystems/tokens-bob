<?php

namespace Database\Seeders;

use App\Enums\TokenStatus;
use App\Models\Category;
use App\Models\Token;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class TokenSeeder extends Seeder
{
    private const CATEGORIES = [
        [
            'name' => 'Netflix Premium 1 Month',
            'price' => 9.99,
            'description' => '4K Ultra HD + HDR, 4 screens simultaneously.',
            'tokens' => [
                'NF-PREM-A1B2-C3D4',
                'NF-PREM-E5F6-G7H8',
                'NF-PREM-I9J0-K1L2',
                'NF-PREM-M3N4-O5P6',
                'NF-PREM-Q7R8-S9T0',
            ],
        ],
        [
            'name' => 'Netflix Standard 1 Month',
            'price' => 7.99,
            'description' => 'Full HD, 2 screens simultaneously.',
            'tokens' => [
                'NF-STD-A1B2-C3D4',
                'NF-STD-E5F6-G7H8',
                'NF-STD-I9J0-K1L2',
                'NF-STD-M3N4-O5P6',
                'NF-STD-Q7R8-S9T0',
            ],
        ],
        [
            'name' => 'Showmax Pro 1 Month',
            'price' => 6.99,
            'description' => 'Live sport + movies & series in Full HD.',
            'tokens' => [
                'SM-PRO-A1B2-C3D4',
                'SM-PRO-E5F6-G7H8',
                'SM-PRO-I9J0-K1L2',
                'SM-PRO-M3N4-O5P6',
                'SM-PRO-Q7R8-S9T0',
            ],
        ],
        [
            'name' => 'Amazon Prime Video 1 Month',
            'price' => 5.99,
            'description' => 'Unlimited movies, series & Amazon originals.',
            'tokens' => [
                'APV-A1B2-C3D4-E5F6',
                'APV-G7H8-I9J0-K1L2',
                'APV-M3N4-O5P6-Q7R8',
                'APV-S9T0-U1V2-W3X4',
                'APV-Y5Z6-A7B8-C9D0',
            ],
        ],
        [
            'name' => 'Disney+ 1 Month',
            'price' => 7.99,
            'description' => 'Disney, Marvel, Star Wars, Pixar & National Geographic.',
            'tokens' => [
                'DSN-A1B2-C3D4-E5F6',
                'DSN-G7H8-I9J0-K1L2',
                'DSN-M3N4-O5P6-Q7R8',
                'DSN-S9T0-U1V2-W3X4',
                'DSN-Y5Z6-A7B8-C9D0',
            ],
        ],
        [
            'name' => 'DSTV Premium 1 Month',
            'price' => 19.99,
            'description' => 'Full bouquet including sports, movies & news.',
            'tokens' => [
                'DSTV-PREM-A1B2-C3D4',
                'DSTV-PREM-E5F6-G7H8',
                'DSTV-PREM-I9J0-K1L2',
                'DSTV-PREM-M3N4-O5P6',
                'DSTV-PREM-Q7R8-S9T0',
            ],
        ],
        [
            'name' => 'Apple TV+ 1 Month',
            'price' => 4.99,
            'description' => 'Apple originals — ad-free, 4K HDR.',
            'tokens' => [
                'ATV-A1B2-C3D4-E5F6',
                'ATV-G7H8-I9J0-K1L2',
                'ATV-M3N4-O5P6-Q7R8',
                'ATV-S9T0-U1V2-W3X4',
                'ATV-Y5Z6-A7B8-C9D0',
            ],
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::CATEGORIES as $data) {
            $category = Category::firstOrCreate(
                ['name' => $data['name']],
                [
                    'price' => $data['price'],
                    'description' => $data['description'],
                    'is_token' => true,
                ],
            );

            $rows = array_map(fn (string $code) => [
                'category_id' => $category->id,
                'token_code' => Crypt::encrypt($code),
                'status' => TokenStatus::Available->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $data['tokens']);

            Token::insert($rows);

            $this->command->info("  {$category->name} — ".count($rows).' tokens added.');
        }
    }
}
