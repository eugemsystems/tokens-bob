<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'default_gateway' => 'whop',
            'webhook_url' => 'https://gateway.test/api/v1/webhooks/test',
            'whop_prefill_email' => 'tests@gmail.com',
            'whop_email_pool' => implode("\n", [
                'test@gmail.com',
            ]),
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
