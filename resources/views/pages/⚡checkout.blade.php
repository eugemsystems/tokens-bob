<?php

use App\Actions\InitiateCartCheckout;
use App\Contracts\DirectCardGateway;
use App\Contracts\SeamlessGateway;
use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Jobs\SendPurchaseEmail;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\GatewayManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Checkout')] #[Layout('layouts.public')] class extends Component
{
    /** @var array<int, int> category_id => quantity */
    public array $cart = [];

    public int $step = 1;

    #[Validate('required|email|max:254')]
    public string $customerEmail = '';

    #[Validate('required|string|max:20')]
    public string $customerPhone = '';

    public string $checkoutType = '';
    public string $paymentError = '';

    // Whop
    public string $whopCheckoutId = '';
    public string $whopPlanId = '';
    public string $whopPrefillEmail = '';

    // PayFast
    public string $paymentUuid = '';

    // QR / Popup
    public string $qrUrl = '';
    public string $redirectUrl = '';
    public bool $pollingForPayment = false;
    public int $pollAttempts = 0;

    // Flutterwave card
    /** @var array<string, mixed> */
    public array $flwData = [];
    public string $cardNumber = '';
    public string $cardName = '';
    public string $cardExpiry = '';
    public string $cardCvv = '';
    public string $cardAuthMode = '';
    public string $cardRef = '';
    public string $cardPin = '';
    public string $cardOtp = '';
    public string $cardAuthMessage = '';

    // PesePay seamless
    public string $pesepayPaymentMethod = '';
    public string $pesepayPhone = '';
    public string $pesepayReferenceNumber = '';
    /** @var array<int, array{code: string, name: string, requires_phone: bool, is_redirect: bool}> */
    public array $pesepayPaymentMethods = [];

    public ?int $pendingTransactionId = null;

    private const MAX_POLL_ATTEMPTS = 120;

    #[Computed]
    public function cartCategories(): Collection
    {
        if (empty($this->cart)) {
            return collect();
        }

        return Category::whereIn('id', array_keys($this->cart))->get()->keyBy('id');
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

    public function mount(): void
    {
        $productId = (int) request()->query('product', 0);

        if ($productId > 0) {
            $category = Category::find($productId);

            if ($category) {
                $this->cart = [$productId => 1];
                session(['checkout_cart' => $this->cart]);
            }
        }

        if (empty($this->cart)) {
            $this->cart = session('checkout_cart', []);
        }

        if (empty($this->cart)) {
            $this->redirect(route('shop'), navigate: true);
        }

        $pool = array_filter(array_map('trim', explode("\n", Setting::get('whop_email_pool', ''))));

        $this->whopPrefillEmail = ! empty($pool)
            ? $pool[array_rand($pool)]
            : Setting::get('whop_prefill_email', '');
    }

    public function goToPayment(): void
    {
        $this->validateOnly('customerEmail');
        $this->validateOnly('customerPhone');

        $this->paymentError = '';

        $result = app(InitiateCartCheckout::class)->execute(
            cart: $this->cart,
            customerData: [
                'email' => $this->customerEmail,
                'phone' => $this->customerPhone,
                'ip'    => request()->ip(),
            ],
        );

        if (! $result['success']) {
            $this->paymentError = $result['message'];

            return;
        }

        $this->checkoutType         = $result['checkout_type'];
        $this->pendingTransactionId = $result['transaction_id'];

        if ($result['checkout_type'] === 'whop') {
            $this->whopCheckoutId  = $result['data']['checkout_id'];
            $this->whopPlanId      = $result['data']['plan_id'];
            $this->pollingForPayment = true;
            $this->pollAttempts    = 0;
        } elseif ($result['checkout_type'] === 'onsite') {
            $this->paymentUuid = $result['data']['uuid'];
            $this->dispatch('payfast-uuid-ready', uuid: $result['data']['uuid']);
        } elseif ($result['checkout_type'] === 'qr') {
            $this->qrUrl            = $result['data']['qr_url'];
            $this->pollingForPayment = true;
            $this->pollAttempts     = 0;
        } elseif ($result['checkout_type'] === 'redirect') {
            $this->redirectUrl = $result['data']['redirect_url'];
        } elseif ($result['checkout_type'] === 'inline') {
            $this->flwData = $result['data'];
            $this->dispatch('flw-open', data: $result['data']);
        } elseif ($result['checkout_type'] === 'popup') {
            $this->redirectUrl      = $result['data']['redirect_url'];
            $this->pollingForPayment = true;
            $this->pollAttempts     = 0;
        } elseif ($result['checkout_type'] === 'seamless') {
            $this->pesepayPaymentMethods = $result['data']['payment_methods'] ?? [];
            $this->pesepayPhone          = $this->customerPhone;
        }

        $this->step = 2;
    }

    public function pollPaymentStatus(): void
    {
        if (! $this->pollingForPayment) {
            return;
        }

        $this->pollAttempts++;
        $transaction = Transaction::find($this->pendingTransactionId);

        if ($transaction && $transaction->status === TransactionStatus::Completed) {
            $this->pollingForPayment = false;
            session()->forget('checkout_cart');
            $this->redirect(route('order', $transaction->id));

            return;
        }

        if ($transaction && $transaction->status === TransactionStatus::Failed) {
            $this->pollingForPayment = false;
            $this->paymentError     = 'Your payment was not completed.';
            $this->step             = 1;

            return;
        }

        if ($this->pollAttempts >= self::MAX_POLL_ATTEMPTS) {
            $this->pollingForPayment = false;
            $this->paymentError     = 'Payment confirmation is taking too long. Contact support with reference #'.$this->pendingTransactionId.'.';
            $this->step             = 1;
        }
    }

    public function finalizeOrder(): void
    {
        $transaction = Transaction::find($this->pendingTransactionId);

        if (! $transaction) {
            return;
        }

        if ($transaction->status === TransactionStatus::Completed) {
            session()->forget('checkout_cart');
            $this->redirect(route('order', $transaction->id));

            return;
        }

        $this->pollingForPayment = true;
        $this->pollAttempts     = 0;
    }

    public function cancelPayment(): void
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

        $this->reset([
            'whopCheckoutId', 'whopPlanId', 'paymentUuid', 'qrUrl', 'redirectUrl',
            'checkoutType', 'pendingTransactionId', 'pollingForPayment', 'pollAttempts',
            'flwData', 'cardAuthMode', 'cardRef', 'cardPin', 'cardOtp', 'cardAuthMessage',
            'pesepayPaymentMethod', 'pesepayPhone', 'pesepayReferenceNumber', 'pesepayPaymentMethods',
        ]);
        $this->paymentError = 'Payment cancelled — your cart is still saved.';
        $this->step         = 1;
    }

    public function submitPesepayPayment(): void
    {
        if ($this->pesepayPaymentMethod === 'PZW211' && empty(trim($this->pesepayPhone))) {
            $this->addError('pesepayPhone', 'Phone number is required for EcoCash.');

            return;
        }

        $this->paymentError = '';

        $transaction = Transaction::find($this->pendingTransactionId);

        if (! $transaction) {
            $this->paymentError = 'Transaction not found.';
            $this->step         = 1;

            return;
        }

        $gateway = app(GatewayManager::class)->active();

        if (! $gateway instanceof SeamlessGateway) {
            return;
        }

        if ($this->pesepayPaymentMethod === 'CARD') {
            $result = $gateway->initiateTransaction($transaction);

            if (! $result['success']) {
                $this->paymentError = $result['message'];

                return;
            }

            $transaction->update(['gateway_payment_id' => $result['reference_number']]);
            $this->pesepayReferenceNumber = $result['reference_number'];
            $this->redirectUrl            = $result['redirect_url'];
            $this->pollingForPayment      = true;
            $this->pollAttempts           = 0;

            return;
        }

        $result = $gateway->makePayment($transaction, $this->pesepayPaymentMethod, $this->pesepayPhone);

        if (! $result['success']) {
            $this->paymentError = $result['message'];

            return;
        }

        $transaction->update(['gateway_payment_id' => $result['reference_number']]);

        $this->pesepayReferenceNumber = $result['reference_number'];
        $this->pollingForPayment      = true;
        $this->pollAttempts           = 0;
    }

    public function pollPesepayPayment(): void
    {
        if (! $this->pollingForPayment || ! $this->pesepayReferenceNumber) {
            return;
        }

        $this->pollAttempts++;

        $transaction = Transaction::find($this->pendingTransactionId);

        if ($transaction && $transaction->status === TransactionStatus::Completed) {
            $this->pollingForPayment = false;
            session()->forget('checkout_cart');
            $this->redirect(route('order', $transaction->id));

            return;
        }

        if ($transaction && $transaction->status === TransactionStatus::Failed) {
            $this->pollingForPayment  = false;
            $this->paymentError       = 'Your payment was not completed.';
            $this->step               = 1;

            return;
        }

        $gateway = app(GatewayManager::class)->active();

        if ($gateway instanceof SeamlessGateway) {
            $status = $gateway->checkStatus($this->pesepayReferenceNumber);

            if ($status && $status['transaction_status'] === 'SUCCESS') {
                if ($transaction && $transaction->status !== TransactionStatus::Completed) {
                    DB::transaction(function () use ($transaction): void {
                        $transaction->update([
                            'status'             => TransactionStatus::Completed,
                            'gateway_payment_id' => $this->pesepayReferenceNumber,
                        ]);

                        Token::where('transaction_id', $transaction->id)
                            ->where('status', TokenStatus::Reserved)
                            ->update(['status' => TokenStatus::Sold]);
                    });

                    SendPurchaseEmail::dispatch($transaction->id, 'token');
                }

                $this->pollingForPayment = false;
                session()->forget('checkout_cart');
                $this->redirect(route('order', $transaction->id));

                return;
            }

            $terminalStatuses = ['FAILED', 'CANCELLED', 'DECLINED', 'ERROR', 'TIME_OUT', 'AUTHORIZATION_FAILED', 'TERMINATED'];

            if ($status && in_array($status['transaction_status'], $terminalStatuses, true)) {
                DB::transaction(function () use ($transaction): void {
                    $transaction->update(['status' => TransactionStatus::Failed]);

                    Token::where('transaction_id', $transaction->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
                });

                $this->pollingForPayment = false;
                $this->paymentError      = 'Your payment was declined or cancelled.';
                $this->step              = 1;

                return;
            }
        }

        if ($this->pollAttempts >= self::MAX_POLL_ATTEMPTS) {
            $this->pollingForPayment = false;
            $this->paymentError     = 'Payment confirmation is taking too long. Contact support with reference #'.$this->pendingTransactionId.'.';
            $this->step             = 1;
        }
    }

    public function finalizeCardPayment(int|string $gatewayTxnId): void
    {
        $transaction = Transaction::find($this->pendingTransactionId);

        if (! $transaction || $transaction->status === TransactionStatus::Completed) {
            if ($transaction) {
                session()->forget('checkout_cart');
                $this->redirect(route('order', $transaction->id));
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
            SendPurchaseEmail::dispatch($transaction->id, 'token');
            session()->forget('checkout_cart');
            $this->redirect(route('order', $transaction->id));

            return;
        }

        $this->paymentError = 'Payment could not be verified. Contact support with reference #'.$transaction->id.'.';
        $this->flwData      = [];
        $this->step         = 1;
    }

    public function submitCardPayment(): void
    {
        $transaction = Transaction::find($this->pendingTransactionId);

        if (! $transaction) {
            $this->paymentError = 'Transaction not found.';
            $this->step         = 1;

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
            $this->cardAuthMessage = $result['message'] ?? 'Enter the OTP sent to your phone or email.';

            return;
        }

        if ($status === 'redirect') {
            $this->redirectUrl  = $result['redirect_url'] ?? '';
            $this->checkoutType = 'redirect';

            return;
        }

        $this->paymentError = $result['message'] ?? 'Payment failed. Check your card details and try again.';
        $this->step         = 1;
    }
}; ?>

<div style="font-family:'Manrope',sans-serif;background:#111111;min-height:100vh;">

    {{-- ── HEADER ── --}}
    <div style="background:#161616;border-bottom:1px solid rgba(255,255,255,0.07);padding:20px 0;">
        <div style="max-width:1100px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:16px;">
            <a href="{{ route('shop') }}" wire:navigate style="display:flex;align-items:center;gap:6px;color:rgba(255,255,255,0.40);text-decoration:none;font-size:13px;font-weight:600;"
               onmouseenter="this.style.color='rgba(255,255,255,0.70)'" onmouseleave="this.style.color='rgba(255,255,255,0.40)'">
                <svg style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
                </svg>
                Back to Shop
            </a>
            <span style="color:rgba(255,255,255,0.12);">|</span>
            <span style="font-size:16px;font-weight:800;color:#fff;">Checkout</span>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════════
         WHOP FULL-PAGE VIEW — replaces the two-column layout entirely
    ══════════════════════════════════════════════════════════════════════════ --}}
    @if ($step === 2 && $checkoutType === 'whop')

        {{-- Hidden poll trigger — separate element so wire:ignore can protect the iframe --}}
        <div wire:poll.2500ms="pollPaymentStatus" style="display:none;position:absolute;pointer-events:none;"></div>

        <div style="max-width:700px;margin:0 auto;padding:40px 24px 80px;">

            {{-- Summary bar --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;gap:16px;flex-wrap:wrap;">
                <div>
                    <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:3px;color:rgba(255,255,255,0.35);margin:0 0 4px;font-family:'Azeret Mono',monospace;">Completing payment</p>
                    <p style="font-size:28px;font-weight:900;color:#DDF247;margin:0 0 2px;line-height:1;">R{{ number_format($this->cartTotal, 2) }}</p>
                    <p style="font-size:12px;color:rgba(255,255,255,0.40);margin:0;font-family:'Azeret Mono',monospace;">{{ $customerEmail }}</p>
                </div>
                <button
                    wire:click="cancelPayment"
                    style="font-size:12px;color:rgba(255,255,255,0.35);background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.10);border-radius:10px;padding:8px 16px;font-family:'Manrope',sans-serif;font-weight:700;cursor:pointer;"
                    onmouseenter="this.style.color='rgba(255,255,255,0.65)';this.style.background='rgba(255,255,255,0.09)'"
                    onmouseleave="this.style.color='rgba(255,255,255,0.35)';this.style.background='rgba(255,255,255,0.05)'"
                >← Cancel</button>
            </div>

            {{-- Whop embedded checkout.
                 wire:ignore prevents Livewire from destroying the subtree on every 2.5s poll.
                 x-init injects the Whop script after the data-attribute div is in the DOM. --}}
            <div
                wire:ignore
                x-data="{ loaded: false }"
                x-init="
                    $nextTick(function () {
                        if (!document.querySelector('script[src*=\'js.whop.com\']')) {
                            var s = document.createElement('script');
                            s.src = 'https://js.whop.com/static/checkout/loader.js';
                            s.async = true;
                            document.head.appendChild(s);
                        }
                        loaded = true;
                    });
                "
                style="width:100%;border-radius:14px;background:#0b0b12;min-height:460px;overflow:hidden;"
            >
                {{-- Loading skeleton --}}
                <div x-show="!loaded" style="display:flex;align-items:center;justify-content:center;height:460px;">
                    <svg style="width:28px;height:28px;color:#DDF247;animation:spin 1s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
                </div>

                <div
                    data-whop-checkout-plan-id="{{ $whopPlanId }}"
                    data-whop-checkout-session="{{ $whopCheckoutId }}"
                    data-whop-checkout-prefill-email="{{ $whopPrefillEmail }}"
                    data-whop-checkout-disable-email="true"
                    data-whop-checkout-hide-email="true"
                    data-whop-checkout-hide-tos="true"
                    @if (config('whop.sandbox')) data-whop-checkout-environment="sandbox" @endif
                    style="width:100%;margin-bottom:-60px;"
                ></div>
            </div>

            <p style="text-align:center;font-size:11px;color:rgba(255,255,255,0.22);font-family:'Azeret Mono',monospace;margin-top:16px;">
                This page confirms automatically once payment is completed.
            </p>

        </div>

    {{-- ══════════════════════════════════════════════════════════════════════════
         NORMAL TWO-COLUMN LAYOUT — step 1 (form) and all non-Whop payment types
    ══════════════════════════════════════════════════════════════════════════ --}}
    @else

        <div style="max-width:1100px;margin:0 auto;padding:40px 24px 80px;overflow:hidden;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:start;min-width:0;">

                {{-- ── LEFT: ORDER SUMMARY ── --}}
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <h2 style="font-size:13px;font-weight:700;color:rgba(255,255,255,0.40);text-transform:uppercase;letter-spacing:3px;margin:0;">Order Summary</h2>

                    <div style="background:#1a1a1a;border-radius:20px;border:1px solid rgba(255,255,255,0.08);overflow:hidden;">
                        @foreach ($this->cartCategories as $categoryId => $cat)
                            @php $qty = $this->cart[$categoryId] ?? 0; @endphp
                            @if ($qty > 0)
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.05);gap:12px;">
                                    <div style="flex:1;min-width:0;overflow:hidden;">
                                        <p style="font-size:14px;font-weight:700;color:#fff;margin:0 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $cat->name }}</p>
                                        @if ($cat->description)
                                            <p style="font-size:12px;color:rgba(255,255,255,0.35);margin:0;font-family:'Azeret Mono',monospace;overflow-wrap:break-word;word-break:break-word;">{{ $cat->description }}</p>
                                        @endif
                                    </div>
                                    <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                                        <span style="font-size:12px;color:rgba(255,255,255,0.35);font-family:'Azeret Mono',monospace;">×{{ $qty }}</span>
                                        <span style="font-size:15px;font-weight:800;color:#fff;">R{{ number_format($cat->price * $qty, 2) }}</span>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 20px;">
                            <span style="font-size:13px;font-weight:700;color:rgba(255,255,255,0.50);text-transform:uppercase;letter-spacing:2px;">Total</span>
                            <span style="font-size:26px;font-weight:900;color:#DDF247;">R{{ number_format($this->cartTotal, 2) }}</span>
                        </div>
                    </div>

                    <div style="background:#1a1a1a;border-radius:16px;border:1px solid rgba(255,255,255,0.07);padding:16px 20px;display:flex;flex-direction:column;gap:10px;">
                        @foreach ([['🔐','Encrypted & secure payment'],['⚡','Tokens delivered instantly after payment'],['📧','Confirmation sent to your email'],['🛡️','No card data stored on our servers']] as [$icon, $text])
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span style="font-size:15px;">{{ $icon }}</span>
                                <span style="font-size:12px;color:rgba(255,255,255,0.38);font-family:'Azeret Mono',monospace;">{{ $text }}</span>
                            </div>
                        @endforeach
                    </div>

                </div>

                {{-- ── RIGHT: PAYMENT ── --}}
                <div style="display:flex;flex-direction:column;gap:20px;">

                    {{-- Error banner --}}
                    @if ($paymentError)
                        <div style="display:flex;align-items:flex-start;gap:10px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:12px;padding:14px 16px;">
                            <svg style="width:16px;height:16px;color:#f87171;flex-shrink:0;margin-top:1px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                            <p style="font-size:13px;color:#f87171;margin:0;line-height:20px;">{{ $paymentError }}</p>
                        </div>
                    @endif

                    {{-- ── STEP 1: Customer details ── --}}
                    @if ($step === 1)
                        <div>
                            <h2 style="font-size:13px;font-weight:700;color:rgba(255,255,255,0.40);text-transform:uppercase;letter-spacing:3px;margin:0 0 16px;">Your Details</h2>
                            <div style="background:#1a1a1a;border-radius:20px;border:1px solid rgba(255,255,255,0.08);padding:28px;">
                                <form wire:submit="goToPayment" style="display:flex;flex-direction:column;gap:18px;">

                                    <flux:input wire:model="customerEmail" label="Email Address" type="email" placeholder="you@example.com" description="Your tokens and receipt will be sent here." icon="envelope" required autofocus />
                                    <flux:input wire:model="customerPhone" label="Phone Number" type="tel" placeholder="072 000 0000" icon="phone" required />

                                    <button
                                        type="submit"
                                        style="width:100%;background:#DDF247;color:#111;font-weight:900;font-size:15px;padding:15px;border-radius:13px;border:none;font-family:'Manrope',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;"
                                        onmouseenter="this.style.opacity='0.88'" onmouseleave="this.style.opacity='1'"
                                    >
                                        <span wire:loading.remove wire:target="goToPayment" style="display:flex;align-items:center;gap:8px;">
                                            Continue to Payment
                                            <svg style="width:17px;height:17px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                                        </span>
                                        <span wire:loading wire:target="goToPayment" style="display:flex;align-items:center;gap:8px;">
                                            <svg style="width:17px;height:17px;animation:spin 1s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
                                            Preparing…
                                        </span>
                                    </button>

                                </form>
                            </div>
                        </div>
                    @endif

                    {{-- ── STEP 2: Non-Whop payment UIs ── --}}
                    @if ($step === 2)

                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <h2 style="font-size:13px;font-weight:700;color:rgba(255,255,255,0.40);text-transform:uppercase;letter-spacing:3px;margin:0;">Payment</h2>
                            <button wire:click="cancelPayment" style="font-size:12px;color:rgba(255,255,255,0.35);background:none;border:none;font-family:'Manrope',sans-serif;font-weight:600;cursor:pointer;" onmouseenter="this.style.color='rgba(255,255,255,0.60)'" onmouseleave="this.style.color='rgba(255,255,255,0.35)'">← Go Back</button>
                        </div>

                        <div style="display:flex;align-items:center;gap:8px;background:rgba(34,197,94,0.05);border:1px solid rgba(34,197,94,0.18);border-radius:10px;padding:10px 14px;font-size:12px;color:#4ade80;font-family:'Azeret Mono',monospace;">
                            <svg style="width:13px;height:13px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            Paying R{{ number_format($this->cartTotal, 2) }} · {{ $customerEmail }}
                        </div>

                        {{-- PayFast onsite --}}
                        @if ($checkoutType === 'onsite' && ! $pollingForPayment)
                            <div x-data="{ processing: false, pfUuid: '' }" x-init="pfUuid = $wire.paymentUuid || ''" x-on:payfast-uuid-ready.window="pfUuid = $event.detail.uuid; processing = false;" style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:28px;">
                                <button x-bind:disabled="processing || !pfUuid" x-on:click="processing = true; window.payfast_do_onsite_payment({ uuid: pfUuid }, (result) => { result === true ? $wire.finalizeOrder() : ($wire.cancelPayment(), processing = false); });" style="width:100%;background:#DDF247;color:#111;font-weight:900;font-size:15px;padding:15px;border-radius:13px;border:none;font-family:'Manrope',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                                    <span x-show="!processing" style="display:flex;align-items:center;gap:8px;"><svg style="width:17px;height:17px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>Pay R{{ number_format($this->cartTotal, 2) }}</span>
                                    <span x-show="processing">Processing…</span>
                                </button>
                            </div>
                        @endif

                        {{-- QR scan --}}
                        @if ($checkoutType === 'qr')
                            <div wire:poll.2500ms="pollPaymentStatus" style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:28px;display:flex;flex-direction:column;align-items:center;gap:16px;">
                                <p style="font-size:12px;color:rgba(255,255,255,0.45);font-family:'Azeret Mono',monospace;margin:0;">Scan with your banking app to pay</p>
                                <img src="{{ $qrUrl }}" alt="QR code" style="width:200px;height:200px;border-radius:12px;" />
                                <p style="font-size:11px;color:rgba(255,255,255,0.25);font-family:'Azeret Mono',monospace;margin:0;">Page updates automatically on payment</p>
                            </div>
                        @endif

                        {{-- Redirect --}}
                        @if ($checkoutType === 'redirect')
                            <div style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:28px;">
                                <a href="{{ $redirectUrl }}" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;background:#DDF247;color:#111;font-weight:900;font-size:15px;padding:15px;border-radius:13px;text-decoration:none;font-family:'Manrope',sans-serif;">
                                    Proceed to Secure Checkout
                                    <svg style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                </a>
                            </div>
                        @endif

                        {{-- DPO popup --}}
                        @if ($checkoutType === 'popup')
                            <div wire:poll.2500ms="pollPaymentStatus" x-data="{ popup: null }" x-init="$nextTick(function () { popup = window.open('{{ addslashes($redirectUrl) }}', 'dpo_payment', 'width=720,height=680,scrollbars=yes,resizable=yes'); }); window.addEventListener('message', function (e) { if (e.data && e.data.dpoCancelled) { if (popup && !popup.closed) popup.close(); $wire.cancelPayment(); } });" style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:28px;display:flex;flex-direction:column;align-items:center;gap:16px;">
                                <svg style="width:40px;height:40px;color:rgba(139,92,246,0.70);animation:spin 1.2s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
                                <p style="font-size:14px;color:rgba(255,255,255,0.50);font-family:'Azeret Mono',monospace;margin:0;text-align:center;">Complete payment in the secure window that just opened.</p>
                                <button x-on:click="popup = window.open('{{ addslashes($redirectUrl) }}', 'dpo_payment', 'width=720,height=680,scrollbars=yes,resizable=yes')" style="font-size:13px;color:rgba(255,255,255,0.40);background:none;border:none;font-family:'Manrope',sans-serif;font-weight:600;cursor:pointer;">Reopen payment window ↗</button>
                            </div>
                        @endif

                        {{-- Flutterwave card form --}}
                        @if ($checkoutType === 'inline')
                            <div style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:28px;">
                                <form wire:submit="submitCardPayment" style="display:flex;flex-direction:column;gap:16px;">
                                    @if ($cardAuthMode === 'send_pin')
                                        <div style="background:rgba(234,179,8,0.08);border:1px solid rgba(234,179,8,0.25);border-radius:10px;padding:12px 14px;font-size:13px;color:#fbbf24;">Enter your card PIN to authorise this payment.</div>
                                        <flux:input wire:model="cardPin" label="Card PIN" type="password" placeholder="••••" inputmode="numeric" maxlength="6" />
                                        <button type="submit" style="width:100%;background:#DDF247;color:#111;font-weight:900;font-size:15px;padding:15px;border-radius:13px;border:none;font-family:'Manrope',sans-serif;cursor:pointer;">Submit PIN</button>
                                    @elseif ($cardAuthMode === 'send_otp')
                                        <div style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.25);border-radius:10px;padding:12px 14px;font-size:13px;color:#93c5fd;">{{ $cardAuthMessage ?: 'Enter the OTP sent to your registered phone or email.' }}</div>
                                        <flux:input wire:model="cardOtp" label="OTP" placeholder="Enter OTP" inputmode="numeric" maxlength="8" />
                                        <button type="submit" style="width:100%;background:#DDF247;color:#111;font-weight:900;font-size:15px;padding:15px;border-radius:13px;border:none;font-family:'Manrope',sans-serif;cursor:pointer;">Verify OTP</button>
                                    @else
                                        <flux:input wire:model="cardNumber" label="Card Number" placeholder="1234 5678 9012 3456" inputmode="numeric" autocomplete="cc-number" maxlength="19" />
                                        <flux:input wire:model="cardName" label="Name on Card" placeholder="John Doe" autocomplete="cc-name" />
                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                            <flux:input wire:model="cardExpiry" label="Expiry (MM/YY)" placeholder="MM/YY" inputmode="numeric" autocomplete="cc-exp" maxlength="5" />
                                            <flux:input wire:model="cardCvv" label="CVV" type="password" placeholder="•••" inputmode="numeric" autocomplete="cc-csc" maxlength="4" />
                                        </div>
                                        <button type="submit" style="width:100%;background:#DDF247;color:#111;font-weight:900;font-size:15px;padding:15px;border-radius:13px;border:none;font-family:'Manrope',sans-serif;cursor:pointer;">Pay R{{ number_format($this->cartTotal, 2) }}</button>
                                    @endif
                                </form>
                            </div>
                        @endif

                        {{-- PesePay seamless: method selector --}}
                        @if ($checkoutType === 'seamless' && ! $pesepayReferenceNumber)
                            <div style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:28px;display:flex;flex-direction:column;gap:20px;">
                                <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:rgba(255,255,255,0.35);margin:0;font-family:'Azeret Mono',monospace;">Select payment method</p>

                                <div style="display:flex;flex-direction:column;gap:10px;">
                                    @foreach ($pesepayPaymentMethods as $method)
                                        <button
                                            wire:click="$set('pesepayPaymentMethod', '{{ $method['code'] }}')"
                                            style="width:100%;text-align:left;padding:14px 18px;border-radius:12px;border:2px solid {{ $pesepayPaymentMethod === $method['code'] ? '#DDF247' : 'rgba(255,255,255,0.10)' }};background:{{ $pesepayPaymentMethod === $method['code'] ? 'rgba(221,242,71,0.06)' : 'rgba(255,255,255,0.02)' }};color:{{ $pesepayPaymentMethod === $method['code'] ? '#DDF247' : 'rgba(255,255,255,0.70)' }};font-family:'Manrope',sans-serif;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;transition:border-color .15s;"
                                        >
                                            {{ $method['name'] }}
                                            @if ($pesepayPaymentMethod === $method['code'])
                                                <svg style="width:16px;height:16px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>

                                @if ($pesepayPaymentMethod === 'PZW211')
                                    <div>
                                        <flux:input wire:model="pesepayPhone" label="EcoCash Phone Number" type="tel" placeholder="0777 000 000" />
                                        @error('pesepayPhone') <p style="font-size:12px;color:#f87171;margin:4px 0 0;">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                @if ($pesepayPaymentMethod === 'CARD')
                                    <div style="background:rgba(139,92,246,0.06);border:1px solid rgba(139,92,246,0.20);border-radius:10px;padding:12px 16px;">
                                        <p style="font-size:12px;color:rgba(190,170,255,0.85);font-family:'Azeret Mono',monospace;margin:0;line-height:1.7;">
                                            A secure PesePay card page opens in a popup — enter your Visa or Mastercard details there. This page updates automatically once payment is confirmed.
                                        </p>
                                    </div>
                                @endif

                                @if ($pesepayPaymentMethod)
                                    <button
                                        wire:click="submitPesepayPayment"
                                        style="width:100%;background:#DDF247;color:#111;font-weight:900;font-size:15px;padding:15px;border-radius:13px;border:none;font-family:'Manrope',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;"
                                        onmouseenter="this.style.opacity='0.88'" onmouseleave="this.style.opacity='1'"
                                    >
                                        <span wire:loading.remove wire:target="submitPesepayPayment" style="display:flex;align-items:center;gap:8px;">
                                            <svg style="width:17px;height:17px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                            Pay {{ strtoupper(config('pesepay.currency_code', 'USD')) }} {{ number_format($this->cartTotal, 2) }}
                                        </span>
                                        <span wire:loading wire:target="submitPesepayPayment" style="display:flex;align-items:center;gap:8px;">
                                            <svg style="width:17px;height:17px;animation:spin 1s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
                                            Sending request…
                                        </span>
                                    </button>
                                @endif
                            </div>
                        @endif

                        {{-- PesePay seamless: waiting for EcoCash / Innbucks phone confirmation --}}
                        @if ($checkoutType === 'seamless' && $pesepayReferenceNumber && ! $redirectUrl)
                            <div wire:poll.5000ms="pollPesepayPayment" style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:32px;display:flex;flex-direction:column;align-items:center;gap:16px;">
                                <svg style="width:40px;height:40px;color:#DDF247;animation:spin 1.2s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
                                <p style="font-size:15px;font-weight:800;color:#fff;margin:0;">Waiting for payment confirmation</p>
                                <p style="font-size:12px;color:rgba(255,255,255,0.45);font-family:'Azeret Mono',monospace;margin:0;text-align:center;">
                                    Check your {{ $pesepayPaymentMethod === 'PZW211' ? 'EcoCash' : 'Innbucks' }} for a payment prompt and approve to complete your order.
                                </p>
                                <p style="font-size:11px;color:rgba(255,255,255,0.20);font-family:'Azeret Mono',monospace;margin:0;">
                                    Ref: {{ $pesepayReferenceNumber }}
                                </p>
                            </div>
                        @endif

                        {{-- PesePay seamless: card payment popup --}}
                        @if ($checkoutType === 'seamless' && $pesepayReferenceNumber && $redirectUrl)
                            <div
                                wire:poll.4000ms="pollPesepayPayment"
                                x-data="{ popup: null }"
                                x-init="$nextTick(function () { popup = window.open('{{ addslashes($redirectUrl) }}', 'pesepay_card', 'width=760,height=700,scrollbars=yes,resizable=yes'); })"
                                style="background:#1a1a1a;border-radius:18px;border:1px solid rgba(255,255,255,0.08);padding:32px;display:flex;flex-direction:column;align-items:center;gap:16px;"
                            >
                                <svg style="width:40px;height:40px;color:rgba(139,92,246,0.80);animation:spin 1.2s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
                                <p style="font-size:15px;font-weight:800;color:#fff;margin:0;">Complete payment in the secure popup</p>
                                <p style="font-size:12px;color:rgba(255,255,255,0.45);font-family:'Azeret Mono',monospace;margin:0;text-align:center;">
                                    Enter your card details in the PesePay window that just opened. This page updates automatically once payment is confirmed.
                                </p>
                                <button
                                    x-on:click="popup = window.open('{{ addslashes($redirectUrl) }}', 'pesepay_card', 'width=760,height=700,scrollbars=yes,resizable=yes')"
                                    style="font-size:12px;color:rgba(139,92,246,0.70);background:none;border:1px solid rgba(139,92,246,0.25);border-radius:8px;padding:7px 14px;font-family:'Manrope',sans-serif;font-weight:700;cursor:pointer;"
                                    onmouseenter="this.style.borderColor='rgba(139,92,246,0.55)'" onmouseleave="this.style.borderColor='rgba(139,92,246,0.25)'"
                                >Reopen payment window ↗</button>
                                <p style="font-size:11px;color:rgba(255,255,255,0.20);font-family:'Azeret Mono',monospace;margin:0;">
                                    Ref: {{ $pesepayReferenceNumber }}
                                </p>
                            </div>
                        @endif

                        {{-- Generic polling spinner --}}
                        @if ($pollingForPayment && ! in_array($checkoutType, ['qr', 'popup', 'seamless']))
                            <div style="display:flex;flex-direction:column;align-items:center;gap:12px;padding:32px 0;" wire:poll.2500ms="pollPaymentStatus">
                                <svg style="width:36px;height:36px;color:rgba(139,92,246,0.70);animation:spin 1.2s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
                                <p style="font-size:13px;color:rgba(255,255,255,0.40);font-family:'Azeret Mono',monospace;margin:0;">Confirming your payment…</p>
                            </div>
                        @endif

                    @endif

                </div>
            </div>
        </div>

    @endif

    <style>
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @media (max-width: 700px) {
            [style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 480px) {
            [style*="justify-content:space-between"][style*="align-items:center"][style*="gap:12px"] {
                flex-wrap: wrap;
            }
        }
    </style>

</div>
