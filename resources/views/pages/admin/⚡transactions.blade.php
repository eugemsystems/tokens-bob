<?php

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Jobs\SendPurchaseEmail;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Transactions')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public string $sortBy = 'created_at';

    #[Url]
    public string $sortDirection = 'desc';

    public ?int $reprocessingId = null;
    public bool $showReprocessModal = false;

    // Export
    public bool $showExportModal = false;
    public string $exportDateFrom = '';
    public string $exportDateTo = '';
    public string $exportStatus = 'all';
    public bool $exportDistinct = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }

        $this->resetPage();
    }

    public function confirmReprocess(int $id): void
    {
        $this->reprocessingId = $id;
        $this->showReprocessModal = true;
    }

    public function reprocess(): void
    {
        $transaction = Transaction::find($this->reprocessingId);

        if (! $transaction || $transaction->status !== TransactionStatus::Pending) {
            $this->showReprocessModal = false;
            $this->reprocessingId = null;

            return;
        }

        DB::transaction(function () use ($transaction): void {
            $transaction->update([
                'status' => TransactionStatus::Completed,
            ]);

            if (! $transaction->is_webhook_purchase) {
                Token::where('transaction_id', $transaction->id)
                    ->where('status', TokenStatus::Reserved)
                    ->update(['status' => TokenStatus::Sold]);
            }
        });

        if (! $transaction->is_webhook_purchase) {
            SendPurchaseEmail::dispatch($transaction->id, 'token');
        }

        $this->showReprocessModal = false;
        $this->reprocessingId = null;

        $this->dispatch('flux:toast', variant: 'success', message: 'Transaction reprocessed — activation triggered.');
    }

    #[Computed]
    public function reprocessingTransaction(): ?Transaction
    {
        return $this->reprocessingId ? Transaction::find($this->reprocessingId) : null;
    }

    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        return Transaction::with('token.category')
            ->when($this->search, fn ($q) => $q->where('customer_email', 'like', "%{$this->search}%")
                ->orWhere('customer_phone', 'like', "%{$this->search}%")
                ->orWhere('pf_payment_id', 'like', "%{$this->search}%")
                ->orWhere('gateway_payment_id', 'like', "%{$this->search}%"))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);
    }

    public function exportCsv(): StreamedResponse
    {
        $query = Transaction::query()
            ->when($this->exportStatus !== 'all', fn ($q) => $q->where('status', $this->exportStatus))
            ->when($this->exportDateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->exportDateFrom))
            ->when($this->exportDateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->exportDateTo))
            ->orderBy('created_at', 'desc');

        if ($this->exportDistinct) {
            $query->select(DB::raw('MIN(id) as id, customer_email, MIN(customer_phone) as customer_phone, SUM(amount) as amount, status, MIN(created_at) as created_at, MIN(gateway) as gateway, MIN(gateway_payment_id) as gateway_payment_id'))
                ->groupBy('customer_email', 'status');
        }

        $rows = $query->get();

        $filename = 'transactions-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Date', 'Email', 'Phone', 'Amount (R)', 'Status', 'Gateway', 'Gateway Ref']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->created_at instanceof \Carbon\Carbon ? $row->created_at->format('Y-m-d H:i:s') : $row->created_at,
                    $row->customer_email,
                    $row->customer_phone ?? '',
                    is_numeric($row->amount) ? number_format((float) $row->amount, 2, '.', '') : $row->amount,
                    $row->status instanceof TransactionStatus ? $row->status->value : $row->status,
                    $row->gateway ?? '',
                    $row->gateway_payment_id ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    #[Computed]
    public function statusCounts(): array
    {
        return Transaction::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Transactions</flux:heading>
        <flux:button wire:click="$set('showExportModal', true)" variant="ghost" icon="arrow-down-tray" size="sm">Export CSV</flux:button>
    </div>

    {{-- ── Summary Badges ── --}}
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ([TransactionStatus::Completed, TransactionStatus::Pending, TransactionStatus::Failed] as $status)
            <flux:button
                wire:click="$set('statusFilter', '{{ $status === TransactionStatus::Completed || $this->statusFilter === $status->value ? ($this->statusFilter === $status->value ? 'all' : $status->value) : $status->value }}')"
                variant="{{ $this->statusFilter === $status->value ? 'filled' : 'ghost' }}"
                size="sm"
            >
                {{ ucfirst($status->value) }}
                <flux:badge
                    size="sm"
                    :color="match($status->value) { 'completed' => 'green', 'failed' => 'red', default => 'yellow' }"
                    class="ml-1"
                >
                    {{ $this->statusCounts[$status->value] ?? 0 }}
                </flux:badge>
            </flux:button>
        @endforeach
    </div>

    {{-- ── Search & Filter Bar ── --}}
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="flex-1">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="Search by email, phone, payment ID or gateway ref…"
                icon="magnifying-glass"
                clearable
            />
        </div>

        <flux:select wire:model.live="statusFilter" class="w-44">
            <flux:select.option value="all">All statuses</flux:select.option>
            <flux:select.option value="completed">Completed</flux:select.option>
            <flux:select.option value="pending">Pending</flux:select.option>
            <flux:select.option value="failed">Failed</flux:select.option>
        </flux:select>
    </div>

    {{-- ── Transactions Table ── --}}
    <div class="mt-4">
        <flux:table :paginate="$this->transactions" pagination:scroll-to>
            <flux:table.columns>
                <flux:table.column
                    sortable
                    :sorted="$sortBy === 'created_at'"
                    :direction="$sortDirection"
                    wire:click="sort('created_at')"
                >
                    Date
                </flux:table.column>

                <flux:table.column>Customer</flux:table.column>
                <flux:table.column>Service</flux:table.column>

                <flux:table.column
                    sortable
                    :sorted="$sortBy === 'amount'"
                    :direction="$sortDirection"
                    wire:click="sort('amount')"
                    align="end"
                >
                    Amount
                </flux:table.column>

                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Payment ID</flux:table.column>
                <flux:table.column>Gateway Ref</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->transactions as $tx)
                    <flux:table.row :key="$tx->id">
                        <flux:table.cell class="whitespace-nowrap text-zinc-500">
                            {{ $tx->created_at->format('d M Y H:i') }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <div>
                                <p class="font-medium">{{ $tx->customer_email }}</p>
                                <p class="text-xs text-zinc-500">{{ $tx->customer_phone }}</p>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-500">
                            {{ $tx->token?->category?->name ?? '—' }}
                        </flux:table.cell>

                        <flux:table.cell align="end" variant="strong">
                            R{{ fmt_price($tx->amount) }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge
                                size="sm"
                                :color="match($tx->status->value) {
                                    'completed' => 'green',
                                    'failed'    => 'red',
                                    default     => 'yellow',
                                }"
                            >
                                {{ $tx->status->value }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="font-mono text-xs text-zinc-500">
                                {{ $tx->pf_payment_id ? Str::limit($tx->pf_payment_id, 16) : '—' }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="font-mono text-xs text-zinc-500">
                                {{ $tx->gateway_payment_id ?? '—' }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            @if ($tx->status === TransactionStatus::Pending)
                                <flux:button
                                    wire:click="confirmReprocess({{ $tx->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-path"
                                    tooltip="Reprocess — mark as completed and trigger activation"
                                />
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="py-12 text-center text-zinc-500">
                            @if ($search || $statusFilter !== 'all')
                                No transactions match your filters.
                            @else
                                No transactions yet.
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- ── Reprocess Confirmation Modal ── --}}
    <flux:modal wire:model="showReprocessModal" class="max-w-md">
        @if ($this->reprocessingTransaction)
            @php $tx = $this->reprocessingTransaction; @endphp

            <flux:heading size="lg">Reprocess Transaction</flux:heading>
            <flux:text class="mt-2 text-zinc-400">
                This will mark the transaction as <strong class="text-white">Completed</strong> and
                @if ($tx->is_webhook_purchase)
                    fire the partner webhook to trigger account activation.
                @else
                    mark the tokens as sold and send the purchase email.
                @endif
            </flux:text>

            <div class="mt-4 rounded-lg border border-zinc-700 bg-zinc-900 p-4 text-sm space-y-2">
                <div class="flex justify-between">
                    <span class="text-zinc-500">Customer</span>
                    <span class="text-zinc-200">{{ $tx->customer_email }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-500">Amount</span>
                    <span class="font-semibold text-white">R{{ fmt_price($tx->amount) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-500">Type</span>
                    <span class="text-zinc-200">{{ $tx->is_webhook_purchase ? 'Subscription / Webhook' : 'Token Purchase' }}</span>
                </div>
                @if ($tx->gateway_payment_id)
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Gateway Ref</span>
                        <span class="font-mono text-xs text-zinc-300">{{ $tx->gateway_payment_id }}</span>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button wire:click="$set('showReprocessModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="reprocess" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="reprocess">Confirm & Reprocess</span>
                    <span wire:loading wire:target="reprocess">Processing…</span>
                </flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- ── Export Modal ── --}}
    <flux:modal wire:model="showExportModal" class="max-w-md">
        <flux:heading size="lg">Export Transactions</flux:heading>
        <flux:text class="mt-1 text-zinc-400">Download a CSV filtered by date range and status.</flux:text>

        <div class="mt-5 space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>From date</flux:label>
                    <flux:input type="date" wire:model="exportDateFrom" />
                </flux:field>
                <flux:field>
                    <flux:label>To date</flux:label>
                    <flux:input type="date" wire:model="exportDateTo" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Status</flux:label>
                <flux:select wire:model="exportStatus">
                    <flux:select.option value="all">All statuses</flux:select.option>
                    <flux:select.option value="completed">Completed</flux:select.option>
                    <flux:select.option value="pending">Pending</flux:select.option>
                    <flux:select.option value="failed">Failed</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:field variant="inline">
                <flux:checkbox wire:model="exportDistinct" id="exportDistinct" />
                <flux:label for="exportDistinct">Distinct emails only (one row per unique email)</flux:label>
            </flux:field>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <flux:button wire:click="$set('showExportModal', false)" variant="ghost">Cancel</flux:button>
            <flux:button wire:click="exportCsv" variant="primary" icon="arrow-down-tray">
                Download CSV
            </flux:button>
        </div>
    </flux:modal>
</div>
