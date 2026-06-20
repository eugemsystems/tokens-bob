<?php

use App\Actions\InitiateCheckout;
use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Token Store')] #[Layout('layouts.public')] class extends Component
{
    public bool $showCheckout = false;
    public ?int $selectedCategoryId = null;
    public int $step = 1;

    #[Validate('required|email|max:254')]
    public string $customerEmail = '';

    #[Validate('required|string|max:20')]
    public string $customerPhone = '';

    public string $paymentUuid = '';
    public string $checkoutType = '';
    public string $qrUrl = '';
    public string $redirectUrl = '';
    public ?int $pendingTransactionId = null;
    public ?int $pendingTokenId = null;

    public bool $paymentSucceeded = false;
    public ?string $purchasedTokenCode = null;
    public ?int $purchasedTransactionId = null;
    public string $paymentError = '';
    public bool $pollingForItn = false;
    public int $pollAttempts = 0;
    private const MAX_POLL_ATTEMPTS = 20;

    #[Computed]
    public function categories(): Collection
    {
        return Category::withCount([
            'tokens as available_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Available),
        ])->orderBy('price')->get();
    }

    #[Computed]
    public function selectedCategory(): ?Category
    {
        return $this->selectedCategoryId
            ? $this->categories->firstWhere('id', $this->selectedCategoryId)
            : null;
    }

    public function selectCategory(int $id): void
    {
        $this->selectedCategoryId = $id;
        $this->resetCheckout();
        $this->showCheckout = true;
    }

    public function goToPayment(): void
    {
        $this->validateOnly('customerEmail');
        $this->validateOnly('customerPhone');

        $category = $this->selectedCategory;

        if (! $category) {
            return;
        }

        $result = app(InitiateCheckout::class)->execute(
            category: $category,
            customerData: [
                'email' => $this->customerEmail,
                'phone' => $this->customerPhone,
            ],
        );

        if (! $result['success']) {
            $this->paymentError = $result['message'];
            $this->paymentSucceeded = false;
            $this->step = 3;

            return;
        }

        $this->checkoutType         = $result['checkout_type'];
        $this->pendingTransactionId = $result['transaction_id'];
        $this->pendingTokenId       = $result['token_id'];

        if ($result['checkout_type'] === 'onsite') {
            $this->paymentUuid = $result['data']['uuid'];
            $this->dispatch('payfast-uuid-ready', uuid: $result['data']['uuid']);
        } elseif ($result['checkout_type'] === 'qr') {
            $this->qrUrl = $result['data']['qr_url'];
            $this->pollingForItn = true;
            $this->pollAttempts  = 0;
        } elseif ($result['checkout_type'] === 'redirect') {
            $this->redirectUrl = $result['data']['redirect_url'];
        }

        $this->step = 2;
    }

    /** Called by Alpine when the PayFast overlay reports a successful payment. */
    public function finalizeOrder(): void
    {
        if (! $this->pendingTransactionId || ! $this->pendingTokenId) {
            return;
        }

        $transaction = Transaction::find($this->pendingTransactionId);
        $token       = Token::find($this->pendingTokenId);

        if (! $transaction || ! $token) {
            $this->paymentError     = 'Order not found. Please contact support.';
            $this->paymentSucceeded = false;
            $this->step             = 3;

            return;
        }

        if ($transaction->status === TransactionStatus::Completed) {
            $this->paymentSucceeded       = true;
            $this->purchasedTokenCode     = $token->token_code;
            $this->purchasedTransactionId = $transaction->id;
            $this->paymentError           = '';
            $this->step                   = 3;

            return;
        }

        // ITN not yet received — poll until gateway confirms via webhook.
        $this->pollingForItn = true;
        $this->pollAttempts  = 0;
        $this->step          = 3;
    }

    /** Polled every 2.5 s while waiting for a gateway webhook to confirm the transaction. */
    public function pollPaymentStatus(): void
    {
        if (! $this->pollingForItn) {
            return;
        }

        $this->pollAttempts++;

        $transaction = Transaction::find($this->pendingTransactionId);
        $token       = Token::find($this->pendingTokenId);

        if ($transaction && $transaction->status === TransactionStatus::Completed) {
            $this->paymentSucceeded       = true;
            $this->purchasedTokenCode     = $token?->token_code;
            $this->purchasedTransactionId = $transaction->id;
            $this->paymentError           = '';
            $this->pollingForItn          = false;

            return;
        }

        if ($transaction && $transaction->status === TransactionStatus::Failed) {
            $this->paymentError     = 'Your payment was not completed. Please try again.';
            $this->paymentSucceeded = false;
            $this->pollingForItn    = false;

            return;
        }

        if ($this->pollAttempts >= self::MAX_POLL_ATTEMPTS) {
            $this->paymentError     = 'Payment confirmation is taking longer than expected. Check your email or contact support with reference #'.$this->pendingTransactionId.'.';
            $this->paymentSucceeded = false;
            $this->pollingForItn    = false;
        }
    }

    /** Called by Alpine when the PayFast overlay is closed without payment. */
    public function paymentFailed(): void
    {
        DB::transaction(function (): void {
            if ($this->pendingTokenId) {
                Token::where('id', $this->pendingTokenId)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
            }

            if ($this->pendingTransactionId) {
                Transaction::where('id', $this->pendingTransactionId)
                    ->where('status', TransactionStatus::Pending)
                    ->update(['status' => TransactionStatus::Failed]);
            }
        });

        $this->paymentError     = 'Payment was not completed. Please try again.';
        $this->paymentSucceeded = false;
        $this->reset(['paymentUuid', 'pendingTransactionId', 'pendingTokenId', 'qrUrl', 'redirectUrl', 'checkoutType']);
        $this->step = 3;
    }

    /** Back button in step 2 — releases the reservation and returns to step 1. */
    public function cancelPayment(): void
    {
        DB::transaction(function (): void {
            if ($this->pendingTokenId) {
                Token::where('id', $this->pendingTokenId)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
            }

            if ($this->pendingTransactionId) {
                Transaction::where('id', $this->pendingTransactionId)
                    ->where('status', TransactionStatus::Pending)
                    ->update(['status' => TransactionStatus::Failed]);
            }
        });

        $this->reset(['paymentUuid', 'pendingTransactionId', 'pendingTokenId', 'qrUrl', 'redirectUrl', 'checkoutType', 'pollingForItn', 'pollAttempts']);
        $this->step = 1;
    }

    private function resetCheckout(): void
    {
        $this->reset([
            'step',
            'customerEmail',
            'customerPhone',
            'paymentSucceeded',
            'purchasedTokenCode',
            'purchasedTransactionId',
            'paymentError',
            'paymentUuid',
            'checkoutType',
            'qrUrl',
            'redirectUrl',
            'pendingTransactionId',
            'pendingTokenId',
            'pollingForItn',
            'pollAttempts',
        ]);

        $this->step = 1;
    }
}; ?>

<div style="font-family:'Manrope',sans-serif;">

    {{-- ── HERO ── --}}
    <section id="hero-section" style="position:relative;overflow:hidden;background:linear-gradient(180deg,#1a1a1a 0%,#111111 100%);padding:144px 0 108px;">
        {{-- Spider web canvas --}}
        <canvas id="hero-canvas" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;"></canvas>
        {{-- Parallax depth layers --}}
        <div style="pointer-events:none;position:absolute;inset:0;overflow:hidden;">
            <div data-depth="0.04" style="position:absolute;top:-200px;left:50%;transform:translateX(-50%);width:900px;height:700px;background:radial-gradient(ellipse,rgba(221,242,71,0.07) 0%,transparent 70%);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="0.08" style="position:absolute;top:60px;right:-80px;width:500px;height:500px;background:radial-gradient(ellipse,rgba(221,242,71,0.06) 0%,transparent 70%);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="-0.06" style="position:absolute;bottom:40px;left:-80px;width:380px;height:380px;background:radial-gradient(ellipse,rgba(130,100,255,0.05) 0%,transparent 70%);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="0.14" style="position:absolute;top:22%;left:7%;width:10px;height:10px;border-radius:50%;background:rgba(221,242,71,0.45);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="-0.11" style="position:absolute;top:38%;right:10%;width:7px;height:7px;border-radius:50%;background:rgba(221,242,71,0.30);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="0.18" style="position:absolute;top:58%;left:5%;width:5px;height:5px;border-radius:50%;background:rgba(221,242,71,0.35);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="-0.09" style="position:absolute;top:18%;right:18%;width:90px;height:90px;border-radius:50%;border:1px solid rgba(221,242,71,0.10);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="0.07" style="position:absolute;top:65%;right:7%;width:56px;height:56px;border-radius:14px;border:1px solid rgba(221,242,71,0.08);transform:rotate(28deg);transition:transform 0.15s ease-out;will-change:transform;"></div>
            <div data-depth="-0.13" style="position:absolute;top:42%;left:14%;width:40px;height:40px;border-radius:10px;border:1px solid rgba(221,242,71,0.07);transform:rotate(-15deg);transition:transform 0.15s ease-out;will-change:transform;"></div>
        </div>

        <div style="position:relative;max-width:72rem;margin:0 auto;padding:0 24px;text-align:center;">
            <div class="mb-8 inline-flex items-center gap-2 rounded-full border px-5 py-2.5 text-sm font-semibold" style="border-color:rgba(221,242,71,0.25);background:rgba(221,242,71,0.07);color:#DDF247;">
                <span class="size-2 animate-pulse rounded-full" style="background:#DDF247;"></span>
                Instant Digital Delivery · South Africa
            </div>

            <h1 class="mb-7 text-5xl font-extrabold leading-tight tracking-tight text-white sm:text-6xl lg:text-7xl" style="font-family:'Manrope',sans-serif;">
                All Your Digital Tokens,
                <span class="gradient-text block">One Trusted Store</span>
            </h1>

            <p class="mx-auto mb-12 max-w-2xl leading-relaxed" style="color:rgba(255,255,255,0.55);font-family:'Azeret Mono',monospace;font-size:15px;line-height:27px;">
                Gaming credits · Streaming subscriptions · Shopping vouchers · Prepaid data<br>
                Delivered to your inbox in seconds. No account needed.
            </p>

            <div style="padding-top: 40px;padding-bottom: 35px;" class="flex flex-wrap items-center justify-center gap-4">
                <a href="{{ route('shop') }}" wire:navigate class="btn-primary text-base">
                    <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M13 3L4 14h7l-2 7 9-11h-7l2-7z"/></svg>
                    Shop All Tokens
                </a>
                <a href="#about" class="btn-ghost text-base">About Us</a>
            </div>

            <div class="mt-16 flex flex-wrap items-center justify-center gap-8" style="color:rgba(255,255,255,0.38);font-size:13px;font-family:'Azeret Mono',monospace;">
                <span class="flex items-center gap-2"><svg class="size-4" style="color:#4ade80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>Secure payment</span>
                <span class="flex items-center gap-2"><svg class="size-4" style="color:#DDF247" fill="currentColor" viewBox="0 0 24 24"><path d="M13 3L4 14h7l-2 7 9-11h-7l2-7z"/></svg>Delivered in seconds</span>
                <span class="flex items-center gap-2"><svg class="size-4" style="color:#60a5fa" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>Email confirmation</span>
                <span class="flex items-center gap-2"><svg class="size-4" style="color:#a78bfa" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>Works on any device</span>
            </div>
        </div>
    </section>

    {{-- ── BRANDS CAROUSEL ── --}}
    <section class="sec" style="background:#161616;">
        <div class="sec-inner">
            <div class="sec-head">
                <div class="sec-badge" style="border-color:rgba(221,242,71,0.2);color:#DDF247;">Supported Platforms</div>
                <h2 class="sec-h2">Tokens for Every Platform</h2>
                <p class="sec-sub">Gaming · Streaming · Shopping · Connectivity — all in one place</p>
            </div>

            @php
            $brands = [
            ['logo' => 'https://cdn.simpleicons.org/playstation/ffffff', 'label' => 'PlayStation',  'color' => '#003087'],
            ['logo' => 'https://cdn.simpleicons.org/xbox/ffffff',         'label' => 'Xbox',          'color' => '#107C10'],
            ['logo' => 'https://cdn.simpleicons.org/steam/ffffff',        'label' => 'Steam',         'color' => '#4a90d9'],
            ['logo' => 'https://cdn.simpleicons.org/nintendo/ffffff',     'label' => 'Nintendo',      'color' => '#E60012'],
            ['logo' => 'https://cdn.simpleicons.org/epicgames/ffffff',    'label' => 'Epic Games',    'color' => '#a0a0a0'],
            ['logo' => 'https://cdn.simpleicons.org/ea/ffffff',           'label' => 'EA Games',      'color' => '#FF4747'],
            ['logo' => 'https://cdn.simpleicons.org/roblox/ffffff',       'label' => 'Roblox',        'color' => '#E11D2A'],
            ['logo' => 'https://cdn.simpleicons.org/rockstargames/ffffff','label' => 'Rockstar',      'color' => '#FCBA03'],
            ['logo' => 'https://cdn.simpleicons.org/netflix/ffffff',      'label' => 'Netflix',       'color' => '#E50914'],
            ['logo' => 'https://cdn.simpleicons.org/spotify/ffffff',      'label' => 'Spotify',       'color' => '#1DB954'],
            ['logo' => 'https://cdn.simpleicons.org/apple/ffffff',        'label' => 'Apple',         'color' => '#aaaaaa'],
            ['logo' => 'https://cdn.simpleicons.org/primevideo/ffffff',   'label' => 'Prime Video',   'color' => '#00A8E0'],
            ['logo' => 'https://cdn.simpleicons.org/googleplay/ffffff',   'label' => 'Google Play',   'color' => '#01875F'],
            ['logo' => 'https://cdn.simpleicons.org/youtube/ffffff',      'label' => 'YouTube',       'color' => '#FF0000'],
            ['logo' => null, 'label' => 'Showmax',    'color' => '#E6000A', 'initials' => 'SM'],
            ['logo' => null, 'label' => 'DStv',       'color' => '#00AEEF', 'initials' => 'DS'],
            ['logo' => null, 'label' => 'Takealot',   'color' => '#FF6900', 'initials' => 'TL'],
            ['logo' => null, 'label' => 'Woolworths', 'color' => '#aaaaaa', 'initials' => 'WW'],
            ];
            @endphp

            {{-- Boxed carousel --}}
            <div style="position:relative;overflow:hidden;padding:8px 0;border-radius:20px;">
                <div style="position:absolute;left:0;top:0;bottom:0;width:80px;background:linear-gradient(90deg,#161616,transparent);z-index:2;pointer-events:none;"></div>
                <div style="position:absolute;right:0;top:0;bottom:0;width:80px;background:linear-gradient(270deg,#161616,transparent);z-index:2;pointer-events:none;"></div>

                <div class="brands-track" style="display:flex;gap:18px;width:max-content;">
                @foreach (array_merge($brands, $brands) as $brand)
                    <div style="
                        width:168px;
                        flex-shrink:0;
                        border-radius:20px;
                        border:1px solid {{ $brand['color'] }}44;
                        overflow:hidden;
                        background:#1a1a1a;
                        display:flex;
                        flex-direction:column;
                        transition:all 0.3s ease;
                    "
                    onmouseenter="this.style.transform='translateY(-7px)';this.style.borderColor='{{ $brand['color'] }}99';this.style.boxShadow='0 18px 44px rgba(0,0,0,0.55)';"
                    onmouseleave="this.style.transform='';this.style.borderColor='{{ $brand['color'] }}44';this.style.boxShadow='';"
                    >
                        {{-- Logo fills the card --}}
                        <div style="background:{{ $brand['color'] }}1a;display:flex;align-items:center;justify-content:center;padding:28px 20px;min-height:150px;">
                            @if ($brand['logo'])
                                <img src="{{ $brand['logo'] }}" alt="{{ $brand['label'] }}" style="width:100px;height:100px;object-fit:contain;" loading="lazy" />
                            @else
                                <span style="font-size:44px;font-weight:900;color:{{ $brand['color'] }};font-family:'Manrope',sans-serif;letter-spacing:-2px;">{{ $brand['initials'] }}</span>
                            @endif
                        </div>
                        <div style="background:#111111;padding:13px 16px;text-align:center;border-top:1px solid rgba(255,255,255,0.06);">
                            <span style="font-size:12px;font-weight:700;color:rgba(255,255,255,0.60);font-family:'Manrope',sans-serif;letter-spacing:0.3px;">{{ $brand['label'] }}</span>
                        </div>
                    </div>
                @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ── TOKENS FOR EVERY NEED ── --}}
    <section class="sec" style="background:#111111;">
        <div class="sec-inner">
            <div class="sec-head">
                <div class="sec-badge" style="border-color:rgba(221,242,71,0.2);color:#DDF247;">Everything Digital</div>
                <h2 class="sec-h2">Tokens for Every Need</h2>
                <p class="sec-sub">From gaming credits to grocery vouchers — we cover every category</p>
            </div>

            <div id="need-grid">
                @foreach ([
                    ['icon' => 'https://cdn.simpleicons.org/playstation/DDF247', 'title' => 'Gaming',    'desc' => 'PlayStation, Xbox, Steam, Nintendo, EA, Epic Games, Roblox & more.', 'bar' => 'step-bar-1', 'num' => '01'],
                    ['icon' => 'https://cdn.simpleicons.org/netflix/DDF247',     'title' => 'Streaming', 'desc' => 'Netflix, Showmax, DStv, Prime Video, Spotify, Apple TV & more.',    'bar' => 'step-bar-2', 'num' => '02'],
                    ['icon' => 'https://cdn.simpleicons.org/googleplay/DDF247',  'title' => 'Shopping',  'desc' => 'Takealot, Woolworths, Pick n Pay, Google Play gift cards.',          'bar' => 'step-bar-3', 'num' => '03'],
                    ['icon' => 'https://cdn.simpleicons.org/youtube/DDF247',     'title' => 'More',      'desc' => 'Prepaid data, airtime, YouTube Premium, iCloud storage & more.',    'bar' => 'step-bar-4', 'num' => '04'],
                ] as $cat)
                    <div
                        class="relative overflow-hidden rounded-3xl"
                        style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.07);transition:all 0.3s ease;display:flex;flex-direction:column;"
                        onmouseenter="this.style.borderColor='rgba(221,242,71,0.28)';this.style.transform='translateY(-7px)';this.style.boxShadow='0 24px 60px rgba(0,0,0,0.55)';"
                        onmouseleave="this.style.borderColor='rgba(255,255,255,0.07)';this.style.transform='';this.style.boxShadow='';"
                    >
                        <div style="padding:32px 28px 28px;flex:1;display:flex;flex-direction:column;gap:18px;">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;">
                                <div style="width:60px;height:60px;border-radius:16px;background:#232323;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,0.08);">
                                    <img src="{{ $cat['icon'] }}" alt="{{ $cat['title'] }}" style="width:30px;height:30px;object-fit:contain;" loading="lazy" />
                                </div>
                                <span style="font-size:11px;font-weight:900;letter-spacing:4px;color:rgba(255,255,255,0.12);font-family:'Azeret Mono',monospace;padding-top:4px;">{{ $cat['num'] }}</span>
                            </div>
                            <div>
                                <h4 style="font-size:21px;font-weight:800;color:#fff;margin-bottom:10px;font-family:'Manrope',sans-serif;">{{ $cat['title'] }}</h4>
                                <p style="font-size:13px;line-height:23px;color:rgba(255,255,255,0.50);font-family:'Azeret Mono',monospace;">{{ $cat['desc'] }}</p>
                            </div>
                        </div>
                        <div class="{{ $cat['bar'] }}" style="height:5px;width:100%;"></div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── LIVE STORE ── --}}
    <section id="store" class="sec" style="background:#161616;">
        <div class="sec-inner">
            <div class="sec-head">
                <div class="sec-badge" style="border-color:rgba(34,197,94,0.3);color:#4ade80;">
                    <span style="width:8px;height:8px;border-radius:50%;background:#4ade80;display:inline-block;animation:pulse 2s infinite;"></span>
                    Live Stock
                </div>
                <h2 class="sec-h2">Available Tokens Right Now</h2>
                <p class="sec-sub">In-stock and ready to deliver — buy in seconds, receive instantly</p>
            </div>

            @if ($this->categories->isEmpty())
                <div style="border-radius:24px;border:1px solid rgba(255,255,255,0.07);background:#1e1e1e;padding:80px 24px;text-align:center;">
                    <p style="color:rgba(255,255,255,0.30);font-size:14px;font-family:'Azeret Mono',monospace;">More tokens coming soon — check back shortly.</p>
                </div>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->categories as $category)
                        @php $isAvailable = $category->available_tokens_count > 0; @endphp
                        <div
                            class="token-card"
                            style="background:#1e1e1e;border-radius:24px;border:1px solid rgba(255,255,255,0.07);padding:32px;display:flex;flex-direction:column;gap:22px;"
                        >
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                                <div>
                                    <h3 style="font-size:21px;font-weight:800;color:#fff;margin-bottom:6px;font-family:'Manrope',sans-serif;">{{ $category->name }}</h3>
                                    @if ($category->description)
                                        <p style="font-size:13px;color:rgba(255,255,255,0.45);font-family:'Azeret Mono',monospace;line-height:20px;">{{ $category->description }}</p>
                                    @endif
                                </div>
                                <span style="flex-shrink:0;font-size:11px;font-weight:700;padding:5px 11px;border-radius:999px;font-family:'Manrope',sans-serif;{{ $isAvailable ? 'background:rgba(34,197,94,0.12);color:#4ade80;border:1px solid rgba(34,197,94,0.25);' : 'background:rgba(239,68,68,0.12);color:#f87171;border:1px solid rgba(239,68,68,0.25);' }}">
                                    {{ $isAvailable ? $category->available_tokens_count.' in stock' : 'Sold out' }}
                                </span>
                            </div>

                            <div style="border-top:1px solid rgba(255,255,255,0.07);padding-top:22px;display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-size:30px;font-weight:800;color:#fff;font-family:'Manrope',sans-serif;">R{{ number_format($category->price, 2) }}</span>
                                @if ($isAvailable)
                                    <a href="{{ route('shop', ['add' => $category->id]) }}" wire:navigate class="btn-primary" style="padding:12px 24px;font-size:14px;">Buy Now</a>
                                @else
                                    <button disabled style="display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.3);border-radius:12px;padding:12px 24px;font-size:14px;font-weight:700;font-family:'Manrope',sans-serif;border:none;">Sold Out</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- ── HOW IT WORKS ── --}}
    <section id="how-it-works" class="sec" style="background:#111111;">
        <div class="sec-inner">
            <div class="sec-head">
                <div class="sec-badge" style="border-color:rgba(221,242,71,0.2);color:#DDF247;">Simple Process</div>
                <h2 class="sec-h2">How It Works</h2>
                <p class="sec-sub">Three steps · No account · No waiting</p>
            </div>

            <div id="hiw-grid">
                @foreach ([
                    ['num' => '01', 'emoji' => '🛍️', 'title' => 'Pick Your Token', 'desc' => 'Browse our live catalog and choose the gaming, streaming, or shopping token you need.', 'bar' => 'step-bar-1'],
                    ['num' => '02', 'emoji' => '💳', 'title' => 'Pay Securely',     'desc' => 'Enter your email and complete payment via our encrypted, PCI-DSS compliant checkout.', 'bar' => 'step-bar-3'],
                    ['num' => '03', 'emoji' => '⚡', 'title' => 'Receive Instantly','desc' => 'Your unique token code appears on screen and lands in your inbox within seconds.',     'bar' => 'step-bar-2'],
                ] as $i => $step)
                    @if ($i > 0)
                        <div class="hiw-arrow">
                            <div style="display:flex;align-items:center;">
                                <div style="width:32px;height:1px;background:linear-gradient(90deg,rgba(221,242,71,0.15),rgba(221,242,71,0.60));"></div>
                                <div style="width:0;height:0;border-top:5px solid transparent;border-bottom:5px solid transparent;border-left:8px solid rgba(221,242,71,0.60);"></div>
                            </div>
                        </div>
                    @endif
                    <div
                        class="relative overflow-hidden rounded-3xl text-center"
                        style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.07);transition:all 0.3s ease;display:flex;flex-direction:column;"
                        onmouseenter="this.querySelector('.step-ico').style.transform='scale(1.15) rotate(-5deg)';this.style.borderColor='rgba(221,242,71,0.25)';this.style.transform='translateY(-7px)';this.style.boxShadow='0 24px 60px rgba(0,0,0,0.5)';"
                        onmouseleave="this.querySelector('.step-ico').style.transform='';this.style.borderColor='rgba(255,255,255,0.07)';this.style.transform='';this.style.boxShadow='';"
                    >
                        <div style="padding:36px 28px 28px;flex:1;">
                            <div style="font-size:11px;font-weight:900;letter-spacing:4px;text-transform:uppercase;color:rgba(221,242,71,0.45);margin-bottom:18px;font-family:'Azeret Mono',monospace;">Step {{ $step['num'] }}</div>
                            <div class="step-ico" style="width:80px;height:80px;border-radius:22px;background:#232323;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;border:1px solid rgba(255,255,255,0.08);font-size:36px;transition:transform 0.4s ease;">{{ $step['emoji'] }}</div>
                            <h4 style="font-size:20px;font-weight:800;color:#fff;margin-bottom:12px;font-family:'Manrope',sans-serif;">{{ $step['title'] }}</h4>
                            <p style="font-size:13px;line-height:23px;color:rgba(255,255,255,0.50);font-family:'Azeret Mono',monospace;">{{ $step['desc'] }}</p>
                        </div>
                        <div class="{{ $step['bar'] }}" style="height:5px;width:100%;"></div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── WHY CHOOSE US ── --}}
    <section class="sec" style="background:#161616;">
        <div class="sec-inner">
            <div class="sec-head">
                <div class="sec-badge" style="border-color:rgba(221,242,71,0.2);color:#DDF247;">Why Us</div>
                <h2 class="sec-h2">The Smart Way to Buy Digital Tokens</h2>
                <p class="sec-sub">Thousands of South Africans trust us for fast, reliable delivery</p>
            </div>

            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => '⚡', 'title' => 'Instant Delivery',  'desc' => 'Tokens appear on screen and in your inbox within seconds of payment — no delays.'],
                    ['icon' => '🛡️', 'title' => '100% Secure',       'desc' => 'PCI-DSS compliant encrypted gateways. We never store your card details.'],
                    ['icon' => '🗂️', 'title' => 'Widest Selection',  'desc' => 'Gaming, streaming, shopping, data — more token types than any other local store.'],
                    ['icon' => '👤', 'title' => 'No Account Needed', 'desc' => 'Just enter your email and pay. No registration, no passwords, no friction.'],
                    ['icon' => '📧', 'title' => 'Email Backup',      'desc' => 'Every purchase is emailed so you always have a safe record of your token code.'],
                    ['icon' => '🔄', 'title' => 'Regular Restocks',  'desc' => 'Sold out? We restock frequently. Contact us for high-volume business orders.'],
                ] as $feat)
                    <div
                        style="background:#1e1e1e;border-radius:22px;border:1px solid rgba(255,255,255,0.07);padding:32px;transition:all 0.3s ease;"
                        onmouseenter="this.style.borderColor='rgba(221,242,71,0.20)';this.style.transform='translateY(-5px)';this.style.boxShadow='0 20px 50px rgba(0,0,0,0.4)';"
                        onmouseleave="this.style.borderColor='rgba(255,255,255,0.07)';this.style.transform='';this.style.boxShadow='';"
                    >
                        <div style="font-size:34px;margin-bottom:18px;">{{ $feat['icon'] }}</div>
                        <h4 style="font-size:18px;font-weight:800;color:#fff;margin-bottom:10px;font-family:'Manrope',sans-serif;">{{ $feat['title'] }}</h4>
                        <p style="font-size:13px;line-height:22px;color:rgba(255,255,255,0.48);font-family:'Azeret Mono',monospace;">{{ $feat['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── ABOUT ── --}}
    <section id="about" class="sec" style="background:#111111;">
        <div class="sec-inner">
            <div class="grid items-center gap-16 lg:grid-cols-2">
                <div>
                    <div class="mb-6 inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-bold uppercase tracking-widest" style="border-color:rgba(221,242,71,0.2);color:#DDF247;">About Us</div>
                    <h2 class="mb-7 text-4xl font-extrabold text-white" style="font-family:'Manrope',sans-serif;">South Africa's Trusted Digital Token Store</h2>
                    <p class="mb-5 leading-relaxed" style="color:rgba(255,255,255,0.55);font-family:'Azeret Mono',monospace;font-size:14px;line-height:26px;">
                        We are a South African company with one mission: making digital tokens and vouchers accessible to everyone — fast and affordably. PlayStation credits for gaming, Netflix tokens for family nights, Takealot vouchers for shopping, airtime for friends. Whatever you need, we have it.
                    </p>
                    <p class="mb-9 leading-relaxed" style="color:rgba(255,255,255,0.55);font-family:'Azeret Mono',monospace;font-size:14px;line-height:26px;">
                        Our platform is fully automated. The moment your payment clears, your token is on its way. No middlemen, no manual processing, no waiting.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (['Locally owned & operated', 'Automated 24/7 delivery', 'Secure & encrypted'] as $pill)
                            <span style="display:inline-flex;align-items:center;gap:8px;background:#1e1e1e;border:1px solid rgba(255,255,255,0.09);border-radius:999px;padding:9px 18px;font-size:13px;color:rgba(255,255,255,0.75);font-family:'Manrope',sans-serif;font-weight:600;">
                                <svg style="width:14px;height:14px;color:#4ade80;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                                {{ $pill }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-5">
                    @foreach ([
                        ['val' => '24/7',  'color' => '#DDF247', 'label' => 'Always open, always delivering'],
                        ['val' => '<30s',  'color' => '#f472b6', 'label' => 'Average token delivery time'],
                        ['val' => '100%',  'color' => '#4ade80', 'label' => 'Secure payment processing'],
                        ['val' => '15+',   'color' => '#818cf8', 'label' => 'Token categories stocked'],
                    ] as $stat)
                        <div style="background:#1e1e1e;border-radius:22px;border:1px solid rgba(255,255,255,0.07);padding:32px 24px;text-align:center;">
                            <div style="font-size:42px;font-weight:900;color:{{ $stat['color'] }};margin-bottom:10px;font-family:'Manrope',sans-serif;line-height:1;">{{ $stat['val'] }}</div>
                            <p style="font-size:12px;color:rgba(255,255,255,0.45);font-family:'Azeret Mono',monospace;line-height:18px;">{{ $stat['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ── FAQ ── --}}
    <section id="faq" class="sec" style="background:#161616;">
        <div class="sec-inner-sm">
            <div class="sec-head">
                <div class="sec-badge" style="border-color:rgba(221,242,71,0.2);color:#DDF247;">FAQ</div>
                <h2 class="sec-h2">Frequently Asked Questions</h2>
                <p class="sec-sub">Everything you need to know before buying</p>
            </div>

            <div class="space-y-3">
                @foreach ([
                    ['q' => 'What types of tokens do you sell?',    'a' => 'We stock PlayStation Network, Xbox, Steam, Nintendo, Epic Games, Roblox, Netflix, Showmax, DStv, Spotify, Google Play, Apple, Takealot vouchers, prepaid data, airtime and more. Our catalog grows regularly.'],
                    ['q' => 'How quickly will I receive my token?', 'a' => 'Instantly. Your token code appears on screen and is emailed within 5–30 seconds of a confirmed payment.'],
                    ['q' => 'Do I need to create an account?',      'a' => 'No account needed. Just enter your email at checkout, pay, and your token is delivered immediately.'],
                    ['q' => 'What payment methods do you accept?',  'a' => 'We accept credit/debit cards and other secure payment options shown at checkout.'],
                    ['q' => 'What if I entered the wrong email?',   'a' => 'Contact us immediately with your transaction reference number and we will assist you.'],
                    ['q' => 'Are your tokens genuine?',             'a' => 'Yes. All tokens are sourced from authorised distributors — 100% genuine, unused, and valid on their platforms.'],
                    ['q' => 'Can I buy in bulk for my business?',   'a' => 'Yes — contact us for bulk pricing and we will arrange a custom deal.'],
                ] as $faq)
                    <div class="faq-item" style="background:#1e1e1e;border-radius:16px;border:1px solid rgba(255,255,255,0.07);overflow:hidden;transition:border-color 0.3s ease;">
                        <button class="faq-trigger" style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:22px 26px;text-align:left;background:none;border:none;color:#fff;font-size:16px;font-weight:700;font-family:'Manrope',sans-serif;gap:16px;">
                            <span>{{ $faq['q'] }}</span>
                            <svg class="faq-chevron" style="flex-shrink:0;width:18px;height:18px;color:#DDF247;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div class="faq-answer" style="padding:0 26px;">
                            <p style="padding-bottom:22px;font-size:14px;line-height:24px;color:rgba(255,255,255,0.50);font-family:'Azeret Mono',monospace;">{{ $faq['a'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA BANNER ── --}}
    <section class="sec-sm" style="background:linear-gradient(135deg,#1a1a00 0%,#111111 50%,#0d1a00 100%);position:relative;overflow:hidden;">
        <div style="position:absolute;inset:0;background:radial-gradient(ellipse at center,rgba(221,242,71,0.08) 0%,transparent 65%);pointer-events:none;"></div>
        <div style="position:relative;max-width:40rem;margin:0 auto;padding:0 24px;text-align:center;">
            <div style="margin-bottom:28px;display:inline-flex;width:64px;height:64px;align-items:center;justify-content:center;border-radius:16px;background:rgba(221,242,71,0.1);border:1px solid rgba(221,242,71,0.2);">
                <svg style="width:32px;height:32px;" viewBox="0 0 24 24" fill="#DDF247"><path d="M13 3L4 14h7l-2 7 9-11h-7l2-7z"/></svg>
            </div>
            <h2 class="sec-h2">Ready to Get Your Token?</h2>
            <p class="sec-sub" style="margin-bottom:36px;">Browse our live catalog and get your code delivered in seconds.</p>
            <a href="{{ route('shop') }}" wire:navigate class="btn-primary text-base">
                <svg style="width:16px;height:16px;" viewBox="0 0 24 24" fill="currentColor"><path d="M13 3L4 14h7l-2 7 9-11h-7l2-7z"/></svg>
                Browse Tokens Now
            </a>
        </div>
    </section>

    {{-- ── CHECKOUT FLYOUT ── --}}
    <flux:modal
        wire:model="showCheckout"
        flyout
        position="right"
        :dismissible="$step !== 2"
        class="md:w-[28rem]"
    >
        <div class="flex h-full flex-col gap-6 p-6">

            {{-- Header --}}
            <div>
                <flux:heading size="lg">
                    @if ($step === 1) Your Details
                    @elseif ($step === 2) Review & Pay
                    @else Order {{ $paymentSucceeded ? 'Confirmed' : 'Status' }}
                    @endif
                </flux:heading>

                @if ($this->selectedCategory && $step < 3)
                    <flux:text class="mt-1">
                        {{ $this->selectedCategory->name }} —
                        <strong>R{{ number_format($this->selectedCategory->price, 2) }}</strong>
                    </flux:text>
                @endif

                @if ($step < 3)
                    <div class="mt-4 flex items-center gap-2">
                        <div @class(['h-1.5 flex-1 rounded-full', 'bg-violet-500' => $step >= 1, 'bg-zinc-700' => $step < 1])></div>
                        <div @class(['h-1.5 flex-1 rounded-full transition-all', 'bg-violet-500' => $step >= 2, 'bg-zinc-700' => $step < 2])></div>
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
                        description="Your token will be sent to this address."
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

                    <div class="mt-auto">
                        <flux:button
                            type="submit"
                            variant="primary"
                            class="w-full bg-violet-600 hover:bg-violet-500"
                        >
                            <span wire:loading.remove wire:target="goToPayment" class="flex items-center gap-2">
                                Continue to Payment
                                <flux:icon.arrow-right class="size-4" />
                            </span>
                            <span wire:loading wire:target="goToPayment" class="flex items-center gap-2">
                                <flux:icon.loading class="size-4 animate-spin" />
                                Preparing your order…
                            </span>
                        </flux:button>
                    </div>
                </form>
            @endif

            {{-- Step 2: Review & pay --}}
            @if ($step === 2)
                <div class="flex flex-1 flex-col gap-5">

                    {{-- Order summary --}}
                    <div class="space-y-3 rounded-xl border border-zinc-700 bg-zinc-800/60 p-5">
                        <div class="flex items-center justify-between text-sm">
                            <flux:text>Product</flux:text>
                            <span class="font-semibold text-zinc-100">{{ $this->selectedCategory?->name }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <flux:text>Email</flux:text>
                            <span class="max-w-[180px] truncate text-sm font-medium text-zinc-100">{{ $customerEmail }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-zinc-700 pt-3">
                            <flux:heading size="sm">Total</flux:heading>
                            <span class="text-xl font-bold text-violet-400">
                                R{{ number_format($this->selectedCategory?->price ?? 0, 2) }}
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 rounded-lg border border-green-800 bg-green-950/40 px-4 py-3 text-sm text-green-300">
                        <flux:icon.lock-closed class="size-4 shrink-0" />
                        Your payment is processed securely and encrypted end-to-end.
                    </div>

                    {{-- PayFast onsite overlay --}}
                    @if ($checkoutType === 'onsite')
                        <div
                            x-data="{ processing: false, pfUuid: '' }"
                            x-init="pfUuid = $wire.paymentUuid || ''"
                            x-on:payfast-uuid-ready.window="pfUuid = $event.detail.uuid"
                            class="mt-auto space-y-3"
                        >
                            <flux:button
                                x-bind:disabled="processing || !pfUuid"
                                x-on:click="processing = true; window.payfast_do_onsite_payment({ uuid: pfUuid }, (result) => { processing = false; result === true ? $wire.finalizeOrder() : $wire.paymentFailed(); });"
                                variant="primary"
                                class="w-full bg-violet-600 hover:bg-violet-500"
                            >
                                <span x-show="! processing" class="flex items-center justify-center gap-2">
                                    <flux:icon.lock-closed class="size-4" />
                                    Pay Securely — R{{ number_format($this->selectedCategory?->price ?? 0, 2) }}
                                </span>
                                <span x-show="processing" class="flex items-center justify-center gap-2">
                                    <flux:icon.loading class="size-4 animate-spin" />
                                    Opening secure payment…
                                </span>
                            </flux:button>

                            <flux:button
                                wire:click="cancelPayment"
                                variant="ghost"
                                class="w-full"
                                x-bind:disabled="processing"
                            >
                                Back
                            </flux:button>
                        </div>
                    @endif

                    {{-- SnapScan QR code --}}
                    @if ($checkoutType === 'qr')
                        <div
                            class="mt-auto space-y-4"
                            wire:poll.2500ms="pollPaymentStatus"
                        >
                            <div class="flex flex-col items-center gap-3 rounded-xl border border-zinc-700 bg-zinc-800/60 p-5">
                                <flux:text class="text-sm text-zinc-400">Scan this QR code with your banking app to pay</flux:text>
                                <img
                                    src="{{ $qrUrl }}"
                                    alt="SnapScan QR code"
                                    class="size-48 rounded-lg"
                                />
                                <flux:text class="text-xs text-zinc-500">
                                    Once scanned, this page will update automatically.
                                </flux:text>
                            </div>

                            <flux:button
                                wire:click="cancelPayment"
                                variant="ghost"
                                class="w-full"
                            >
                                Cancel
                            </flux:button>
                        </div>
                    @endif

                    {{-- DPO redirect --}}
                    @if ($checkoutType === 'redirect')
                        <div class="mt-auto space-y-3">
                            <a href="{{ $redirectUrl }}" class="block w-full">
                                <flux:button
                                    variant="primary"
                                    class="w-full bg-violet-600 hover:bg-violet-500"
                                >
                                    <flux:icon.arrow-top-right-on-square class="size-4" />
                                    Proceed to Secure Checkout
                                </flux:button>
                            </a>

                            <flux:button
                                wire:click="cancelPayment"
                                variant="ghost"
                                class="w-full"
                            >
                                Back
                            </flux:button>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Step 3: Result --}}
            @if ($step === 3)
                <div
                    class="flex flex-1 flex-col items-center justify-center gap-6 text-center"
                    @if ($pollingForItn) wire:poll.2500ms="pollPaymentStatus" @endif
                >
                    @if ($pollingForItn)
                        <div class="flex size-20 items-center justify-center rounded-full bg-violet-500/10">
                            <flux:icon.loading class="size-10 animate-spin text-violet-400" />
                        </div>
                        <div>
                            <flux:heading size="lg">Confirming Payment…</flux:heading>
                            <flux:text class="mt-1 text-zinc-400">
                                Please wait while we confirm your payment.
                            </flux:text>
                        </div>
                    @elseif ($paymentSucceeded)
                        <div class="flex size-20 items-center justify-center rounded-full bg-green-500/10">
                            <flux:icon.check-circle class="size-10 text-green-500" />
                        </div>

                        <div>
                            <flux:heading size="lg">Payment Successful!</flux:heading>
                            <flux:text class="mt-1">
                                Your token has been delivered to
                                <strong>{{ $customerEmail }}</strong>.
                            </flux:text>
                        </div>

                        <div class="w-full rounded-xl border border-violet-500/40 bg-violet-500/10 px-6 py-5">
                            <flux:text class="mb-2 text-xs uppercase tracking-widest text-zinc-400">Your Token</flux:text>
                            <p class="font-mono text-2xl font-bold tracking-widest text-violet-300">
                                {{ $purchasedTokenCode }}
                            </p>
                        </div>

                        <flux:text class="text-sm text-zinc-400">
                            Screenshot or copy your token. A confirmation email is on its way.
                        </flux:text>
                    @else
                        <div class="flex size-20 items-center justify-center rounded-full bg-red-500/10">
                            <flux:icon.x-circle class="size-10 text-red-500" />
                        </div>

                        <div>
                            <flux:heading size="lg">Payment Failed</flux:heading>
                            <flux:text class="mt-2">{{ $paymentError }}</flux:text>
                        </div>

                        <flux:button
                            wire:click="$set('step', 1)"
                            variant="primary"
                            class="w-full bg-violet-600 hover:bg-violet-500"
                        >
                            Try Again
                        </flux:button>
                    @endif

                    <flux:modal.close>
                        <flux:button variant="ghost" class="w-full">Close</flux:button>
                    </flux:modal.close>
                </div>
            @endif

        </div>
    </flux:modal>

</div>
