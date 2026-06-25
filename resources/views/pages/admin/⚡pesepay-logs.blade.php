<?php

use App\Models\PesepayLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('PesePay Logs')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $eventFilter = 'all';

    #[Url]
    public string $successFilter = 'all';

    public ?int $viewingId = null;
    public bool $showPayload = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingEventFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSuccessFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return PesepayLog::query()
            ->when($this->search, fn ($q) => $q
                ->where('reference_number', 'like', "%{$this->search}%")
                ->orWhere('transaction_status', 'like', "%{$this->search}%")
                ->orWhere('error_message', 'like', "%{$this->search}%")
                ->orWhere('transaction_id', 'like', "%{$this->search}%"))
            ->when($this->eventFilter !== 'all', fn ($q) => $q->where('event', $this->eventFilter))
            ->when($this->successFilter === 'success', fn ($q) => $q->where('success', true))
            ->when($this->successFilter === 'failed', fn ($q) => $q->where('success', false))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function viewingLog(): ?PesepayLog
    {
        return $this->viewingId ? PesepayLog::find($this->viewingId) : null;
    }

    #[Computed]
    public function eventCounts(): array
    {
        return PesepayLog::query()
            ->selectRaw('event, count(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event')
            ->toArray();
    }

    public function viewLog(int $id): void
    {
        $this->viewingId = $id;
        $this->showPayload = true;
    }

    public function formatEventLabel(string $event): string
    {
        return match ($event) {
            'make_payment'         => 'Make Payment',
            'check_status'         => 'Check Status',
            'card_payment'         => 'Card Payment',
            'initiate_transaction' => 'Initiate',
            default                => ucwords(str_replace('_', ' ', $event)),
        };
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">PesePay Logs</flux:heading>
            <flux:text class="mt-1 text-zinc-400">Every API response from PesePay — including pending, failed, and successful statuses.</flux:text>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex-1">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="Search by reference, status, error or transaction ID…"
                icon="magnifying-glass"
                clearable
            />
        </div>

        <flux:select wire:model.live="eventFilter" class="w-44">
            <flux:select.option value="all">All events</flux:select.option>
            <flux:select.option value="make_payment">Make Payment</flux:select.option>
            <flux:select.option value="check_status">Check Status</flux:select.option>
            <flux:select.option value="card_payment">Card Payment</flux:select.option>
            <flux:select.option value="initiate_transaction">Initiate</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="successFilter" class="w-36">
            <flux:select.option value="all">All results</flux:select.option>
            <flux:select.option value="success">Success</flux:select.option>
            <flux:select.option value="failed">Failed</flux:select.option>
        </flux:select>
    </div>

    {{-- Table --}}
    <flux:table :paginate="$this->logs" pagination:scroll-to>
        <flux:table.columns>
            <flux:table.column>#</flux:table.column>
            <flux:table.column>Event</flux:table.column>
            <flux:table.column>Tx ID</flux:table.column>
            <flux:table.column>Reference</flux:table.column>
            <flux:table.column>Method</flux:table.column>
            <flux:table.column align="center">HTTP</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>Description</flux:table.column>
            <flux:table.column align="center">Result</flux:table.column>
            <flux:table.column>Time</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->logs as $log)
                <flux:table.row :key="$log->id">
                    <flux:table.cell class="font-mono text-xs text-zinc-500">
                        #{{ $log->id }}
                    </flux:table.cell>

                    <flux:table.cell>
                        @php
                            $eventColor = match ($log->event) {
                                'make_payment'         => 'blue',
                                'check_status'         => 'zinc',
                                'card_payment'         => 'violet',
                                'initiate_transaction' => 'cyan',
                                default                => 'zinc',
                            };
                        @endphp
                        <flux:badge :color="$eventColor" size="sm">
                            {{ $this->formatEventLabel($log->event) }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell class="font-mono text-xs text-zinc-400">
                        {{ $log->transaction_id ?? '—' }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="font-mono text-xs text-zinc-300">
                            {{ $log->reference_number ? Str::limit($log->reference_number, 20) : '—' }}
                        </span>
                    </flux:table.cell>

                    <flux:table.cell class="text-xs text-zinc-400">
                        {{ $log->payment_method ?? '—' }}
                    </flux:table.cell>

                    <flux:table.cell align="center">
                        <span class="font-mono text-xs {{ $log->http_status && $log->http_status < 400 ? 'text-green-400' : 'text-red-400' }}">
                            {{ $log->http_status ?? '—' }}
                        </span>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="text-xs text-zinc-300">{{ $log->transaction_status ?? '—' }}</span>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="text-xs text-zinc-400">
                            {{ $log->status_description ? Str::limit($log->status_description, 40) : ($log->error_message ? Str::limit($log->error_message, 40) : '—') }}
                        </span>
                    </flux:table.cell>

                    <flux:table.cell align="center">
                        @if ($log->success)
                            <flux:badge color="green" size="sm">OK</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Failed</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="text-xs text-zinc-400 whitespace-nowrap">
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
                    <flux:table.cell colspan="11" class="py-12 text-center text-zinc-500">
                        @if ($search || $eventFilter !== 'all' || $successFilter !== 'all')
                            No logs match your filters.
                        @else
                            No PesePay logs yet. Logs will appear here after the first payment attempt.
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- ── Detail Viewer ── --}}
    <flux:modal wire:model="showPayload" flyout position="right" class="md:w-[42rem]">
        @if ($this->viewingLog)
            @php $log = $this->viewingLog; @endphp
            <div class="flex items-center gap-3 mb-1">
                <flux:heading size="lg">Log #{{ $log->id }}</flux:heading>
                @if ($log->success)
                    <flux:badge color="green" size="sm">Success</flux:badge>
                @else
                    <flux:badge color="red" size="sm">Failed</flux:badge>
                @endif
            </div>

            <div class="mt-4 space-y-5">
                {{-- Meta --}}
                <div class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-700 bg-zinc-900 p-4 text-sm">
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Event</p>
                        <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $this->formatEventLabel($log->event) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">HTTP Status</p>
                        <p class="mt-0.5 font-mono text-xs {{ $log->http_status && $log->http_status < 400 ? 'text-green-400' : 'text-red-400' }}">
                            {{ $log->http_status ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Transaction ID</p>
                        <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $log->transaction_id ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Reference Number</p>
                        <p class="mt-0.5 font-mono text-xs text-zinc-200 break-all">{{ $log->reference_number ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Payment Method</p>
                        <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $log->payment_method ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-zinc-500">Recorded</p>
                        <p class="mt-0.5 text-xs text-zinc-200">{{ $log->created_at->format('d M Y H:i:s') }}</p>
                    </div>
                    @if ($log->transaction_status)
                        <div>
                            <p class="text-xs font-semibold text-zinc-500">Transaction Status</p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $log->transaction_status }}</p>
                        </div>
                    @endif
                    @if ($log->status_code)
                        <div>
                            <p class="text-xs font-semibold text-zinc-500">Status Code</p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $log->status_code }}</p>
                        </div>
                    @endif
                    @if ($log->status_description)
                        <div class="col-span-2">
                            <p class="text-xs font-semibold text-zinc-500">Status Description</p>
                            <p class="mt-0.5 text-xs text-zinc-200">{{ $log->status_description }}</p>
                        </div>
                    @endif
                    @if ($log->error_message)
                        <div class="col-span-2">
                            <p class="text-xs font-semibold text-zinc-500">Error</p>
                            <p class="mt-0.5 text-xs text-red-400">{{ $log->error_message }}</p>
                        </div>
                    @endif
                </div>

                {{-- Raw Payload --}}
                @if ($log->raw_payload)
                    <div>
                        <p class="mb-2 text-xs font-semibold text-zinc-400">Raw Payload</p>
                        <div class="max-h-96 overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-950 p-4">
                            <pre class="whitespace-pre-wrap break-all font-mono text-xs text-zinc-300 leading-relaxed">{{ json_encode($log->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
