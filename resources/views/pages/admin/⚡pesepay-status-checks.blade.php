<?php

use App\Models\PesepayStatusCheck;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('PesePay Status Checks')] class extends Component
{
    use WithPagination;

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'updated')]
    public string $updatedFilter = '';

    public function updatedBatchFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUpdatedFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function todayChecked(): int
    {
        return PesepayStatusCheck::whereDate('checked_at', today())->count();
    }

    #[Computed]
    public function todayUpdated(): int
    {
        return PesepayStatusCheck::whereDate('checked_at', today())->where('was_updated', true)->count();
    }

    #[Computed]
    public function totalBatches(): int
    {
        return PesepayStatusCheck::whereDate('checked_at', today())->distinct('batch_id')->count('batch_id');
    }

    #[Computed]
    public function checks()
    {
        return PesepayStatusCheck::with('transaction')
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', 'like', $this->batchFilter.'%'))
            ->when($this->updatedFilter !== '', fn ($q) => $q->where('was_updated', (bool) $this->updatedFilter))
            ->latest('checked_at')
            ->paginate(25);
    }

    #[Computed]
    public function recentBatches(): Collection
    {
        return PesepayStatusCheck::select('batch_id', \Illuminate\Support\Facades\DB::raw('MIN(checked_at) as ran_at'), \Illuminate\Support\Facades\DB::raw('COUNT(*) as total'), \Illuminate\Support\Facades\DB::raw('SUM(was_updated) as updated_count'))
            ->groupBy('batch_id')
            ->orderByDesc('ran_at')
            ->limit(10)
            ->get();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">PesePay Status Checks</flux:heading>
            <flux:text class="text-zinc-500">Scheduler runs every minute — checks pending PesePay transactions from the last hour.</flux:text>
        </div>
        <flux:button wire:click="$refresh" icon="arrow-path" variant="ghost" size="sm">Refresh</flux:button>
    </div>

    {{-- ── Today Summary Cards ── --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Batches Today</flux:text>
            <p class="text-2xl font-bold">{{ $this->totalBatches }}</p>
            <flux:text class="text-xs text-zinc-500">Scheduler runs so far today</flux:text>
        </flux:card>
        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Transactions Checked</flux:text>
            <p class="text-2xl font-bold">{{ number_format($this->todayChecked) }}</p>
            <flux:text class="text-xs text-zinc-500">Today</flux:text>
        </flux:card>
        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Completed via Check</flux:text>
            <p class="text-2xl font-bold {{ $this->todayUpdated > 0 ? 'text-green-600' : '' }}">{{ number_format($this->todayUpdated) }}</p>
            <flux:text class="text-xs text-zinc-500">Transactions auto-completed today</flux:text>
        </flux:card>
    </div>

    {{-- ── Recent Batch Runs ── --}}
    <flux:card class="p-5">
        <flux:heading class="mb-4">Recent Batch Runs</flux:heading>
        @if ($this->recentBatches->isEmpty())
            <flux:text class="text-sm text-zinc-500">No batches recorded yet. The scheduler will populate this once it runs.</flux:text>
        @else
            <div class="space-y-2">
                @foreach ($this->recentBatches as $batch)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-100 p-3 dark:border-zinc-700">
                        <div class="min-w-0">
                            <p class="font-mono text-xs text-zinc-500 truncate">{{ $batch->batch_id }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ \Carbon\Carbon::parse($batch->ran_at)->format('d M Y H:i:s') }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <flux:badge size="sm" color="zinc">{{ $batch->total }} checked</flux:badge>
                            @if ($batch->updated_count > 0)
                                <flux:badge size="sm" color="green">{{ $batch->updated_count }} completed</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">0 updated</flux:badge>
                            @endif
                            <flux:button
                                wire:click="$set('batchFilter', '{{ substr($batch->batch_id, 0, 8) }}')"
                                variant="ghost"
                                size="xs"
                            >
                                Filter
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- ── Check Records Table ── --}}
    <flux:card class="p-5">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <flux:heading>Check Records</flux:heading>
            <div class="flex flex-1 items-center gap-2">
                <flux:input
                    wire:model.live.debounce="batchFilter"
                    placeholder="Filter by batch ID prefix…"
                    size="sm"
                    class="max-w-xs"
                />
                <flux:select wire:model.live="updatedFilter" size="sm" class="max-w-[160px]">
                    <flux:select.option value="">All checks</flux:select.option>
                    <flux:select.option value="1">Updated only</flux:select.option>
                    <flux:select.option value="0">Not updated</flux:select.option>
                </flux:select>
                @if ($batchFilter || $updatedFilter !== '')
                    <flux:button wire:click="$set('batchFilter', ''); $set('updatedFilter', '')" variant="ghost" size="sm">
                        Clear
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($this->checks->isEmpty())
            <flux:text class="py-8 text-center text-sm text-zinc-500">No records match the current filters.</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-xs text-zinc-500 dark:border-zinc-700">
                            <th class="pb-2 pr-4 font-medium">Batch</th>
                            <th class="pb-2 pr-4 font-medium">Transaction</th>
                            <th class="pb-2 pr-4 font-medium">Reference</th>
                            <th class="pb-2 pr-4 font-medium">Before</th>
                            <th class="pb-2 pr-4 font-medium">PesePay Returned</th>
                            <th class="pb-2 pr-4 font-medium">After</th>
                            <th class="pb-2 pr-4 font-medium">Updated</th>
                            <th class="pb-2 font-medium">Checked At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->checks as $check)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="py-2.5 pr-4">
                                    <span class="font-mono text-xs text-zinc-400">{{ substr($check->batch_id, 0, 8) }}…</span>
                                </td>
                                <td class="py-2.5 pr-4">
                                    @if ($check->transaction_id)
                                        <a
                                            href="{{ route('admin.transactions') }}?search={{ $check->reference_number ?? $check->transaction_id }}"
                                            class="font-medium text-violet-600 hover:underline"
                                            wire:navigate
                                        >#{{ $check->transaction_id }}</a>
                                        @if ($check->transaction)
                                            <p class="truncate text-xs text-zinc-400 max-w-[160px]">{{ $check->transaction->customer_email }}</p>
                                        @endif
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4">
                                    <span class="font-mono text-xs">{{ $check->reference_number ?? '—' }}</span>
                                </td>
                                <td class="py-2.5 pr-4">
                                    <flux:badge size="sm" color="yellow">{{ $check->status_before ?? '—' }}</flux:badge>
                                </td>
                                <td class="py-2.5 pr-4">
                                    @if ($check->error_message)
                                        <flux:badge size="sm" color="red">Error</flux:badge>
                                        <p class="mt-0.5 text-xs text-red-500">{{ Str::limit($check->error_message, 40) }}</p>
                                    @elseif ($check->status_returned)
                                        <flux:badge
                                            size="sm"
                                            :color="in_array($check->status_returned, ['SUCCESS', 'PROCESSED']) ? 'green' : 'zinc'"
                                        >
                                            {{ $check->status_returned }}
                                        </flux:badge>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4">
                                    @if ($check->status_after)
                                        <flux:badge size="sm" color="green">{{ $check->status_after }}</flux:badge>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4">
                                    @if ($check->was_updated)
                                        <flux:badge size="sm" color="green">Yes</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">No</flux:badge>
                                    @endif
                                </td>
                                <td class="py-2.5 text-xs text-zinc-500">
                                    {{ $check->checked_at->format('d M H:i:s') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->checks->links() }}
            </div>
        @endif
    </flux:card>
</div>
