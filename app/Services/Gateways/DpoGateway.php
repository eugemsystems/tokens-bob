<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DpoGateway implements PaymentGateway
{
    public function getKey(): string
    {
        return 'dpo';
    }

    public function getName(): string
    {
        return 'DPO Pay';
    }

    public function getCheckoutType(): string
    {
        return 'redirect';
    }

    public function initiate(Transaction $transaction, array $customerData, string $description): array
    {
        $token = $this->createToken($transaction, $customerData, $description);

        if (! $token) {
            return ['success' => false, 'checkout_type' => 'redirect', 'data' => [], 'message' => 'Failed to initiate DPO payment.'];
        }

        $redirectUrl = config('dpo.payment_url').'?ID='.$token;

        return [
            'success'       => true,
            'checkout_type' => 'redirect',
            'data'          => ['redirect_url' => $redirectUrl, 'token' => $token],
            'message'       => '',
        ];
    }

    private function createToken(Transaction $transaction, array $customerData, string $description): ?string
    {
        $nameParts = explode(' ', trim($customerData['email']), 2);
        $xml = $this->buildCreateTokenXml($transaction, $customerData, $description, $nameParts);

        Log::info('DPO: sending createToken request', [
            'transaction_id' => $transaction->id,
            'amount'         => $transaction->amount,
            'description'    => $description,
            'email'          => $customerData['email'],
        ]);

        $response = Http::withHeaders(['Content-Type' => 'application/xml'])
            ->withBody($xml, 'application/xml')
            ->post(config('dpo.api_url'));

        Log::info('DPO: createToken response', [
            'transaction_id' => $transaction->id,
            'status'         => $response->status(),
            'body'           => $response->body(),
        ]);

        if ($response->failed()) {
            return null;
        }

        $parsed = simplexml_load_string($response->body());

        if (! $parsed || (string) $parsed->Result !== '000') {
            return null;
        }

        return (string) $parsed->TransToken;
    }

    private function buildCreateTokenXml(Transaction $transaction, array $customerData, string $description, array $nameParts): string
    {
        $returnUrl  = rtrim(config('app.url'), '/').'/dpo/return';
        $backUrl    = rtrim(config('app.url'), '/').'/dpo/cancel';
        $amount     = number_format((float) $transaction->amount, 2, '.', '');
        $firstName  = htmlspecialchars($nameParts[0] ?? 'Customer');
        $email      = htmlspecialchars($customerData['email']);
        $companyRef = 'TXN-'.$transaction->id;
        $desc       = htmlspecialchars($description);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<API3G>
  <CompanyToken>{$this->companyToken()}</CompanyToken>
  <Request>createToken</Request>
  <Transaction>
    <PaymentAmount>{$amount}</PaymentAmount>
    <PaymentCurrency>ZAR</PaymentCurrency>
    <CompanyRef>{$companyRef}</CompanyRef>
    <RedirectURL>{$returnUrl}</RedirectURL>
    <BackURL>{$backUrl}</BackURL>
    <DefaultPayment>CC</DefaultPayment>
    <customerFirstName>{$firstName}</customerFirstName>
    <customerEmail>{$email}</customerEmail>
  </Transaction>
  <Services>
    <Service>
      <ServiceType>{$this->serviceType()}</ServiceType>
      <ServiceDescription>{$desc}</ServiceDescription>
      <ServiceDate>{$this->serviceDate()}</ServiceDate>
    </Service>
  </Services>
</API3G>
XML;
    }

    public function verifyToken(string $token): ?string
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<API3G>
  <CompanyToken>{$this->companyToken()}</CompanyToken>
  <Request>verifyToken</Request>
  <TransactionToken>{$token}</TransactionToken>
</API3G>
XML;

        $response = Http::withHeaders(['Content-Type' => 'application/xml'])
            ->withBody($xml, 'application/xml')
            ->post(config('dpo.api_url'));

        Log::info('DPO: verifyToken response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->failed()) {
            return null;
        }

        $parsed = simplexml_load_string($response->body());

        if (! $parsed) {
            return null;
        }

        return (string) $parsed->Result;
    }

    private function companyToken(): string
    {
        return config('dpo.company_token', '');
    }

    private function serviceType(): string
    {
        return config('dpo.service_type', '');
    }

    private function serviceDate(): string
    {
        return now()->format('Y/m/d H:i');
    }
}
