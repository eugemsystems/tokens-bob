<?php

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Analytics')] class extends Component
{
    #[Computed]
    public function totalRevenue(): float
    {
        return (float) Transaction::where('status', TransactionStatus::Completed)->sum('amount');
    }

    #[Computed]
    public function todayRevenue(): float
    {
        return (float) Transaction::where('status', TransactionStatus::Completed)
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    #[Computed]
    public function thisMonthRevenue(): float
    {
        return (float) Transaction::where('status', TransactionStatus::Completed)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('amount');
    }

    #[Computed]
    public function tokensSold(): int
    {
        return Token::where('status', TokenStatus::Sold)->count();
    }

    #[Computed]
    public function availableInventory(): int
    {
        return Token::where('status', TokenStatus::Available)->count();
    }

    #[Computed]
    public function pendingTransactions(): int
    {
        return Transaction::where('status', TransactionStatus::Pending)->count();
    }

    /** @return array<int, array{label: string, revenue: float}> */
    #[Computed]
    public function monthlyRevenue(): array
    {
        return collect(range(5, 0))->map(function (int $monthsAgo): array {
            $date = now()->subMonths($monthsAgo);

            return [
                'label' => $date->format('M'),
                'revenue' => (float) Transaction::where('status', TransactionStatus::Completed)
                    ->whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->sum('amount'),
            ];
        })->toArray();
    }

    #[Computed]
    public function revenueByService(): Collection
    {
        return DB::table('transactions')
            ->join('tokens', 'tokens.transaction_id', '=', 'transactions.id')
            ->join('categories', 'categories.id', '=', 'tokens.category_id')
            ->where('transactions.status', TransactionStatus::Completed->value)
            ->select(
                'categories.name',
                DB::raw('SUM(transactions.amount) as revenue'),
                DB::raw('COUNT(*) as tokens_sold'),
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->get();
    }

    #[Computed]
    public function recentTransactions(): Collection
    {
        return Transaction::with('token.category')
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function lowInventoryAlerts(): Collection
    {
        return Category::withCount([
            'tokens as available_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Available),
        ])
            ->orderBy('available_tokens_count')
            ->get()
            ->filter(fn ($category) => $category->available_tokens_count < 5)
            ->values();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Analytics</flux:heading>
        <flux:text class="text-zinc-500">{{ now()->format('d M Y') }}</flux:text>
    </div>

    {{-- ── KPI Cards ── --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Total Revenue</flux:text>
            <p class="text-2xl font-bold">R{{ fmt_price($this->totalRevenue) }}</p>
            <flux:text class="text-xs text-zinc-500">All time completed payments</flux:text>
        </flux:card>

        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">This Month</flux:text>
            <p class="text-2xl font-bold">R{{ fmt_price($this->thisMonthRevenue) }}</p>
            <flux:text class="text-xs text-zinc-500">{{ now()->format('F Y') }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Today</flux:text>
            <p class="text-2xl font-bold">R{{ fmt_price($this->todayRevenue) }}</p>
            <flux:text class="text-xs text-zinc-500">{{ now()->format('d M Y') }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Tokens Sold</flux:text>
            <p class="text-2xl font-bold">{{ number_format($this->tokensSold) }}</p>
            <flux:text class="text-xs text-zinc-500">All time</flux:text>
        </flux:card>

        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Available Stock</flux:text>
            <p class="text-2xl font-bold {{ $this->availableInventory < 10 ? 'text-red-500' : '' }}">
                {{ number_format($this->availableInventory) }}
            </p>
            <flux:text class="text-xs text-zinc-500">Tokens ready to sell</flux:text>
        </flux:card>

        <flux:card class="space-y-1 p-5">
            <flux:text class="text-sm text-zinc-500">Pending Transactions</flux:text>
            <p class="text-2xl font-bold {{ $this->pendingTransactions > 0 ? 'text-yellow-500' : '' }}">
                {{ $this->pendingTransactions }}
            </p>
            <flux:text class="text-xs text-zinc-500">Awaiting confirmation</flux:text>
        </flux:card>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">

        {{-- ── Monthly Revenue Chart ── --}}
        <flux:card class="col-span-2 p-5">
            <flux:heading class="mb-4">Revenue — Last 6 Months</flux:heading>

            @php $maxRevenue = collect($this->monthlyRevenue)->max('revenue') ?: 1; @endphp

            <div class="flex h-40 items-end gap-3">
                @foreach ($this->monthlyRevenue as $month)
                    @php $pct = max(4, ($month['revenue'] / $maxRevenue) * 100); @endphp
                    <div class="flex flex-1 flex-col items-center gap-1.5">
                        <span class="text-xs text-zinc-500">
                            @if ($month['revenue'] > 0) R{{ number_format($month['revenue'], 0) }} @endif
                        </span>
                        <div
                            class="w-full rounded-t bg-violet-500 transition-all duration-500"
                            style="height: {{ $pct }}%"
                        ></div>
                        <span class="text-xs font-medium text-zinc-400">{{ $month['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </flux:card>

        {{-- ── Low Inventory Alerts ── --}}
        <flux:card class="p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading>Inventory Alerts</flux:heading>
                @if ($this->lowInventoryAlerts->isEmpty())
                    <flux:badge color="green" size="sm">All good</flux:badge>
                @else
                    <flux:badge color="red" size="sm">{{ $this->lowInventoryAlerts->count() }} low</flux:badge>
                @endif
            </div>

            @if ($this->lowInventoryAlerts->isEmpty())
                <flux:text class="text-sm text-zinc-500">All categories have sufficient stock.</flux:text>
            @else
                <div class="space-y-3">
                    @foreach ($this->lowInventoryAlerts as $category)
                        <div class="flex items-center justify-between">
                            <flux:text class="truncate text-sm">{{ $category->name }}</flux:text>
                            <flux:badge
                                :color="$category->available_tokens_count === 0 ? 'red' : 'yellow'"
                                size="sm"
                            >
                                {{ $category->available_tokens_count }} left
                            </flux:badge>
                        </div>
                        <flux:progress
                            :value="$category->available_tokens_count"
                            max="5"
                            :color="$category->available_tokens_count === 0 ? 'red' : 'yellow'"
                        />
                    @endforeach
                </div>
            @endif

            <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button :href="route('admin.tokens')" variant="ghost" size="sm" class="w-full" wire:navigate>
                    Manage Tokens
                </flux:button>
            </div>
        </flux:card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">

        {{-- ── Revenue by Service ── --}}
        <flux:card class="p-5">
            <flux:heading class="mb-4">Revenue by Service</flux:heading>

            @if ($this->revenueByService->isEmpty())
                <flux:text class="text-sm text-zinc-500">No completed sales yet.</flux:text>
            @else
                @php $topRevenue = $this->revenueByService->first()->revenue ?: 1; @endphp
                <div class="space-y-4">
                    @foreach ($this->revenueByService as $row)
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium">{{ $row->name }}</span>
                                <span class="text-zinc-500">
                                    R{{ fmt_price($row->revenue) }}
                                    <span class="text-xs">({{ $row->tokens_sold }} sold)</span>
                                </span>
                            </div>
                            <flux:progress
                                :value="$row->revenue"
                                :max="$topRevenue"
                                color="violet"
                            />
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>

        {{-- ── Recent Transactions ── --}}
        <flux:card class="p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading>Recent Transactions</flux:heading>
                <flux:button :href="route('admin.transactions')" variant="ghost" size="sm" wire:navigate>
                    View all
                </flux:button>
            </div>

            @if ($this->recentTransactions->isEmpty())
                <flux:text class="text-sm text-zinc-500">No transactions yet.</flux:text>
            @else
                <div class="space-y-3">
                    @foreach ($this->recentTransactions as $tx)
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $tx->customer_email }}</p>
                                <p class="text-xs text-zinc-500">{{ $tx->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="text-sm font-medium">R{{ fmt_price($tx->amount) }}</span>
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
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
    </div>
</div>
