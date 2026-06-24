<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class GatewaySeeder extends Seeder
{
    public function run(): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->command->warn('.env file not found — skipping gateway seeder.');

            return;
        }

        $keys = [
            // Whop
            'WHOP_API_KEY' => 'apik_cIOzzUKbBbGtd_C12990_C_3eb62d0c801a3d755bafa7af77c274e77df80870353446dc8072e1b86f1b70',
            'WHOP_COMPANY_ID' => 'biz_KTGAs028WtIciE',
            'WHOP_WEBHOOK_SECRET' => 'ws_c2b633951abe6eecdb6300ada40239f70eb86d8ba99ec8265c56fec830f266ec',
            'WHOP_CURRENCY' => 'usd',
            'WHOP_SANDBOX' => 'true',
            'XWHOP_API_KEY' => 'apik_cIOzzUKbBbGtd_C12990_C_3eb62d0c801a3d755bafa7af77c274e77df80870353446dc8072e1b86f1b70',
            'XWHOP_COMPANY_ID' => 'biz_KTGAs028WtIciE',
            'XWHOP_WEBHOOK_SECRET' => 'ws_c2b633951abe6eecdb6300ada40239f70eb86d8ba99ec8265c56fec830f266ec',

            // PayFast
            'PAYFAST_MERCHANT_ID' => '10027914',
            'PAYFAST_MERCHANT_KEY' => 'dmerp870dsu2y',
            'PAYFAST_PASSPHRASE' => 'Strawberry141189',
            'PAYFAST_SANDBOX' => 'true',
            'PAYFAST_NOTIFY_URL' => 'https://stag-upward-turtle.ngrok-free.app/payfast/notify',

            // Paystack
            'PAYSTACK_SECRET_KEY' => 'sk_test_0f4478d403c8ecb32b36163e5b1fa474798fa68d',
            'PAYSTACK_PUBLIC_KEY' => 'pk_test_04d84406c6c364aefe15c3bd0f4b8de97198ee57',

            // SnapScan
            'SNAPSCAN_API_KEY' => '',
            'SNAPSCAN_SNAP_CODE' => '',

            // Pese Pay
            'PESEPAY_SANDBOX' => 'true',
            'PESEPAY_INTEGRATION_KEY' => '3fe9bb3a-e387-4359-b0aa-5291107dc32b',
            'PESEPAY_ENCRYPTION_KEY' => 'd54ebfbe1f31449096992ed978ac1c73',
            'PESEPAY_CURRENCY_CODE' => 'USD',
        ];

        $env = file_get_contents($envPath);
        $changed = false;

        foreach ($keys as $key => $value) {
            if (preg_match("/^{$key}=.*/m", $env)) {
                $env = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
                $changed = true;
            } else {
                $env .= PHP_EOL."{$key}={$value}";
                $changed = true;
            }

            $this->command->info("Set {$key}");
        }

        if ($changed) {
            file_put_contents($envPath, $env);
            $this->command->info('Gateway keys written to .env');
        }
    }
}
