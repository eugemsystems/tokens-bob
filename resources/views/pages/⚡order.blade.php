<?php

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Token;
use App\Models\Transaction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order Confirmation')] #[Layout('layouts.public')] class extends Component
{
    public bool $found = false;

    public bool $isWebhookPurchase = false;

    public bool $isPartnerPurchase = false;

    public bool $polling = false;

    public bool $failed = false;

    public int $transactionId = 0;

    public float $amount = 0.0;

    public string $customerEmail = '';

    /** @var array<int, array{name: string, description: string|null, code: string}> */
    public array $tokens = [];

    public function mount(int $transactionId): void
    {
        $transaction = Transaction::find($transactionId);

        if (! $transaction) {
            return;
        }

        $this->transactionId = $transaction->id;
        $this->amount = (float) $transaction->amount;
        $this->customerEmail = $transaction->customer_email;
        $this->isWebhookPurchase = (bool) $transaction->is_webhook_purchase;
        $this->isPartnerPurchase = ! empty($transaction->partner_data['reference']);

        if ($transaction->status === TransactionStatus::Failed) {
            $this->failed = true;

            return;
        }

        if ($transaction->status === TransactionStatus::Pending) {
            return;
        }

        // Transaction is Completed.
        if ($this->isWebhookPurchase) {
            // Webhook-type purchase: no tokens delivered, just confirm payment received.
            $this->found = true;

            return;
        }

        $tokens = Token::with('category')
            ->where('transaction_id', $transaction->id)
            ->where('status', TokenStatus::Sold)
            ->get()
            ->map(fn (Token $t) => [
                'name'        => $t->category?->name ?? 'Token',
                'description' => $t->category?->description,
                'code'        => $t->token_code,
            ])
            ->toArray();

        if (empty($tokens)) {
            return;
        }

        $this->tokens = $tokens;
        $this->found = true;
    }

    public function pollStatus(): void
    {
        $transaction = Transaction::find($this->transactionId);

        if (! $transaction) {
            $this->polling = false;

            return;
        }

        if ($transaction->status === TransactionStatus::Completed) {
            $this->polling = false;
            $this->isWebhookPurchase = (bool) $transaction->is_webhook_purchase;
            $this->isPartnerPurchase = ! empty($transaction->partner_data['reference']);

            if ($this->isWebhookPurchase) {
                $this->found = true;

                return;
            }

            $this->tokens = Token::with('category')
                ->where('transaction_id', $transaction->id)
                ->where('status', TokenStatus::Sold)
                ->get()
                ->map(fn (Token $t) => [
                    'name'        => $t->category?->name ?? 'Token',
                    'description' => $t->category?->description,
                    'code'        => $t->token_code,
                ])
                ->toArray();

            if (! empty($this->tokens)) {
                $this->found = true;
            }
        } elseif ($transaction->status === TransactionStatus::Failed) {
            $this->polling = false;
            $this->failed = true;
        }
    }
}; ?>

<div style="font-family:'Manrope',sans-serif;background:#111111;min-height:100vh;">

    @if ($polling)

        {{-- ── POLLING STATE (waiting for Whop webhook) ── --}}
        <div style="max-width:480px;margin:0 auto;padding:120px 24px;text-align:center;" wire:poll.2500ms="pollStatus">
            <div style="display:flex;justify-content:center;margin-bottom:28px;">
                <div style="width:72px;height:72px;border-radius:50%;background:rgba(139,92,246,0.12);border:2px solid rgba(139,92,246,0.30);display:flex;align-items:center;justify-content:center;">
                    <svg style="width:32px;height:32px;color:#a78bfa;animation:spin 1s linear infinite;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path d="M12 3v3m0 12v3M3 12h3m12 0h3m-2.636-6.364-2.121 2.121M8.757 15.243l-2.121 2.121m0-14.728 2.121 2.121m6.486 6.486 2.121 2.121"/>
                    </svg>
                </div>
            </div>
            <h2 style="font-size:26px;font-weight:900;color:#fff;margin:0 0 10px;">Confirming your payment…</h2>
            <p style="font-size:14px;color:rgba(255,255,255,0.40);font-family:'Azeret Mono',monospace;line-height:22px;">
                Please wait while we confirm your payment with Whop.<br>This usually takes just a few seconds.
            </p>
        </div>

    @elseif ($failed)

        {{-- ── FAILED STATE ── --}}
        <div style="max-width:480px;margin:0 auto;padding:120px 24px;text-align:center;">
            <div style="display:flex;justify-content:center;margin-bottom:28px;">
                <div style="width:72px;height:72px;border-radius:50%;background:rgba(239,68,68,0.10);border:2px solid rgba(239,68,68,0.28);display:flex;align-items:center;justify-content:center;">
                    <svg style="width:36px;height:36px;color:#f87171;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </div>
            </div>
            <h2 style="font-size:26px;font-weight:900;color:#fff;margin:0 0 10px;">Payment Failed</h2>
            <p style="font-size:14px;color:rgba(255,255,255,0.40);font-family:'Azeret Mono',monospace;line-height:22px;margin:0 0 32px;">
                Your payment was not completed. No charge was made.<br>
                Reference: <strong style="color:rgba(255,255,255,0.60);">#{{ $transactionId }}</strong>
            </p>
            <a href="{{ route('shop') }}" wire:navigate style="background:#DDF247;color:#111;font-weight:800;font-size:13px;padding:13px 26px;border-radius:12px;text-decoration:none;font-family:'Manrope',sans-serif;">
                Return to Shop
            </a>
        </div>

    @elseif ($found && $isWebhookPurchase)

        {{-- ── WEBHOOK PURCHASE SUCCESS (no tokens shown) ── --}}
        <div style="max-width:560px;margin:0 auto;padding:80px 24px;">

            <div style="display:flex;justify-content:center;margin-bottom:28px;">
                <div style="width:72px;height:72px;border-radius:50%;background:rgba(34,197,94,0.12);border:2px solid rgba(34,197,94,0.30);display:flex;align-items:center;justify-content:center;">
                    <svg style="width:36px;height:36px;color:#4ade80;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
            </div>

            @if ($isPartnerPurchase)
                <h1 style="text-align:center;font-size:clamp(24px,5vw,34px);font-weight:900;color:#fff;margin:0 0 10px;line-height:1.15;">Your BobTV Account Has Been Activated!</h1>
                <p style="text-align:center;font-size:14px;color:rgba(255,255,255,0.45);margin:0 0 40px;font-family:'Azeret Mono',monospace;line-height:22px;">
                    Your BobTV subscription has been successfully activated.<br>
                    A confirmation has been sent to <strong style="color:rgba(255,255,255,0.65);">{{ $customerEmail }}</strong>.
                </p>
            @else
                <h1 style="text-align:center;font-size:clamp(24px,5vw,34px);font-weight:900;color:#fff;margin:0 0 10px;line-height:1.15;">Payment Received!</h1>
                <p style="text-align:center;font-size:14px;color:rgba(255,255,255,0.45);margin:0 0 40px;font-family:'Azeret Mono',monospace;line-height:22px;">
                    Your payment has been confirmed.<br>
                    You will be contacted at <strong style="color:rgba(255,255,255,0.65);">{{ $customerEmail }}</strong>.
                </p>
            @endif

            <div style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;">
                <div>
                    <p style="font-size:11px;color:rgba(255,255,255,0.38);margin:0 0 3px;text-transform:uppercase;letter-spacing:2px;font-family:'Azeret Mono',monospace;">Amount paid</p>
                    <p style="font-size:22px;font-weight:900;color:#fff;margin:0;">R{{ number_format($amount, 2) }}</p>
                </div>
                <span style="font-size:11px;color:rgba(255,255,255,0.38);font-family:'Azeret Mono',monospace;">Ref #{{ $transactionId }}</span>
            </div>

            <div style="text-align:center;">
                <a href="{{ route('shop') }}" wire:navigate style="background:#DDF247;color:#111;font-weight:800;font-size:13px;padding:12px 22px;border-radius:12px;text-decoration:none;font-family:'Manrope',sans-serif;">
                    Back to Shop
                </a>
            </div>

        </div>

    @elseif ($found)

        {{-- ── TOKEN PURCHASE SUCCESS ── --}}
        <div style="max-width:640px;margin:0 auto;padding:72px 24px 80px;">

            {{-- Check icon --}}
            <div style="display:flex;justify-content:center;margin-bottom:28px;">
                <div style="width:72px;height:72px;border-radius:50%;background:rgba(34,197,94,0.12);border:2px solid rgba(34,197,94,0.30);display:flex;align-items:center;justify-content:center;">
                    <svg style="width:36px;height:36px;color:#4ade80;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
            </div>

            {{-- Heading --}}
            @if ($isPartnerPurchase)
                <h1 style="text-align:center;font-size:clamp(24px,5vw,34px);font-weight:900;color:#fff;margin:0 0 8px;line-height:1.15;">Your BobTV Account Has Been Activated!</h1>
                <p style="text-align:center;font-size:14px;color:rgba(255,255,255,0.45);margin:0 0 40px;font-family:'Azeret Mono',monospace;">
                    Your BobTV subscription is now active.<br>
                    Confirmation sent to <strong style="color:rgba(255,255,255,0.65);">{{ $customerEmail }}</strong>
                    &nbsp;·&nbsp; Ref <strong style="color:rgba(255,255,255,0.65);">#{{ $transactionId }}</strong>
                </p>
            @else
                <h1 style="text-align:center;font-size:clamp(24px,5vw,34px);font-weight:900;color:#fff;margin:0 0 8px;line-height:1.15;">
                    {{ count($tokens) === 1 ? 'Token Purchased!' : count($tokens).' Tokens Purchased!' }}
                </h1>
                <p style="text-align:center;font-size:14px;color:rgba(255,255,255,0.45);margin:0 0 40px;font-family:'Azeret Mono',monospace;">
                    Sent to <strong style="color:rgba(255,255,255,0.65);">{{ $customerEmail }}</strong>
                    &nbsp;·&nbsp; Ref <strong style="color:rgba(255,255,255,0.65);">#{{ $transactionId }}</strong>
                </p>
            @endif

            {{-- Token cards --}}
            <div style="display:flex;flex-direction:column;gap:16px;margin-bottom:36px;">
                @foreach ($tokens as $token)
                    <div style="background:#1a1a1a;border:1px solid rgba(221,242,71,0.22);border-radius:18px;padding:22px 24px;">
                        <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:3px;color:rgba(255,255,255,0.38);margin:0 0 4px;font-family:'Azeret Mono',monospace;">
                            {{ $token['name'] }}
                        </p>
                        @if (!empty($token['description']))
                            <p style="font-size:12px;color:rgba(255,255,255,0.45);line-height:18px;margin:0 0 12px;">
                                {{ $token['description'] }}
                            </p>
                        @endif
                        <p style="font-family:'Azeret Mono',monospace;font-size:clamp(16px,4vw,22px);font-weight:700;color:#DDF247;letter-spacing:3px;word-break:break-all;margin:0;">
                            {{ $token['code'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- Total + actions --}}
            <div style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;">
                <div>
                    <p style="font-size:11px;color:rgba(255,255,255,0.38);margin:0 0 3px;text-transform:uppercase;letter-spacing:2px;font-family:'Azeret Mono',monospace;">Amount paid</p>
                    <p style="font-size:22px;font-weight:900;color:#fff;margin:0;">R{{ number_format($amount, 2) }}</p>
                </div>
                <a href="{{ route('shop') }}" wire:navigate style="background:#DDF247;color:#111;font-weight:800;font-size:13px;padding:12px 22px;border-radius:12px;text-decoration:none;font-family:'Manrope',sans-serif;white-space:nowrap;">
                    Shop More
                </a>
            </div>

            <p style="text-align:center;font-size:12px;color:rgba(255,255,255,0.22);font-family:'Azeret Mono',monospace;line-height:20px;">
                A confirmation email with your token{{ count($tokens) > 1 ? 's has' : ' has' }} been sent.<br>
                Keep this page bookmarked or note your reference number.
            </p>

        </div>

    @endif
    {{-- If none of the above: render nothing — no hints about whether the transaction exists --}}

</div>
