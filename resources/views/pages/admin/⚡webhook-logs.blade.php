<?php

use App\Models\WebhookLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Webhook Logs')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $sourceFilter = 'all';

    #[Url]
    public string $statusFilter = 'all';

    public ?int $viewingId = null;
    public bool $showPayload = false;

    public function updatingSourceFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return WebhookLog::query()
            ->when($this->sourceFilter !== 'all', fn ($q) => $q->where('source', $this->sourceFilter))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function viewingLog(): ?WebhookLog
    {
        return $this->viewingId ? WebhookLog::find($this->viewingId) : null;
    }

    #[Computed]
    public function sources(): array
    {
        return WebhookLog::query()
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source')
            ->toArray();
    }

    public function viewLog(int $id): void
    {
        $this->viewingId = $id;
        $this->showPayload = true;
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Webhook Logs</flux:heading>
            <flux:text class="mt-1 text-zinc-400">All incoming webhook requests from external services.</flux:text>
        </div>

        {{-- Endpoint reference --}}
        <div class="hidden flex-col items-end gap-1 lg:flex">
            <p class="text-xs font-semibold text-zinc-500">Webhook endpoints</p>
            <code class="rounded bg-zinc-800 px-2 py-0.5 text-xs text-zinc-300">/whop/webhook</code>
            <code class="rounded bg-zinc-800 px-2 py-0.5 text-xs text-zinc-300">/stripe/webhook</code>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        {{-- Source filter --}}
        <div class="flex items-center gap-1.5">
            <span class="text-xs font-semibold text-zinc-500">Source:</span>
            <flux:button
                wire:click="$set('sourceFilter', 'all')"
                :variant="$sourceFilter === 'all' ? 'primary' : 'ghost'"
                size="sm"
            >All</flux:button>
            <flux:button
                wire:click="$set('sourceFilter', 'whop')"
                :variant="$sourceFilter === 'whop' ? 'primary' : 'ghost'"
                size="sm"
            >Whop</flux:button>
            <flux:button
                wire:click="$set('sourceFilter', 'stripe')"
                :variant="$sourceFilter === 'stripe' ? 'primary' : 'ghost'"
                size="sm"
            >Stripe</flux:button>
        </div>

        <div class="h-4 w-px bg-zinc-700"></div>

        {{-- Status filter --}}
        <div class="flex items-center gap-1.5">
            <span class="text-xs font-semibold text-zinc-500">Status:</span>
            <flux:button
                wire:click="$set('statusFilter', 'all')"
                :variant="$statusFilter === 'all' ? 'primary' : 'ghost'"
                size="sm"
            >All</flux:button>
            <flux:button
                wire:click="$set('statusFilter', 'processed')"
                :variant="$statusFilter === 'processed' ? 'primary' : 'ghost'"
                size="sm"
            >Processed</flux:button>
            <flux:button
                wire:click="$set('statusFilter', 'received')"
                :variant="$statusFilter === 'received' ? 'primary' : 'ghost'"
                size="sm"
            >Received</flux:button>
            <flux:button
                wire:click="$set('statusFilter', 'failed')"
                :variant="$statusFilter === 'failed' ? 'primary' : 'ghost'"
                size="sm"
            >Failed</flux:button>
        </div>
    </div>

    {{-- Table --}}
    <flux:table :paginate="$this->logs" pagination:scroll-to>
        <flux:table.columns>
            <flux:table.column>#</flux:table.column>
            <flux:table.column>Source</flux:table.column>
            <flux:table.column>Event</flux:table.column>
            <flux:table.column align="center">Status</flux:table.column>
            <flux:table.column align="center">Code</flux:table.column>
            <flux:table.column>IP Address</flux:table.column>
            <flux:table.column>Received</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->logs as $log)
                <flux:table.row :key="$log->id">
                    <flux:table.cell class="text-zinc-500 font-mono text-xs">
                        #{{ $log->id }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge
                            :color="$log->source === 'stripe' ? 'violet' : 'blue'"
                            size="sm"
                        >
                            {{ ucfirst($log->source) }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="font-mono text-xs text-zinc-300">
                            {{ $log->event_type ?? '—' }}
                        </span>
                    </flux:table.cell>

                    <flux:table.cell align="center">
                        @php
                            $statusColor = match ($log->status) {
                                'processed' => 'green',
                                'failed'    => 'red',
                                default     => 'zinc',
                            };
                        @endphp
                        <flux:badge :color="$statusColor" size="sm">
                            {{ ucfirst($log->status) }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell align="center">
                        <span class="font-mono text-xs {{ ($log->response_code >= 400 || $log->response_code === null) ? 'text-red-400' : 'text-green-400' }}">
                            {{ $log->response_code ?? '—' }}
                        </span>
                    </flux:table.cell>

                    <flux:table.cell class="font-mono text-xs text-zinc-400">
                        {{ $log->ip_address ?? '—' }}
                    </flux:table.cell>

                    <flux:table.cell class="text-xs text-zinc-400">
                        {{ $log->created_at->diffForHumans() }}
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        <flux:button
                            wire:click="viewLog({{ $log->id }})"
                            variant="ghost"
                            size="sm"
                            icon="eye"
                        />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="py-12 text-center text-zinc-500">
                        No webhook logs yet. Logs appear here as soon as a request hits an endpoint.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- ── Payload Viewer ── --}}
    <flux:modal wire:model="showPayload" flyout position="right" class="md:w-[38rem]">
        @if ($this->viewingLog)
            @php $log = $this->viewingLog; @endphp
            <div class="flex items-center gap-3 mb-1">
                <flux:heading size="lg">Webhook #{{ $log->id }}</flux:heading>
                <flux:badge :color="$log->source === 'stripe' ? 'violet' : 'blue'" size="sm">
                    {{ ucfirst($log->source) }}
                </flux:badge>
                <flux:badge
                    :color="match($log->status) { 'processed' => 'green', 'failed' => 'red', default => 'zinc' }"
                    size="sm"
                >
                    {{ ucfirst($log->status) }}
                </flux:badge>
            </div>

            <div class="mt-4 space-y-5">
                {{-- Meta --}}
                <div class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-700 bg-zinc-900 p-4 text-sm">
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Event</p>
                        <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $log->event_type ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Response Code</p>
                        <p class="mt-0.5 font-mono text-xs {{ ($log->response_code >= 400 || $log->response_code === null) ? 'text-red-400' : 'text-green-400' }}">
                            {{ $log->response_code ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">IP Address</p>
                        <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $log->ip_address ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Received</p>
                        <p class="mt-0.5 text-xs text-zinc-200">{{ $log->created_at->format('d M Y H:i:s') }}</p>
                    </div>
                </div>

                {{-- Payload --}}
                <div>
                    <p class="mb-2 text-xs font-semibold text-zinc-400">Payload</p>
                    <div class="max-h-96 overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-950 p-4">
                        <pre class="whitespace-pre-wrap break-all font-mono text-xs text-zinc-300 leading-relaxed">{{ json_encode($log->decodedPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>

                {{-- Headers --}}
                <div>
                    <p class="mb-2 text-xs font-semibold text-zinc-400">Headers</p>
                    <div class="max-h-48 overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-950 p-4">
                        <pre class="whitespace-pre-wrap break-all font-mono text-xs text-zinc-500 leading-relaxed">{{ json_encode($log->decodedHeaders(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
