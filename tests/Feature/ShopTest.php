<?php

use App\Actions\InitiateCartCheckout;
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

function mockShopGateway(array $initiateResult, string $checkoutType = 'onsite'): void
{
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('getKey')->andReturn('payfast');
    $gateway->shouldReceive('getCheckoutType')->andReturn($checkoutType);
    $gateway->shouldReceive('initiate')->andReturn($initiateResult);

    $manager = Mockery::mock(GatewayManager::class);
    $manager->shouldReceive('active')->andReturn($gateway);

    app()->instance(GatewayManager::class, $manager);
}

// ── Cart ──────────────────────────────────────────────────────────────────────

it('renders the shop page', function () {
    Livewire::test('pages::shop')->assertOk();
});

it('can add an available token to the cart', function () {
    $category = Category::factory()->create();
    Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    Livewire::test('pages::shop')
        ->call('addToCart', $category->id)
        ->assertSet('cart', [$category->id => 1]);
});

it('does not add to cart when category has no available tokens', function () {
    $category = Category::factory()->create();

    Livewire::test('pages::shop')
        ->call('addToCart', $category->id)
        ->assertSet('cart', []);
});

it('increments quantity when adding the same category again', function () {
    $category = Category::factory()->create();
    Token::factory()->for($category)->count(3)->create(['status' => TokenStatus::Available]);

    Livewire::test('pages::shop')
        ->call('addToCart', $category->id)
        ->call('addToCart', $category->id)
        ->assertSet('cart', [$category->id => 2]);
});

it('does not exceed available stock when adding to cart', function () {
    $category = Category::factory()->create();
    Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    Livewire::test('pages::shop')
        ->set('cart', [$category->id => 1])
        ->call('addToCart', $category->id)
        ->assertSet('cart', [$category->id => 1]);
});

it('decrements quantity when removing from cart', function () {
    $category = Category::factory()->create();
    Token::factory()->for($category)->count(3)->create(['status' => TokenStatus::Available]);

    Livewire::test('pages::shop')
        ->set('cart', [$category->id => 2])
        ->call('removeFromCart', $category->id)
        ->assertSet('cart', [$category->id => 1]);
});

it('removes the category from cart when quantity reaches zero', function () {
    $category = Category::factory()->create();
    Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    $component = Livewire::test('pages::shop')
        ->set('cart', [$category->id => 1])
        ->call('removeFromCart', $category->id);

    expect($component->get('cart'))->not->toHaveKey($category->id);
});

it('clears the entire cart', function () {
    $cat1 = Category::factory()->create();
    $cat2 = Category::factory()->create();

    Livewire::test('pages::shop')
        ->set('cart', [$cat1->id => 1, $cat2->id => 2])
        ->call('clearCart')
        ->assertSet('cart', []);
});

// ── Search ────────────────────────────────────────────────────────────────────

it('filters categories by search term', function () {
    Category::factory()->create(['name' => 'Netflix Premium 1 Month']);
    Category::factory()->create(['name' => 'PlayStation Store Credit']);

    Livewire::test('pages::shop')
        ->set('search', 'Netflix')
        ->assertSee('Netflix Premium 1 Month')
        ->assertDontSee('PlayStation Store Credit');
});

it('shows all categories when search is cleared', function () {
    Category::factory()->create(['name' => 'Netflix Premium 1 Month']);
    Category::factory()->create(['name' => 'PlayStation Store Credit']);

    Livewire::test('pages::shop')
        ->set('search', 'Netflix')
        ->set('search', '')
        ->assertSee('Netflix Premium 1 Month')
        ->assertSee('PlayStation Store Credit');
});

// ── Checkout ──────────────────────────────────────────────────────────────────

it('redirects to checkout page when cart has items', function () {
    $category = Category::factory()->create();
    Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    Livewire::test('pages::shop')
        ->set('cart', [$category->id => 1])
        ->call('startCheckout')
        ->assertRedirect(route('checkout'));

    expect(session('checkout_cart'))->toBe([$category->id => 1]);
});

it('does not redirect to checkout when cart is empty', function () {
    Livewire::test('pages::shop')
        ->call('startCheckout')
        ->assertNoRedirect();
});

it('creates one transaction with total amount for all cart items on goToPayment', function () {
    $cat1 = Category::factory()->create(['price' => 99.00]);
    $cat2 = Category::factory()->create(['price' => 149.00]);
    Token::factory()->for($cat1)->create(['status' => TokenStatus::Available]);
    Token::factory()->for($cat2)->count(2)->create(['status' => TokenStatus::Available]);

    mockShopGateway([
        'success'       => true,
        'checkout_type' => 'onsite',
        'data'          => ['uuid' => 'test-uuid-001'],
        'message'       => '',
    ]);

    Livewire::test('pages::shop')
        ->set('cart', [$cat1->id => 1, $cat2->id => 2])
        ->set('customerEmail', 'buyer@example.com')
        ->set('customerPhone', '0720000000')
        ->call('goToPayment')
        ->assertSet('step', 2)
        ->assertSet('checkoutType', 'onsite');

    $transaction = Transaction::latest()->first();
    expect((float) $transaction->amount)->toBe(99.00 + 149.00 + 149.00);
    expect(Token::where('transaction_id', $transaction->id)->count())->toBe(3);
    expect(Token::where('status', TokenStatus::Reserved)->count())->toBe(3);
});

it('goes to step 3 with error when gateway returns failure during checkout', function () {
    $category = Category::factory()->create();
    Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('getKey')->andReturn('payfast');
    $gateway->shouldReceive('getCheckoutType')->andReturn('onsite');
    $gateway->shouldReceive('initiate')->andReturn([
        'success' => false, 'checkout_type' => 'onsite', 'data' => [], 'message' => 'Gateway error',
    ]);
    $manager = Mockery::mock(GatewayManager::class);
    $manager->shouldReceive('active')->andReturn($gateway);
    app()->instance(GatewayManager::class, $manager);

    Livewire::test('pages::shop')
        ->set('cart', [$category->id => 1])
        ->set('customerEmail', 'buyer@example.com')
        ->set('customerPhone', '0720000000')
        ->call('goToPayment')
        ->assertSet('step', 3)
        ->assertSet('paymentError', 'Gateway error');
});

it('redirects to order page when transaction is already completed on finalizeOrder', function () {
    $category    = Category::factory()->create(['price' => 99.00]);
    $transaction = Transaction::factory()->completed()->create(['amount' => 99.00]);
    Token::factory()->for($category)->create([
        'status'         => TokenStatus::Sold,
        'transaction_id' => $transaction->id,
    ]);

    Livewire::test('pages::shop')
        ->set('pendingTransactionId', $transaction->id)
        ->set('step', 2)
        ->call('finalizeOrder')
        ->assertRedirect(route('order', $transaction->id));
});

it('starts polling when transaction is still pending after finalizeOrder', function () {
    $category    = Category::factory()->create();
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    Token::factory()->for($category)->create([
        'status'         => TokenStatus::Reserved,
        'transaction_id' => $transaction->id,
    ]);

    Livewire::test('pages::shop')
        ->set('pendingTransactionId', $transaction->id)
        ->set('step', 2)
        ->call('finalizeOrder')
        ->assertSet('pollingForItn', true)
        ->assertSet('step', 2);
});

it('redirects to order page once poll detects completed transaction', function () {
    $category    = Category::factory()->create();
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    Token::factory()->for($category)->create([
        'status'         => TokenStatus::Reserved,
        'transaction_id' => $transaction->id,
    ]);

    $component = Livewire::test('pages::shop')
        ->set('pendingTransactionId', $transaction->id)
        ->set('pollingForItn', true)
        ->set('pollAttempts', 0)
        ->set('step', 2);

    $transaction->update(['status' => TransactionStatus::Completed]);

    $component->call('pollPaymentStatus')
        ->assertRedirect(route('order', $transaction->id));
});

it('releases all reserved tokens when payment is failed', function () {
    $category    = Category::factory()->create();
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Pending]);
    $token       = Token::factory()->for($category)->create([
        'status'         => TokenStatus::Reserved,
        'transaction_id' => $transaction->id,
    ]);

    Livewire::test('pages::shop')
        ->set('pendingTransactionId', $transaction->id)
        ->set('step', 2)
        ->call('paymentFailed')
        ->assertSet('step', 3)
        ->assertSet('paymentError', 'Payment was cancelled.');

    expect($token->fresh()->status)->toBe(TokenStatus::Available);
    expect($transaction->fresh()->status)->toBe(TransactionStatus::Failed);
});
