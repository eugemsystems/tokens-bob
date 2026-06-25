<?php

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Gateways\PesepayGateway;

beforeEach(function () {
    $key = str_pad('testkey', 32, 'x');
    config(['pesepay.encryption_key' => $key]);
});

function encryptPayload(array $data): string
{
    $key = config('pesepay.encryption_key');
    $iv = substr($key, 0, 16);

    return openssl_encrypt(json_encode($data), 'AES-256-CBC', $key, 0, $iv);
}

it('marks transaction completed when pesepay sends PROCESSED status', function () {
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    $merchantRef = 'VGP-'.$transaction->id.'-abcd1234';
    $referenceNumber = 'REF-'.$transaction->id;

    $payload = encryptPayload([
        'transactionStatus' => 'PROCESSED',
        'merchantReference' => $merchantRef,
        'referenceNumber' => $referenceNumber,
    ]);

    $this->post('/pesepay/result', ['payload' => $payload])
        ->assertStatus(200);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
    expect($transaction->fresh()->gateway_payment_id)->toBe($referenceNumber);
});

it('marks transaction completed when pesepay sends SUCCESS status', function () {
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    $merchantRef = 'VGP-'.$transaction->id.'-abcd5678';
    $referenceNumber = 'REF-'.$transaction->id.'-2';

    $payload = encryptPayload([
        'transactionStatus' => 'SUCCESS',
        'merchantReference' => $merchantRef,
        'referenceNumber' => $referenceNumber,
    ]);

    $this->post('/pesepay/result', ['payload' => $payload])
        ->assertStatus(200);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
});

it('marks transaction failed when pesepay sends FAILED status', function () {
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    $merchantRef = 'VGP-'.$transaction->id.'-abcd9999';

    $payload = encryptPayload([
        'transactionStatus' => 'FAILED',
        'merchantReference' => $merchantRef,
        'referenceNumber' => null,
    ]);

    $this->post('/pesepay/result', ['payload' => $payload])
        ->assertStatus(200);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed);
});
