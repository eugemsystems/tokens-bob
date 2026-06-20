<?php

use App\Actions\InitiateCartCheckout;
use App\Actions\InitiateCheckout;
use App\Contracts\PaymentGateway;
use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\GatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Replace the GatewayManager in the container with a stub whose active()
 * gateway returns the given result from initiate().
 *
 * @param  array<string, mixed>  $initiateResult
 */
function mockGateway(array $initiateResult, string $checkoutType = 'onsite'): void
{
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('getKey')->andReturn('payfast');
    $gateway->shouldReceive('getCheckoutType')->andReturn($checkoutType);
    $gateway->shouldReceive('initiate')->andReturn($initiateResult);

    $manager = Mockery::mock(GatewayManager::class);
    $manager->shouldReceive('active')->andReturn($gateway);

    app()->instance(GatewayManager::class, $manager);
}

// ── InitiateCheckout ──────────────────────────────────────────────────────────

it('reserves a token and returns checkout data on successful gateway call', function () {
    $category = Category::factory()->create(['price' => 99.00]);
    $token    = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    mockGateway([
        'success'       => true,
        'checkout_type' => 'onsite',
        'data'          => ['uuid' => 'test-uuid-1234'],
        'message'       => '',
    ]);

    $result = app(InitiateCheckout::class)->execute(
        $category,
        ['email' => 'buyer@example.com', 'phone' => '0720000000']
    );

    expect($result['success'])->toBeTrue()
        ->and($result['checkout_type'])->toBe('onsite')
        ->and($result['data']['uuid'])->toBe('test-uuid-1234')
        ->and($result['transaction_id'])->not->toBeNull()
        ->and($result['token_id'])->toBe($token->id);

    expect($token->fresh()->status)->toBe(TokenStatus::Reserved);
    expect(Transaction::find($result['transaction_id'])->status)->toBe(TransactionStatus::Pending);
});

it('returns failure and rolls back when gateway call fails', function () {
    $category = Category::factory()->create(['price' => 99.00]);
    $token    = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    mockGateway([
        'success'       => false,
        'checkout_type' => 'onsite',
        'data'          => [],
        'message'       => 'Gateway error',
    ]);

    $result = app(InitiateCheckout::class)->execute(
        $category,
        ['email' => 'buyer@example.com', 'phone' => '0720000000']
    );

    expect($result['success'])->toBeFalse();
    expect($token->fresh()->status)->toBe(TokenStatus::Available);
});

it('returns failure when no tokens are available', function () {
    $category = Category::factory()->create();

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('getKey')->andReturn('payfast');
    $gateway->shouldReceive('getCheckoutType')->andReturn('onsite');
    $gateway->shouldNotReceive('initiate');

    $manager = Mockery::mock(GatewayManager::class);
    $manager->shouldReceive('active')->andReturn($gateway);
    app()->instance(GatewayManager::class, $manager);

    $result = app(InitiateCheckout::class)->execute(
        $category,
        ['email' => 'buyer@example.com', 'phone' => '0720000000']
    );

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('No tokens');
});

// ── InitiateCartCheckout ──────────────────────────────────────────────────────

it('creates one transaction with total amount and reserves all tokens', function () {
    $cat1   = Category::factory()->create(['price' => 99.00]);
    $cat2   = Category::factory()->create(['price' => 149.00]);
    $token1 = Token::factory()->for($cat1)->create(['status' => TokenStatus::Available]);
    $token2 = Token::factory()->for($cat2)->create(['status' => TokenStatus::Available]);
    $token3 = Token::factory()->for($cat2)->create(['status' => TokenStatus::Available]);

    mockGateway([
        'success'       => true,
        'checkout_type' => 'onsite',
        'data'          => ['uuid' => 'cart-uuid-001'],
        'message'       => '',
    ]);

    $result = app(InitiateCartCheckout::class)->execute(
        cart: [$cat1->id => 1, $cat2->id => 2],
        customerData: ['email' => 'buyer@example.com', 'phone' => '0720000000'],
    );

    expect($result['success'])->toBeTrue();

    $transaction = Transaction::find($result['transaction_id']);
    expect((float) $transaction->amount)->toBe(99.00 + 149.00 + 149.00);
    expect($transaction->tokens()->count())->toBe(3);
    expect($token1->fresh()->status)->toBe(TokenStatus::Reserved);
    expect($token2->fresh()->status)->toBe(TokenStatus::Reserved);
    expect($token3->fresh()->status)->toBe(TokenStatus::Reserved);
});

it('cart checkout rolls back all reservations when gateway fails', function () {
    $category = Category::factory()->create(['price' => 50.00]);
    $token1   = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);
    $token2   = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    mockGateway([
        'success'       => false,
        'checkout_type' => 'onsite',
        'data'          => [],
        'message'       => 'Gateway error',
    ]);

    $result = app(InitiateCartCheckout::class)->execute(
        cart: [$category->id => 2],
        customerData: ['email' => 'buyer@example.com', 'phone' => '0720000000'],
    );

    expect($result['success'])->toBeFalse();
    expect($token1->fresh()->status)->toBe(TokenStatus::Available);
    expect($token2->fresh()->status)->toBe(TokenStatus::Available);
});

it('cart checkout fails gracefully when a category has insufficient stock', function () {
    $category = Category::factory()->create(['price' => 99.00]);
    Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    mockGateway(['success' => true, 'checkout_type' => 'onsite', 'data' => ['uuid' => 'x'], 'message' => '']);

    $result = app(InitiateCartCheckout::class)->execute(
        cart: [$category->id => 2],
        customerData: ['email' => 'buyer@example.com', 'phone' => '0720000000'],
    );

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('No tokens available');
});

// ── PayFast IPN Controller ────────────────────────────────────────────────────

it('marks transaction completed and token sold on COMPLETE itn with valid signature', function () {
    $category    = Category::factory()->create();
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending, 'amount' => 99.00]);
    $token       = Token::factory()->for($category)->create([
        'status'         => TokenStatus::Reserved,
        'transaction_id' => $transaction->id,
    ]);

    $passphrase = config('payfast.passphrase', '');
    $data       = [
        'merchant_id'    => '10004002',
        'm_payment_id'   => (string) $transaction->id,
        'pf_payment_id'  => 'pf-abc-123',
        'payment_status' => 'COMPLETE',
        'amount_gross'   => '99.00',
    ];

    ksort($data);
    $qs = collect($data)->map(fn ($v, $k) => $k.'='.urlencode((string) $v))->implode('&');
    if ($passphrase !== '') {
        $qs .= '&passphrase='.urlencode($passphrase);
    }
    $data['signature'] = md5($qs);

    $this->post(route('payfast.notify'), $data)->assertStatus(200);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Completed);
    expect($token->fresh()->status)->toBe(TokenStatus::Sold);
});

it('rejects itn with invalid signature', function () {
    $transaction = Transaction::factory()->create();

    $this->post(route('payfast.notify'), [
        'm_payment_id'   => (string) $transaction->id,
        'payment_status' => 'COMPLETE',
        'signature'      => 'invalid',
    ])->assertStatus(400);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Pending);
});

// ── Storefront: finalizeOrder polls for ITN ───────────────────────────────────

it('finalizeOrder shows token immediately when transaction is already completed', function () {
    $category    = Category::factory()->create(['price' => 99.00]);
    $transaction = Transaction::factory()->completed()->create(['amount' => 99.00]);
    $token       = Token::factory()->for($category)->create([
        'status'         => TokenStatus::Sold,
        'transaction_id' => $transaction->id,
    ]);

    Livewire::test('pages::storefront')
        ->set('pendingTransactionId', $transaction->id)
        ->set('pendingTokenId', $token->id)
        ->set('step', 2)
        ->call('finalizeOrder')
        ->assertSet('paymentSucceeded', true)
        ->assertSet('purchasedTokenCode', $token->token_code)
        ->assertSet('pollingForItn', false)
        ->assertSet('step', 3);
});

it('finalizeOrder starts polling when itn has not yet arrived', function () {
    $category    = Category::factory()->create();
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    $token       = Token::factory()->for($category)->create([
        'status'         => TokenStatus::Reserved,
        'transaction_id' => $transaction->id,
    ]);

    Livewire::test('pages::storefront')
        ->set('pendingTransactionId', $transaction->id)
        ->set('pendingTokenId', $token->id)
        ->set('step', 2)
        ->call('finalizeOrder')
        ->assertSet('pollingForItn', true)
        ->assertSet('paymentSucceeded', false)
        ->assertSet('step', 3);

    expect($token->fresh()->status)->toBe(TokenStatus::Reserved);
    expect($transaction->fresh()->status)->toBe(TransactionStatus::Pending);
});

it('pollPaymentStatus reveals token once itn marks transaction completed', function () {
    $category    = Category::factory()->create();
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    $token       = Token::factory()->for($category)->create([
        'status'         => TokenStatus::Reserved,
        'transaction_id' => $transaction->id,
    ]);

    $component = Livewire::test('pages::storefront')
        ->set('pendingTransactionId', $transaction->id)
        ->set('pendingTokenId', $token->id)
        ->set('pollingForItn', true)
        ->set('pollAttempts', 0)
        ->set('step', 3);

    $transaction->update(['status' => TransactionStatus::Completed]);
    $token->update(['status' => TokenStatus::Sold]);

    $component->call('pollPaymentStatus')
        ->assertSet('paymentSucceeded', true)
        ->assertSet('pollingForItn', false)
        ->assertSet('purchasedTokenCode', $token->token_code);
});

it('pollPaymentStatus shows error after max attempts without itn', function () {
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    $token       = Token::factory()->create([
        'status'         => TokenStatus::Reserved,
        'transaction_id' => $transaction->id,
    ]);

    Livewire::test('pages::storefront')
        ->set('pendingTransactionId', $transaction->id)
        ->set('pendingTokenId', $token->id)
        ->set('pollingForItn', true)
        ->set('pollAttempts', 19)
        ->set('step', 3)
        ->call('pollPaymentStatus')
        ->assertSet('pollingForItn', false)
        ->assertSet('paymentSucceeded', false);
});
