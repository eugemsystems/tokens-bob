<?php

use App\Actions\InitiateCartCheckout;
use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Mail\TokenPurchased;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Contracts\DirectCardGateway;
use App\Services\GatewayManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Shop All Tokens')] #[Layout('layouts.public')] class extends Component
{
    public string $search = '';
    public int $perPage = 12;

    /** @var array<int, int> category_id => quantity */
    public array $cart = [];

    public bool $showCheckout = false;
    public int $step = 1;

    #[Validate('required|email|max:254')]
    public string $customerEmail = '';

    #[Validate('required|string|max:20')]
    public string $customerPhone = '';

    public string $checkoutType = '';
    public string $paymentUuid = '';
    public string $qrUrl = '';
    public string $redirectUrl = '';
    public string $whopCheckoutId = '';
    public string $whopPlanId = '';
    public ?int $pendingTransactionId = null;

    /** @var array<string, mixed> Flutterwave inline checkout data passed to JS */
    public array $flwData = [];

    #[Validate('required|string|min:13|max:19')]
    public string $cardNumber = '';

    #[Validate('required|string|max:100')]
    public string $cardName = '';

    #[Validate('required|string|regex:/^\d{2}\/\d{2,4}$/')]
    public string $cardExpiry = '';

    #[Validate('required|string|min:3|max:4')]
    public string $cardCvv = '';

    public string $cardAuthMode = '';

    public string $cardRef = '';

    #[Validate('required_if:cardAuthMode,send_pin|string|min:4|max:6')]
    public string $cardPin = '';

    #[Validate('required_if:cardAuthMode,send_otp|string|min:4|max:8')]
    public string $cardOtp = '';

    public string $cardAuthMessage = '';

    public string $paymentError = '';
    public bool $pollingForItn = false;
    public int $pollAttempts = 0;

    private const MAX_POLL_ATTEMPTS = 20;

    #[Computed]
    public function categories(): Collection
    {
        return Category::withCount([
            'tokens as available_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Available),
        ])
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('price')
            ->limit($this->perPage)
            ->get();
    }

    #[Computed]
    public function totalCategoriesCount(): int
    {
        return Category::when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->count();
    }

    #[Computed]
    public function cartCategories(): Collection
    {
        if (empty($this->cart)) {
            return collect();
        }

        return Category::withCount([
            'tokens as available_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Available),
        ])->whereIn('id', array_keys($this->cart))->get()->keyBy('id');
    }

    #[Computed]
    public function cartTotal(): float
    {
        $total = 0.0;
        foreach ($this->cart as $categoryId => $qty) {
            $cat = $this->cartCategories->get($categoryId);
            if ($cat) {
                $total += (float) $cat->price * $qty;
            }
        }

        return $total;
    }

    #[Computed]
    public function cartItemCount(): int
    {
        return (int) array_sum($this->cart);
    }

    public function updatedSearch(): void
    {
        $this->perPage = 12;
    }

    public function loadMore(): void
    {
        $this->perPage += 12;
    }

    public function mount(): void
    {
        $addId = (int) request()->query('add', 0);

        if ($addId > 0) {
            $category = Category::withCount([
                'tokens as available_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Available),
            ])->find($addId);

            if ($category) {
                if (! $category->is_token) {
                    // Non-token: always alone
                    $this->cart = [$addId => 1];
                } elseif ($category->available_tokens_count > 0 && ! $this->cartHasNonTokenItem()) {
                    $this->cart[$addId] = 1;
                }
            }
        }
    }

    public function addToCart(int $categoryId): void
    {
        $category = $this->categories->firstWhere('id', $categoryId);

        if (! $category) {
            return;
        }

        if (! $category->is_token) {
            // Non-token items are always alone in the cart — replace whatever was there
            $this->cart = [$categoryId => 1];

            return;
        }

        // Token item: block if cart already contains a non-token item
        if ($this->cartHasNonTokenItem()) {
            return;
        }

        if ($category->available_tokens_count === 0) {
            return;
        }

        $current = $this->cart[$categoryId] ?? 0;

        if ($current < $category->available_tokens_count) {
            $this->cart[$categoryId] = $current + 1;
        }
    }

    private function cartHasNonTokenItem(): bool
    {
        if (empty($this->cart)) {
            return false;
        }

        return Category::whereIn('id', array_keys($this->cart))
            ->where('is_token', false)
            ->exists();
    }

    public function removeFromCart(int $categoryId): void
    {
        $current = $this->cart[$categoryId] ?? 0;

        if ($current <= 1) {
            $cart = $this->cart;
            unset($cart[$categoryId]);
            $this->cart = $cart;
        } else {
            $this->cart[$categoryId] = $current - 1;
        }
    }

    public function clearCart(): void
    {
        $this->cart = [];
    }

    public function startCheckout(): void
    {
        if (empty($this->cart)) {
            return;
        }

        session(['checkout_cart' => $this->cart]);
        $this->redirect(route('checkout'), navigate: true);
    }

    public function goToPayment(): void
    {
        $this->validateOnly('customerEmail');
        $this->validateOnly('customerPhone');

        $result = app(InitiateCartCheckout::class)->execute(
            cart: $this->cart,
            customerData: [
                'email' => $this->customerEmail,
                'phone' => $this->customerPhone,
            ],
        );

        if (! $result['success']) {
            $this->paymentError = $result['message'];
            $this->step         = 3;

            return;
        }

        $this->checkoutType         = $result['checkout_type'];
        $this->pendingTransactionId = $result['transaction_id'];

        if ($result['checkout_type'] === 'onsite') {
            $this->paymentUuid = $result['data']['uuid'];
            $this->dispatch('payfast-uuid-ready', uuid: $result['data']['uuid']);
        } elseif ($result['checkout_type'] === 'qr') {
            $this->qrUrl         = $result['data']['qr_url'];
            $this->pollingForItn = true;
            $this->pollAttempts  = 0;
        } elseif ($result['checkout_type'] === 'redirect') {
            $this->redirectUrl = $result['data']['redirect_url'];
        } elseif ($result['checkout_type'] === 'whop') {
            $this->whopCheckoutId = $result['data']['checkout_id'];
            $this->whopPlanId     = $result['data']['plan_id'];
            $this->pollingForItn  = true;
            $this->pollAttempts   = 0;
        } elseif ($result['checkout_type'] === 'inline') {
            $this->flwData = $result['data'];
            $this->dispatch('flw-open', data: $result['data']);
        } elseif ($result['checkout_type'] === 'popup') {
            $this->redirectUrl   = $result['data']['redirect_url'];
            $this->pollingForItn = true;
            $this->pollAttempts  = 0;
        }

        $this->step = 2;
    }

    public function finalizeOrder(): void
    {
        if (! $this->pendingTransactionId) {
            return;
        }

        $transaction = Transaction::find($this->pendingTransactionId);

        if (! $transaction) {
            $this->paymentError = 'Order not found. Please contact support.';
            $this->step         = 3;

            return;
        }

        if ($transaction->status === TransactionStatus::Completed) {
            $this->collectCompletedTokens($transaction);

            return;
        }

        $this->pollingForItn = true;
        $this->pollAttempts  = 0;
    }

    public function pollPaymentStatus(): void
    {
        if (! $this->pollingForItn) {
            return;
        }

        $this->pollAttempts++;

        $transaction = Transaction::find($this->pendingTransactionId);

        if ($transaction && $transaction->status === TransactionStatus::Completed) {
            $this->collectCompletedTokens($transaction);

            return;
        }

        if ($transaction && $transaction->status === TransactionStatus::Failed) {
            $this->paymentError  = 'Your payment was not completed.';
            $this->pollingForItn = false;
            $this->step          = 3;

            return;
        }

        if ($this->pollAttempts >= self::MAX_POLL_ATTEMPTS) {
            $this->paymentError  = 'Payment confirmation is taking too long. Check your email or contact support with reference #'.$this->pendingTransactionId.'.';
            $this->pollingForItn = false;
            $this->step          = 3;
        }
    }

    public function paymentFailed(): void
    {
        if ($this->pendingTransactionId) {
            DB::transaction(function (): void {
                Token::where('transaction_id', $this->pendingTransactionId)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);

                Transaction::where('id', $this->pendingTransactionId)
                    ->where('status', TransactionStatus::Pending)
                    ->update(['status' => TransactionStatus::Failed]);
            });
        }

        $this->paymentError = 'Payment was cancelled.';
        $this->reset(['paymentUuid', 'pendingTransactionId', 'qrUrl', 'redirectUrl', 'checkoutType',
            'whopCheckoutId', 'whopPlanId', 'pollingForItn', 'pollAttempts', 'flwData',
            'cardAuthMode', 'cardRef', 'cardPin', 'cardOtp', 'cardAuthMessage']);
        $this->step = 3;
    }

    public function finalizeCardPayment(int|string $gatewayTxnId): void
    {
        if (! $this->pendingTransactionId) {
            return;
        }

        $transaction = Transaction::find($this->pendingTransactionId);

        if (! $transaction || $transaction->status === TransactionStatus::Completed) {
            if ($transaction) {
                $this->collectCompletedTokens($transaction);
            }

            return;
        }

        $gateway = app(GatewayManager::class)->active();
        $data    = ($gateway instanceof DirectCardGateway)
            ? $gateway->verifyTransaction($gatewayTxnId)
            : null;

        $verified = $data
            && $data['status'] === 'successful'
            && $data['tx_ref'] === $transaction->gateway_payment_id
            && $data['amount'] >= (float) $transaction->amount
            && $data['currency'] === 'ZAR';

        Log::info('Card payment: inline verification', [
            'transaction_id'  => $transaction->id,
            'gateway_txn_id'  => $gatewayTxnId,
            'gateway'         => $gateway->getKey(),
            'verified'        => $verified,
        ]);

        DB::transaction(function () use ($transaction, $verified, $data): void {
            if ($verified) {
                $transaction->update([
                    'status'             => TransactionStatus::Completed,
                    'gateway_payment_id' => (string) ($data['id'] ?? $transaction->gateway_payment_id),
                ]);

                Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Sold]);
            } else {
                $transaction->update(['status' => TransactionStatus::Failed]);

                Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
            }
        });

        if ($verified) {
            Mail::to($transaction->customer_email)->send(new TokenPurchased($transaction->fresh()));
            $this->collectCompletedTokens($transaction->fresh());

            return;
        }

        $this->paymentError = 'Payment could not be verified. Please contact support with reference #'.$transaction->id.'.';
        $this->flwData      = [];
        $this->step         = 3;
    }

    public function submitCardPayment(): void
    {
        if (! $this->pendingTransactionId) {
            return;
        }

        $transaction = Transaction::find($this->pendingTransactionId);

        if (! $transaction) {
            $this->paymentError = 'Transaction not found.';
            $this->step         = 3;

            return;
        }

        $gateway = app(GatewayManager::class)->active();

        if (! $gateway instanceof DirectCardGateway) {
            return;
        }

        if ($this->cardAuthMode === 'send_otp') {
            $this->validateOnly('cardOtp');

            $result = $gateway->submitOtp($this->cardRef, $this->cardOtp);
            $this->handleChargeResult($result);

            return;
        }

        [$month, $year] = array_pad(explode('/', $this->cardExpiry), 2, '');

        $cardData = [
            'number'       => $this->cardNumber,
            'cvv'          => $this->cardCvv,
            'expiry_month' => trim($month),
            'expiry_year'  => trim($year),
            'name'         => $this->cardName,
        ];

        if ($this->cardAuthMode === 'send_pin') {
            $this->validateOnly('cardPin');

            $result = $gateway->submitPin($this->cardRef, $this->cardPin, $transaction, $cardData);
            $this->handleChargeResult($result);

            return;
        }

        $this->validateOnly('cardNumber');
        $this->validateOnly('cardName');
        $this->validateOnly('cardExpiry');
        $this->validateOnly('cardCvv');

        $result = $gateway->chargeCard($cardData, $transaction);
        $this->handleChargeResult($result);
    }

    private function handleChargeResult(array $result): void
    {
        $status = $result['status'] ?? 'error';

        if ($status === 'success') {
            $this->finalizeCardPayment($result['gateway_txn_id'] ?? 0);

            return;
        }

        if ($status === 'send_pin') {
            $this->cardAuthMode = 'send_pin';
            $this->cardRef      = $result['card_ref'] ?? '';

            return;
        }

        if ($status === 'send_otp') {
            $this->cardAuthMode    = 'send_otp';
            $this->cardRef         = $result['card_ref'] ?? '';
            $this->cardAuthMessage = $result['message'] ?? 'Please enter the OTP sent to your registered phone or email.';

            return;
        }

        if ($status === 'redirect') {
            $this->redirectUrl  = $result['redirect_url'] ?? '';
            $this->checkoutType = 'redirect';

            return;
        }

        $this->paymentError = $result['message'] ?? 'Payment failed. Please check your card details and try again.';
        $this->step         = 3;
    }

    private function collectCompletedTokens(Transaction $transaction): void
    {
        $this->pollingForItn = false;
        $this->redirect(route('order', $transaction->id));
    }
}; ?>

<div style="font-family:'Manrope',sans-serif;background:#111111;min-height:100vh;">

    {{-- ── PAGE HEADER ── --}}
    <div style="background:#161616;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.07);">
        <div class="sec-inner">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                {{-- Live indicator + title --}}
                <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:160px;">
                    <span style="width:7px;height:7px;border-radius:50%;background:#4ade80;flex-shrink:0;animation:pulse 2s infinite;"></span>
                    <h1 style="font-size:clamp(16px,2.5vw,22px);font-weight:900;color:#fff;margin:0;line-height:1.2;white-space:nowrap;">Shop All Tokens</h1>
                </div>

                {{-- Search --}}
                <div style="position:relative;flex:1;min-width:180px;max-width:400px;">
                    <svg style="position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:rgba(255,255,255,0.30);pointer-events:none;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="Search tokens…"
                        style="width:100%;background:#1a1a1a;border:1px solid rgba(255,255,255,0.11);border-radius:10px;padding:9px 12px 9px 34px;color:#fff;font-size:14px;font-family:'Manrope',sans-serif;outline:none;box-sizing:border-box;-webkit-appearance:none;"
                        onfocus="this.style.borderColor='rgba(221,242,71,0.35)'"
                        onblur="this.style.borderColor='rgba(255,255,255,0.11)'"
                    />
                </div>

                {{-- Checkout button --}}
                @if ($this->cartItemCount > 0)
                    <button wire:click="startCheckout" class="btn-primary" style="padding:9px 18px;font-size:13px;white-space:nowrap;flex-shrink:0;">
                        <svg style="width:15px;height:15px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        Checkout ({{ $this->cartItemCount }})
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- ── MAIN CONTENT ── --}}
    <div class="sec-inner" style="padding-top:40px;padding-bottom:80px;">
        <div id="shop-layout">

            {{-- Products grid --}}
            <div>
                @if ($this->categories->isEmpty())
                    <div style="text-align:center;padding:80px 24px;">
                        @if ($search)
                            <p style="color:rgba(255,255,255,0.35);font-family:'Azeret Mono',monospace;font-size:14px;margin-bottom:12px;">
                                No tokens match "<strong style="color:rgba(255,255,255,0.60);">{{ $search }}</strong>".
                            </p>
                            <button wire:click="$set('search', '')" style="background:none;border:1px solid rgba(255,255,255,0.14);border-radius:10px;padding:8px 18px;color:rgba(255,255,255,0.50);font-family:'Manrope',sans-serif;font-size:13px;font-weight:600;">Clear search</button>
                        @else
                            <p style="color:rgba(255,255,255,0.30);font-family:'Azeret Mono',monospace;font-size:14px;">No tokens available right now. Check back soon!</p>
                        @endif
                    </div>
                @else
                    {{-- Card grid --}}
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
                        @foreach ($this->categories as $category)
                            @php
                                $inStock    = $category->available_tokens_count > 0;
                                $qty        = $this->cart[$category->id] ?? 0;
                                $inCart     = $qty > 0;
                                $maxReached = $inStock && $qty >= $category->available_tokens_count;
                                $borderColor = $inCart ? 'rgba(221,242,71,0.35)' : 'rgba(255,255,255,0.07)';
                            @endphp
                            <div
                                style="background:#1a1a1a;border-radius:18px;border:1px solid {{ $borderColor }};overflow:hidden;display:flex;flex-direction:column;transition:border-color 0.2s,box-shadow 0.2s;{{ $inCart ? 'box-shadow:0 0 0 1px rgba(221,242,71,0.10);' : '' }}"
                                onmouseenter="this.style.borderColor='{{ $inCart ? 'rgba(221,242,71,0.60)' : 'rgba(255,255,255,0.16)' }}';this.style.boxShadow='0 6px 24px rgba(0,0,0,0.40)';"
                                onmouseleave="this.style.borderColor='{{ $borderColor }}';this.style.boxShadow='{{ $inCart ? '0 0 0 1px rgba(221,242,71,0.10)' : 'none' }}';"
                            >
                                {{-- Image --}}
                                @if ($category->image_url)
                                    <div style="aspect-ratio:16/9;overflow:hidden;background:#222;">
                                        <img src="{{ $category->image_url }}" alt="{{ $category->name }}" style="width:100%;height:100%;object-fit:cover;" loading="lazy" />
                                    </div>
                                @else
                                    <div style="aspect-ratio:16/9;background:linear-gradient(135deg,#1e1e2e 0%,#252535 100%);display:flex;align-items:center;justify-content:center;">
                                        <svg style="width:38px;height:38px;color:rgba(255,255,255,0.09);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                                    </div>
                                @endif

                                {{-- Card body --}}
                                <div style="padding:16px;flex:1;display:flex;flex-direction:column;gap:12px;">
                                    <div style="flex:1;">
                                        <h3 style="font-size:15px;font-weight:800;color:#fff;margin:0 0 4px;line-height:1.3;">{{ $category->name }}</h3>
                                        @if ($category->description)
                                            <p style="font-size:12px;color:rgba(255,255,255,0.40);line-height:1.6;margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">{{ $category->description }}</p>
                                        @endif
                                    </div>

                                    {{-- Price --}}
                                    <div style="display:flex;align-items:center;justify-content:flex-end;">
                                        <span style="font-size:20px;font-weight:900;color:#fff;line-height:1;">R{{ number_format($category->price, 2) }}</span>
                                    </div>

                                    {{-- Cart controls --}}
                                    @if ($inCart)
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <button wire:click="removeFromCart({{ $category->id }})" style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.11);color:#fff;font-size:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">−</button>
                                            <span style="flex:1;text-align:center;font-size:17px;font-weight:800;color:#DDF247;">{{ $qty }}</span>
                                            <button wire:click="addToCart({{ $category->id }})" {{ $maxReached ? 'disabled' : '' }} style="width:36px;height:36px;border-radius:10px;background:{{ $maxReached ? 'rgba(255,255,255,0.04)' : '#DDF247' }};border:none;color:{{ $maxReached ? 'rgba(255,255,255,0.18)' : '#111' }};font-size:20px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">+</button>
                                        </div>
                                    @elseif ($inStock)
                                        <button wire:click="addToCart({{ $category->id }})" class="btn-primary" style="padding:10px;font-size:13px;justify-content:center;width:100%;box-sizing:border-box;">Add to Cart</button>
                                    @else
                                        <button disabled style="background:rgba(255,255,255,0.04);border:none;border-radius:12px;padding:10px;color:rgba(255,255,255,0.22);font-size:13px;font-weight:700;font-family:'Manrope',sans-serif;width:100%;">Sold Out</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Load more --}}
                    @if ($this->categories->count() < $this->totalCategoriesCount)
                        <div style="text-align:center;margin-top:28px;">
                            <button wire:click="loadMore" style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.12);border-radius:14px;padding:12px 36px;color:#fff;font-family:'Manrope',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:border-color 0.2s;" onmouseenter="this.style.borderColor='rgba(221,242,71,0.35)'" onmouseleave="this.style.borderColor='rgba(255,255,255,0.12)'">
                                <span wire:loading.remove wire:target="loadMore">Load More</span>
                                <span wire:loading wire:target="loadMore">Loading…</span>
                            </button>
                            <p style="margin-top:8px;font-size:12px;color:rgba(255,255,255,0.28);font-family:'Azeret Mono',monospace;">
                                Showing {{ $this->categories->count() }} of {{ $this->totalCategoriesCount }}
                            </p>
                        </div>
                    @endif
                @endif
            </div>

            {{-- ── CART SIDEBAR ── --}}
            <div id="shop-cart">
                <div style="background:#1a1a1a;border-radius:22px;border:1px solid rgba(255,255,255,0.08);overflow:hidden;">

                    {{-- Cart header --}}
                    <div style="padding:20px 22px;border-bottom:1px solid rgba(255,255,255,0.07);display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <svg style="width:17px;height:17px;color:#DDF247;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span style="font-size:15px;font-weight:800;color:#fff;">Your Cart</span>
                            @if ($this->cartItemCount > 0)
                                <span style="background:#DDF247;color:#111;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:900;">{{ $this->cartItemCount }}</span>
                            @endif
                        </div>
                        @if ($this->cartItemCount > 0)
                            <button wire:click="clearCart" style="background:none;border:none;color:rgba(255,255,255,0.30);font-size:12px;font-family:'Manrope',sans-serif;font-weight:600;padding:4px 8px;border-radius:6px;">Clear all</button>
                        @endif
                    </div>

                    {{-- Cart items --}}
                    <div style="padding:16px 22px;">
                        @if ($this->cartItemCount > 0)
                            @foreach ($this->cartCategories as $categoryId => $cat)
                                @php $qty = $this->cart[$categoryId] ?? 0; @endphp
                                @if ($qty > 0)
                                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05);gap:8px;">
                                        <div style="flex:1;min-width:0;">
                                            <p style="font-size:13px;font-weight:700;color:#fff;margin:0 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $cat->name }}</p>
                                            <p style="font-size:11px;color:rgba(255,255,255,0.38);margin:0;font-family:'Azeret Mono',monospace;">× {{ $qty }}</p>
                                        </div>
                                        <span style="font-size:13px;font-weight:800;color:#fff;white-space:nowrap;">R{{ number_format($cat->price * $qty, 2) }}</span>
                                    </div>
                                @endif
                            @endforeach

                            {{-- Total --}}
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 0 4px;">
                                <span style="font-size:12px;font-weight:700;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:2px;">Total</span>
                                <span style="font-size:22px;font-weight:900;color:#DDF247;">R{{ number_format($this->cartTotal, 2) }}</span>
                            </div>

                            <button wire:click="startCheckout" class="btn-primary" style="width:100%;margin-top:14px;padding:14px;font-size:14px;justify-content:center;">
                                <svg style="width:15px;height:15px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                Proceed to Checkout
                            </button>

                            <p style="text-align:center;font-size:11px;color:rgba(255,255,255,0.22);margin-top:10px;font-family:'Azeret Mono',monospace;">
                                One secure payment of R{{ number_format($this->cartTotal, 2) }}
                            </p>
                        @else
                            <div style="padding:32px 0;text-align:center;">
                                <div style="font-size:36px;margin-bottom:12px;">🛒</div>
                                <p style="font-size:13px;color:rgba(255,255,255,0.32);font-family:'Azeret Mono',monospace;line-height:20px;">Your cart is empty.<br>Add tokens from the catalog.</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Trust badges --}}
                <div style="margin-top:14px;padding:16px 18px;background:#1a1a1a;border-radius:16px;border:1px solid rgba(255,255,255,0.07);display:flex;flex-direction:column;gap:10px;">
                    @foreach ([['🔐','Encrypted & secure checkout'],['⚡','Tokens delivered in seconds'],['📧','Email confirmation included']] as [$icon, $text])
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:15px;">{{ $icon }}</span>
                            <span style="font-size:11px;color:rgba(255,255,255,0.38);font-family:'Azeret Mono',monospace;">{{ $text }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

        </div>
    </div>

    {{-- ── CHECKOUT FLYOUT ── --}}
    <flux:modal
        wire:model="showCheckout"
        flyout
        position="right"
        :dismissible="$step === 1 || $step === 3"
        class="md:w-[28rem]"
    >
        <div class="flex h-full flex-col gap-6 p-6">

            {{-- Flyout header --}}
            <div>
                <flux:heading size="lg">
                    @if ($step === 1) Your Details
                    @elseif ($step === 2) Review & Pay
                    @else Order Complete
                    @endif
                </flux:heading>

                @if ($step === 1)
                    <flux:text class="mt-1">
                        {{ $this->cartItemCount }} {{ $this->cartItemCount === 1 ? 'token' : 'tokens' }}
                        — total <strong>R{{ number_format($this->cartTotal, 2) }}</strong>
                    </flux:text>
                    <div class="mt-4 flex items-center gap-2">
                        <div @class(['h-1.5 flex-1 rounded-full', 'bg-violet-500'])></div>
                        <div @class(['h-1.5 flex-1 rounded-full', 'bg-zinc-700'])></div>
                    </div>
                @elseif ($step === 2)
                    <flux:text class="mt-1">
                        Total: <strong>R{{ number_format($this->cartTotal, 2) }}</strong>
                    </flux:text>
                    <div class="mt-4 flex items-center gap-2">
                        <div @class(['h-1.5 flex-1 rounded-full', 'bg-violet-500'])></div>
                        <div @class(['h-1.5 flex-1 rounded-full', 'bg-violet-500'])></div>
                    </div>
                @endif
            </div>

            {{-- Step 1: Customer details --}}
            @if ($step === 1)
                <form wire:submit="goToPayment" class="flex flex-1 flex-col gap-5">
                    <flux:input
                        wire:model="customerEmail"
                        label="Email Address"
                        type="email"
                        placeholder="you@example.com"
                        description="All tokens will be sent to this address."
                        icon="envelope"
                        required
                        autofocus
                    />

                    <flux:input
                        wire:model="customerPhone"
                        label="Phone Number"
                        type="tel"
                        placeholder="072 000 0000"
                        icon="phone"
                        required
                    />

                    {{-- Order summary --}}
                    <div style="background:rgba(255,255,255,0.03);border-radius:13px;border:1px solid rgba(255,255,255,0.08);overflow:hidden;">
                        @foreach ($this->cartCategories as $categoryId => $cat)
                            @php $qty = $this->cart[$categoryId] ?? 0; @endphp
                            @if ($qty > 0)
                                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;">
                                    <span style="color:rgba(255,255,255,0.65);font-weight:600;">{{ $cat->name }} <span style="color:rgba(255,255,255,0.32);font-weight:400;">×{{ $qty }}</span></span>
                                    <span style="color:#fff;font-weight:700;">R{{ number_format($cat->price * $qty, 2) }}</span>
                                </div>
                            @endif
                        @endforeach
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:13px 16px;">
                            <span style="font-size:14px;font-weight:800;color:#fff;">Total</span>
                            <span style="font-size:18px;font-weight:900;color:#DDF247;">R{{ number_format($this->cartTotal, 2) }}</span>
                        </div>
                    </div>

                    <div class="mt-auto">
                        <flux:button type="submit" variant="primary" class="w-full bg-violet-600 hover:bg-violet-500">
                            <span wire:loading.remove wire:target="goToPayment" class="flex items-center gap-2">
                                Continue to Payment
                                <flux:icon.arrow-right class="size-4" />
                            </span>
                            <span wire:loading wire:target="goToPayment" class="flex items-center gap-2">
                                <flux:icon.loading class="size-4 animate-spin" />
                                Preparing payment…
                            </span>
                        </flux:button>
                    </div>
                </form>
            @endif

            {{-- Step 2: Pay --}}
            @if ($step === 2)
                <div class="flex flex-1 flex-col gap-4">

                    {{-- Order summary --}}
                    <div style="background:rgba(255,255,255,0.03);border-radius:13px;border:1px solid rgba(255,255,255,0.08);overflow:hidden;">
                        @foreach ($this->cartCategories as $categoryId => $cat)
                            @php $qty = $this->cart[$categoryId] ?? 0; @endphp
                            @if ($qty > 0)
                                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;">
                                    <span style="color:rgba(255,255,255,0.65);font-weight:600;">{{ $cat->name }} <span style="color:rgba(255,255,255,0.32);font-weight:400;">×{{ $qty }}</span></span>
                                    <span style="color:#fff;font-weight:700;">R{{ number_format($cat->price * $qty, 2) }}</span>
                                </div>
                            @endif
                        @endforeach
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:13px 16px;">
                            <span style="font-size:14px;font-weight:800;color:#fff;">Total</span>
                            <span style="font-size:18px;font-weight:900;color:#DDF247;">R{{ number_format($this->cartTotal, 2) }}</span>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:8px;background:rgba(34,197,94,0.05);border:1px solid rgba(34,197,94,0.18);border-radius:10px;padding:10px 13px;font-size:12px;color:#4ade80;">
                        <svg style="width:13px;height:13px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Payment is processed securely.
                    </div>

                    {{-- Waiting for IPN (after PayFast popup or during QR scan) --}}
                    @if ($pollingForItn && $checkoutType !== 'qr' && $checkoutType !== 'popup')
                        <div class="mt-auto flex flex-col items-center gap-3 py-6" wire:poll.2500ms="pollPaymentStatus">
                            <div class="flex size-14 items-center justify-center rounded-full bg-violet-500/10">
                                <flux:icon.loading class="size-7 animate-spin text-violet-400" />
                            </div>
                            <flux:text class="text-center text-zinc-400">Confirming your payment…</flux:text>
                        </div>
                    @endif

                    {{-- PayFast onsite --}}
                    @if ($checkoutType === 'onsite' && ! $pollingForItn)
                        <div
                            x-data="{ processing: false, pfUuid: '' }"
                            x-init="pfUuid = $wire.paymentUuid || ''"
                            x-on:payfast-uuid-ready.window="pfUuid = $event.detail.uuid; processing = false;"
                            class="mt-auto space-y-3"
                        >
                            <flux:button
                                x-bind:disabled="processing || !pfUuid"
                                x-on:click="processing = true; window.payfast_do_onsite_payment({ uuid: pfUuid }, (result) => { result === true ? $wire.finalizeOrder() : ($wire.paymentFailed(), processing = false); });"
                                variant="primary"
                                class="w-full bg-violet-600 hover:bg-violet-500"
                            >
                                <span x-show="!processing" class="flex items-center justify-center gap-2">
                                    <flux:icon.lock-closed class="size-4" />
                                    Pay R{{ number_format($this->cartTotal, 2) }}
                                </span>
                                <span x-show="processing" class="flex items-center justify-center gap-2">
                                    <flux:icon.loading class="size-4 animate-spin" />
                                    Processing…
                                </span>
                            </flux:button>
                        </div>
                    @endif

                    {{-- SnapScan QR --}}
                    @if ($checkoutType === 'qr')
                        <div class="mt-auto space-y-3" wire:poll.2500ms="pollPaymentStatus">
                            <div style="display:flex;flex-direction:column;align-items:center;gap:12px;background:rgba(255,255,255,0.03);border-radius:13px;border:1px solid rgba(255,255,255,0.08);padding:20px;">
                                <p style="font-size:12px;color:rgba(255,255,255,0.45);font-family:'Azeret Mono',monospace;">Scan with your banking app</p>
                                <img src="{{ $qrUrl }}" alt="QR code" style="width:170px;height:170px;border-radius:10px;" />
                                <p style="font-size:11px;color:rgba(255,255,255,0.28);font-family:'Azeret Mono',monospace;">Page updates automatically on payment</p>
                            </div>
                        </div>
                    @endif

                    {{-- Flutterwave card form --}}
                    @if ($checkoutType === 'inline')
                        <form wire:submit="submitCardPayment" class="mt-2 flex flex-col gap-3">

                            @if ($cardAuthMode === 'send_pin')
                                <div style="background:rgba(234,179,8,0.08);border:1px solid rgba(234,179,8,0.25);border-radius:10px;padding:12px 14px;font-size:13px;color:#fbbf24;">
                                    Please enter your card PIN to authorize this payment.
                                </div>
                                <flux:input
                                    wire:model="cardPin"
                                    label="Card PIN"
                                    type="password"
                                    placeholder="••••"
                                    inputmode="numeric"
                                    maxlength="6"
                                />
                                <flux:button type="submit" variant="primary" class="mt-1 w-full bg-violet-600 hover:bg-violet-500">
                                    <span wire:loading.remove wire:target="submitCardPayment" class="flex items-center justify-center gap-2">
                                        <flux:icon.lock-closed class="size-4" />
                                        Submit PIN
                                    </span>
                                    <span wire:loading wire:target="submitCardPayment" class="flex items-center justify-center gap-2">
                                        <flux:icon.loading class="size-4 animate-spin" />
                                        Verifying…
                                    </span>
                                </flux:button>

                            @elseif ($cardAuthMode === 'send_otp')
                                <div style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.25);border-radius:10px;padding:12px 14px;font-size:13px;color:#93c5fd;">
                                    {{ $cardAuthMessage ?: 'Please enter the OTP sent to your registered phone or email.' }}
                                </div>
                                <flux:input
                                    wire:model="cardOtp"
                                    label="One-Time PIN (OTP)"
                                    placeholder="Enter OTP"
                                    inputmode="numeric"
                                    maxlength="8"
                                />
                                <flux:button type="submit" variant="primary" class="mt-1 w-full bg-violet-600 hover:bg-violet-500">
                                    <span wire:loading.remove wire:target="submitCardPayment" class="flex items-center justify-center gap-2">
                                        <flux:icon.shield-check class="size-4" />
                                        Verify OTP
                                    </span>
                                    <span wire:loading wire:target="submitCardPayment" class="flex items-center justify-center gap-2">
                                        <flux:icon.loading class="size-4 animate-spin" />
                                        Verifying…
                                    </span>
                                </flux:button>

                            @else
                                <flux:input
                                    wire:model="cardNumber"
                                    label="Card Number"
                                    placeholder="1234 5678 9012 3456"
                                    inputmode="numeric"
                                    autocomplete="cc-number"
                                    maxlength="19"
                                />
                                <flux:input
                                    wire:model="cardName"
                                    label="Name on Card"
                                    placeholder="John Doe"
                                    autocomplete="cc-name"
                                />
                                <div class="grid grid-cols-2 gap-3">
                                    <flux:input
                                        wire:model="cardExpiry"
                                        label="Expiry (MM/YY)"
                                        placeholder="MM/YY"
                                        inputmode="numeric"
                                        autocomplete="cc-exp"
                                        maxlength="5"
                                    />
                                    <flux:input
                                        wire:model="cardCvv"
                                        label="CVV"
                                        type="password"
                                        placeholder="•••"
                                        inputmode="numeric"
                                        autocomplete="cc-csc"
                                        maxlength="4"
                                    />
                                </div>
                                <flux:button type="submit" variant="primary" class="mt-1 w-full bg-violet-600 hover:bg-violet-500">
                                    <span wire:loading.remove wire:target="submitCardPayment" class="flex items-center justify-center gap-2">
                                        <flux:icon.lock-closed class="size-4" />
                                        Pay R{{ number_format($this->cartTotal, 2) }}
                                    </span>
                                    <span wire:loading wire:target="submitCardPayment" class="flex items-center justify-center gap-2">
                                        <flux:icon.loading class="size-4 animate-spin" />
                                        Processing…
                                    </span>
                                </flux:button>
                            @endif

                        </form>
                    @endif

                    {{-- Whop embedded checkout --}}
                    @if ($checkoutType === 'whop')
                        <div
                            class="flex flex-1 flex-col gap-3"
                            wire:poll.2500ms="pollPaymentStatus"
                            x-data
                            x-init="
                                $nextTick(function () {
                                    if (!document.querySelector('script[src*=\'js.whop.com\']')) {
                                        const s = document.createElement('script');
                                        s.src = 'https://js.whop.com/static/checkout/loader.js';
                                        s.async = true;
                                        document.head.appendChild(s);
                                    }
                                    setTimeout(function () {
                                        document.querySelector('a[href=&quot;https://whop.com&quot;]')
                                            ?.parentElement?.remove();
                                    }, 3000);
                                })
                            "
                        >
                            <div
                                data-whop-checkout-plan-id="{{ $whopPlanId }}"
                                data-whop-checkout-session="{{ $whopCheckoutId }}"
                                class="min-h-[380px] w-full overflow-hidden rounded-xl border border-zinc-700 bg-zinc-900"
                            ></div>

                            <p style="text-align:center;font-size:11px;color:rgba(255,255,255,0.28);font-family:'Azeret Mono',monospace;">
                                Confirming payment automatically…
                            </p>
                        </div>
                    @endif

                    {{-- DPO redirect (fallback for non-popup) --}}
                    @if ($checkoutType === 'redirect')
                        <div class="mt-auto">
                            <a href="{{ $redirectUrl }}" class="block">
                                <flux:button variant="primary" class="w-full bg-violet-600 hover:bg-violet-500">
                                    <flux:icon.arrow-top-right-on-square class="size-4" />
                                    Proceed to Secure Checkout
                                </flux:button>
                            </a>
                        </div>
                    @endif

                    {{-- DPO popup --}}
                    @if ($checkoutType === 'popup')
                        <div
                            class="mt-auto flex flex-col items-center gap-4 py-6"
                            wire:poll.2500ms="pollPaymentStatus"
                            x-data="{ popup: null }"
                            x-init="
                                $nextTick(function () {
                                    popup = window.open(
                                        '{{ addslashes($redirectUrl) }}',
                                        'dpo_payment',
                                        'width=720,height=680,scrollbars=yes,resizable=yes'
                                    );
                                });
                                window.addEventListener('message', function (e) {
                                    if (e.data && e.data.dpoDone) {
                                        if (popup && !popup.closed) popup.close();
                                    }
                                    if (e.data && e.data.dpoCancelled) {
                                        if (popup && !popup.closed) popup.close();
                                        $wire.paymentFailed();
                                    }
                                });
                            "
                        >
                            <div class="flex size-14 items-center justify-center rounded-full bg-violet-500/10">
                                <flux:icon.loading class="size-7 animate-spin text-violet-400" />
                            </div>
                            <flux:text class="text-center text-sm text-zinc-400">
                                Complete your payment in the secure window that just opened.
                            </flux:text>
                            <flux:button
                                x-on:click="popup = window.open('{{ addslashes($redirectUrl) }}', 'dpo_payment', 'width=720,height=680,scrollbars=yes,resizable=yes')"
                                variant="ghost"
                                size="sm"
                                icon="arrow-top-right-on-square"
                            >
                                Reopen payment window
                            </flux:button>
                        </div>
                    @endif

                </div>
            @endif

            {{-- Step 3: Error --}}
            @if ($step === 3)
                <div class="flex flex-1 flex-col gap-5">

                    <div class="flex size-16 items-center justify-center rounded-full bg-red-500/10 mx-auto">
                        <flux:icon.x-circle class="size-9 text-red-500" />
                    </div>

                    <div class="text-center">
                        <flux:heading size="lg">Payment Failed</flux:heading>
                        <flux:text class="mt-1">{{ $paymentError }}</flux:text>
                    </div>

                    <div class="mt-auto space-y-2">
                        <flux:button wire:click="$set('step', 1)" variant="primary" class="w-full bg-violet-600 hover:bg-violet-500">Try Again</flux:button>
                        <flux:modal.close>
                            <flux:button variant="ghost" class="w-full">Close</flux:button>
                        </flux:modal.close>
                    </div>

                </div>
            @endif

        </div>
    </flux:modal>

</div>
