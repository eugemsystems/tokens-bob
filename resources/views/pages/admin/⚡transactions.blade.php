<?php

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
    <flux:heading size="xl">Transactions</flux:heading>

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
                            R{{ number_format($tx->amount, 2) }}
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
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="py-12 text-center text-zinc-500">
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
</div>
