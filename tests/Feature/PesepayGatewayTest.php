<?php

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\Gateways\PesepayGateway;
use Mockery\MockInterface;

beforeEach(function () {
    config([
        'pesepay.integration_key' => '3fe9bb3a-e387-4359-b0aa-5291107dc32b',
        'pesepay.encryption_key' => 'd54ebfbe1f31449096992ed978ac1c73',
        'pesepay.sandbox' => true,
        'pesepay.currency_code' => 'USD',
    ]);
});

function pesepayEncrypt(array $data): string
{
    $key = config('pesepay.encryption_key');
    $iv = substr($key, 0, 16);

    return openssl_encrypt(json_encode($data), 'AES-256-CBC', $key, 0, $iv);
}

/**
 * Build a fake curlRequest response as PesePay would return.
 *
 * @param  array<string, mixed>  $decryptedPayload
 */
function fakeCurlOk(array $decryptedPayload): array
{
    $encrypted = pesepayEncrypt($decryptedPayload);
    $body = json_encode(['payload' => $encrypted]);

    return [
        'status' => 200,
        'body' => $body,
        'json' => ['payload' => $encrypted],
        'succeeded' => true,
    ];
}

function fakeCurlFail(int $status = 500): array
{
    return [
        'status' => $status,
        'body' => '{"message":"Internal Server Error"}',
        'json' => ['message' => 'Internal Server Error'],
        'succeeded' => false,
    ];
}

/** @return MockInterface&PesepayGateway */
function pesepayGateway(): PesepayGateway
{
    return Mockery::mock(PesepayGateway::class)
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
}

it('initiate returns seamless checkout type with payment methods including card', function () {
    $gateway = app(PesepayGateway::class);
    $transaction = Transaction::factory()->create(['amount' => 10.00]);

    $result = $gateway->initiate($transaction, ['email' => 'test@example.com', 'phone' => '0777777777'], 'Token purchase');

    $methods = $result['data']['payment_methods'];
    $codes = array_column($methods, 'code');

    expect($result['success'])->toBeTrue()
        ->and($result['checkout_type'])->toBe('seamless')
        ->and($codes)->toContain('PZW211')
        ->and($codes)->toContain('PZW204')
        ->and($codes)->toContain('PZW205');
});

it('makeCardPayment sends encrypted card payload and returns reference number on success', function () {
    $transaction = Transaction::factory()->create([
        'amount' => 10.00,
        'customer_email' => 'test@example.com',
    ]);

    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')
        ->once()
        ->andReturn(fakeCurlOk([
            'referenceNumber' => 'REF-2026-CARD-001',
            'transactionStatus' => 'PENDING',
        ]));

    $result = $gateway->makeCardPayment($transaction, 'PZW204', '4867960000005461', '08/27', '123');

    expect($result['success'])->toBeTrue()
        ->and($result['reference_number'])->toBe('REF-2026-CARD-001');
});

it('makeCardPayment returns error when API call fails', function () {
    $transaction = Transaction::factory()->create(['amount' => 10.00]);

    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')->once()->andReturn(fakeCurlFail());

    $result = $gateway->makeCardPayment($transaction, 'PZW204', '4867960000005461', '08/27', '123');

    expect($result['success'])->toBeFalse()
        ->and($result['reference_number'])->toBe('');
});

it('initiateTransaction returns redirect url for card payment', function () {
    $transaction = Transaction::factory()->create(['amount' => 10.00]);

    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')
        ->once()
        ->andReturn(fakeCurlOk([
            'referenceNumber' => 'REF-2026-CARD-001',
            'redirectUrl' => 'https://api.test.sandbox.pesepay.com/payments/redirect/REF-2026-CARD-001',
        ]));

    $result = $gateway->initiateTransaction($transaction);

    expect($result['success'])->toBeTrue()
        ->and($result['reference_number'])->toBe('REF-2026-CARD-001')
        ->and($result['redirect_url'])->toContain('REF-2026-CARD-001');
});

it('initiateTransaction returns error when API call fails', function () {
    $transaction = Transaction::factory()->create(['amount' => 10.00]);

    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')->once()->andReturn(fakeCurlFail());

    $result = $gateway->initiateTransaction($transaction);

    expect($result['success'])->toBeFalse()
        ->and($result['redirect_url'])->toBe('');
});

it('makePayment sends encrypted payload and returns reference number on success', function () {
    $transaction = Transaction::factory()->create([
        'amount' => 10.00,
        'customer_email' => 'test@example.com',
        'customer_phone' => '0777777777',
    ]);

    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')
        ->once()
        ->andReturn(fakeCurlOk([
            'referenceNumber' => 'REF-2026-TEST-001',
            'pollUrl' => 'https://api.test.sandbox.pesepay.com/payments-engine/v1/payments/check-payment',
            'transactionStatus' => 'PENDING',
        ]));

    $result = $gateway->makePayment($transaction, 'PZW211', '0777777777');

    expect($result['success'])->toBeTrue()
        ->and($result['reference_number'])->toBe('REF-2026-TEST-001');
});

it('makePayment returns error when API call fails', function () {
    $transaction = Transaction::factory()->create(['amount' => 10.00]);

    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')->once()->andReturn(fakeCurlFail());

    $result = $gateway->makePayment($transaction, 'PZW211', '0777777777');

    expect($result['success'])->toBeFalse()
        ->and($result['reference_number'])->toBe('');
});

it('checkStatus decrypts and returns the transaction status', function () {
    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')
        ->once()
        ->andReturn(fakeCurlOk([
            'referenceNumber' => 'REF-2026-TEST-001',
            'transactionStatus' => 'SUCCESS',
        ]));

    $status = $gateway->checkStatus('REF-2026-TEST-001');

    expect($status)->not->toBeNull()
        ->and($status['transaction_status'])->toBe('SUCCESS')
        ->and($status['reference_number'])->toBe('REF-2026-TEST-001');
});

it('checkStatus returns null when API call fails', function () {
    $gateway = pesepayGateway();
    $gateway->shouldReceive('sendRequest')->once()->andReturn(null);

    $status = $gateway->checkStatus('REF-2026-TEST-001');

    expect($status)->toBeNull();
});

it('result controller marks transaction completed and tokens sold on SUCCESS webhook', function () {
    $transaction = Transaction::factory()->create([
        'amount' => 10.00,
        'status' => TransactionStatus::Pending,
        'gateway' => 'pesepay',
    ]);

    $token = Token::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => TokenStatus::Reserved,
    ]);

    $encryptedPayload = pesepayEncrypt([
        'referenceNumber' => 'REF-2026-TEST-001',
        'transactionStatus' => 'SUCCESS',
        'merchantReference' => 'TXN-'.$transaction->id,
    ]);

    $this->postJson('/pesepay/result', ['payload' => $encryptedPayload])
        ->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and($transaction->fresh()->gateway_payment_id)->toBe('REF-2026-TEST-001')
        ->and($token->fresh()->status)->toBe(TokenStatus::Sold);
});

it('result controller marks transaction failed and releases tokens on non-SUCCESS webhook', function () {
    $transaction = Transaction::factory()->create([
        'amount' => 10.00,
        'status' => TransactionStatus::Pending,
        'gateway' => 'pesepay',
    ]);

    $token = Token::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => TokenStatus::Reserved,
    ]);

    $encryptedPayload = pesepayEncrypt([
        'referenceNumber' => 'REF-2026-TEST-002',
        'transactionStatus' => 'FAILED',
        'merchantReference' => 'TXN-'.$transaction->id,
    ]);

    $this->postJson('/pesepay/result', ['payload' => $encryptedPayload])
        ->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed)
        ->and($token->fresh()->status)->toBe(TokenStatus::Available)
        ->and($token->fresh()->transaction_id)->toBeNull();
});

it('result controller is idempotent for already-completed transactions', function () {
    $transaction = Transaction::factory()->create([
        'amount' => 10.00,
        'status' => TransactionStatus::Completed,
        'gateway_payment_id' => 'REF-EXISTING',
        'gateway' => 'pesepay',
    ]);

    $encryptedPayload = pesepayEncrypt([
        'referenceNumber' => 'REF-2026-TEST-003',
        'transactionStatus' => 'SUCCESS',
        'merchantReference' => 'TXN-'.$transaction->id,
    ]);

    $this->postJson('/pesepay/result', ['payload' => $encryptedPayload])
        ->assertOk();

    // Should not change the existing gateway_payment_id
    expect($transaction->fresh()->gateway_payment_id)->toBe('REF-EXISTING');
});
