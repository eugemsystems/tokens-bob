<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Setting;
use App\Services\Gateways\DpoGateway;
use App\Services\Gateways\FlutterwaveGateway;
use App\Services\Gateways\PayFastGateway;
use App\Services\Gateways\PaystackGateway;
use App\Services\Gateways\PeachPaymentsGateway;
use App\Services\Gateways\PesepayGateway;
use App\Services\Gateways\SnapScanGateway;
use App\Services\Gateways\WhopGateway;
use InvalidArgumentException;

class GatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(
        PayFastGateway $payfast,
        SnapScanGateway $snapscan,
        DpoGateway $dpo,
        PeachPaymentsGateway $peach,
        FlutterwaveGateway $flutterwave,
        PaystackGateway $paystack,
        WhopGateway $whop,
        PesepayGateway $pesepay,
    ) {
        $this->gateways = [
            $payfast->getKey() => $payfast,
            $snapscan->getKey() => $snapscan,
            $dpo->getKey() => $dpo,
            $peach->getKey() => $peach,
            $flutterwave->getKey() => $flutterwave,
            $paystack->getKey() => $paystack,
            $whop->getKey() => $whop,
            $pesepay->getKey() => $pesepay,
        ];
    }

    public function active(): PaymentGateway
    {
        $key = Setting::get('default_gateway', 'payfast');

        return $this->gateways[$key] ?? throw new InvalidArgumentException("Unknown gateway: {$key}");
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }

    public function find(string $key): PaymentGateway
    {
        return $this->gateways[$key] ?? throw new InvalidArgumentException("Unknown gateway: {$key}");
    }
}
