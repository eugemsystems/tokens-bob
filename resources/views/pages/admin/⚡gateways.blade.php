<?php

use App\Models\Setting;
use App\Services\GatewayManager;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Payment Gateways')] class extends Component
{
    public string $defaultGateway = '';

    #[Validate('nullable|url|max:500')]
    public string $webhookUrl = '';

    #[Validate('nullable|email|max:254')]
    public string $whopPrefillEmail = '';

    #[Validate('nullable|string|max:20000')]
    public string $whopEmailPool = '';

    public function mount(): void
    {
        $this->defaultGateway = Setting::get('default_gateway', 'payfast');
        $this->webhookUrl = Setting::get('webhook_url', '');
        $this->whopPrefillEmail = Setting::get('whop_prefill_email', '');
        $this->whopEmailPool = Setting::get('whop_email_pool', '');
    }

    #[Computed]
    public function gateways(): array
    {
        return collect(app(GatewayManager::class)->all())
            ->map(fn ($gateway) => [
                'key'           => $gateway->getKey(),
                'name'          => $gateway->getName(),
                'checkout_type' => $gateway->getCheckoutType(),
            ])
            ->values()
            ->toArray();
    }

    public function setDefault(string $key): void
    {
        $validKeys = array_keys(app(GatewayManager::class)->all());

        if (! in_array($key, $validKeys, true)) {
            return;
        }

        Setting::set('default_gateway', $key);
        $this->defaultGateway = $key;

        $this->dispatch('gateway-updated');
    }

    public function saveWebhookUrl(): void
    {
        $this->validateOnly('webhookUrl');
        Setting::set('webhook_url', $this->webhookUrl);
        Flux::toast(variant: 'success', text: 'Webhook URL saved.');
    }

    public function saveWhopSettings(): void
    {
        $this->validateOnly('whopPrefillEmail');
        $this->validateOnly('whopEmailPool');
        Setting::set('whop_prefill_email', $this->whopPrefillEmail);
        Setting::set('whop_email_pool', $this->whopEmailPool);
        Flux::toast(variant: 'success', text: 'Whop settings saved.');
    }

    public function poolEmailCount(): int
    {
        return count(array_filter(array_map('trim', explode("\n", $this->whopEmailPool))));
    }
}; ?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl">Payment Gateways</flux:heading>
        <flux:text class="mt-1 text-zinc-400">Select the active payment gateway customers will use at checkout.</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->gateways as $gateway)
            @php $isActive = $gateway['key'] === $defaultGateway; @endphp
            <flux:card
                wire:click="setDefault('{{ $gateway['key'] }}')"
                wire:loading.attr="disabled"
                class="cursor-pointer border-2 p-5 transition-all duration-150 {{ $isActive ? 'border-violet-500 bg-violet-500/5' : 'border-zinc-700 bg-zinc-900 hover:border-zinc-500' }}"
            >
                <div class="flex items-start justify-between">
                    <div class="space-y-1">
                        <flux:heading class="{{ $isActive ? 'text-violet-300' : 'text-zinc-100' }}">
                            {{ $gateway['name'] }}
                        </flux:heading>
                        <flux:badge size="sm" color="zinc">
                            {{ ucfirst($gateway['checkout_type']) }}
                        </flux:badge>
                    </div>

                    @if ($isActive)
                        <div class="flex size-6 items-center justify-center rounded-full bg-violet-500">
                            <flux:icon.check class="size-4 text-white" />
                        </div>
                    @else
                        <div class="size-6 rounded-full border-2 border-zinc-600"></div>
                    @endif
                </div>

                <flux:text class="mt-3 text-xs text-zinc-500">
                    @if ($gateway['checkout_type'] === 'onsite')
                        Embedded payment overlay — customers stay on your site.
                    @elseif ($gateway['checkout_type'] === 'qr')
                        QR code scan — customers pay via their banking app.
                    @elseif ($gateway['checkout_type'] === 'redirect')
                        Hosted checkout — customers are redirected to a secure payment page.
                    @elseif ($gateway['checkout_type'] === 'whop')
                        Hosted Whop checkout — customers complete payment on whop.com.
                    @endif
                </flux:text>
            </flux:card>
        @endforeach
    </div>

    <flux:separator />

    {{-- Webhook URL --}}
    <div>
        <flux:heading size="lg">Webhook URL</flux:heading>
        <flux:text class="mt-1 text-zinc-400">
            After a successful Whop payment for a <strong>Webhook</strong>-type category, the customer's email address is
            posted here as <code class="rounded bg-zinc-800 px-1 text-xs">{"email": "..."}</code>.
        </flux:text>

        <div class="mt-4 flex items-end gap-3">
            <div class="flex-1">
                <flux:input
                    wire:model="webhookUrl"
                    label="Webhook URL"
                    type="url"
                    placeholder="https://your-system.com/webhook/token-purchased"
                />
            </div>
            <flux:button wire:click="saveWebhookUrl" variant="primary">Save</flux:button>
        </div>

        @if ($webhookUrl)
            <div class="mt-3 flex items-center gap-2 text-xs text-green-400">
                <flux:icon.check-circle class="size-4" />
                Active — <span class="font-mono text-zinc-400">{{ $webhookUrl }}</span>
            </div>
        @endif
    </div>

    <flux:separator />

    {{-- Whop Settings --}}
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Whop Settings</flux:heading>
            <flux:text class="mt-1 text-zinc-400">
                Configure the email pre-filled into the Whop checkout embed. The system randomly picks from the pool on each checkout; falls back to the default if the pool is empty.
            </flux:text>
        </div>

        {{-- Default fallback email --}}
        <div>
            <flux:input
                wire:model="whopPrefillEmail"
                label="Default Prefill Email"
                type="email"
                placeholder="your-whop-account@example.com"
                description="Used when the email pool below is empty."
            />
            @if ($whopPrefillEmail)
                <div class="mt-2 flex items-center gap-2 text-xs text-green-400">
                    <flux:icon.check-circle class="size-4" />
                    Default — <span class="font-mono text-zinc-400">{{ $whopPrefillEmail }}</span>
                </div>
            @endif
        </div>

        {{-- Email pool --}}
        <div>
            <flux:textarea
                wire:model="whopEmailPool"
                label="Email Pool"
                placeholder="account1@example.com&#10;account2@example.com&#10;account3@example.com"
                rows="6"
                description="One email per line. A random one is picked each checkout. Leave empty to always use the default above."
            />
            @if ($this->poolEmailCount() > 0)
                <div class="mt-2 flex items-center gap-2 text-xs text-blue-400">
                    <flux:icon.inbox-stack class="size-4" />
                    {{ $this->poolEmailCount() }} email{{ $this->poolEmailCount() === 1 ? '' : 's' }} in pool — random selection active
                </div>
            @endif
        </div>

        <flux:button wire:click="saveWhopSettings" variant="primary">Save Whop Settings</flux:button>
    </div>

    <flux:separator />

    <div class="flex items-center gap-3 rounded-lg border border-blue-800 bg-blue-950/40 px-4 py-3 text-sm text-blue-300">
        <flux:icon.information-circle class="size-5 shrink-0" />
        <span>
            Changes take effect immediately. Make sure your selected gateway is configured in the
            <code class="rounded bg-blue-900/60 px-1">.env</code> file before activating it.
        </span>
    </div>
</div>
