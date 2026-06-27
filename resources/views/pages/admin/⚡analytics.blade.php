<?php

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transaction Analytics')] class extends Component
{
    public string $activeTab = 'transactions';

    /** @return array<int, array{label: string, short: string, count: int, revenue: float}> */
    #[Computed]
    public function dailyStats(): array
    {
        $raw = Transaction::where('status', TransactionStatus::Completed)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count, SUM(amount) as revenue')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        return collect(range(29, 0))->map(function (int $daysAgo) use ($raw): array {
            $date = now()->subDays($daysAgo);
            $row = $raw->get($date->format('Y-m-d'));

            return [
                'label' => $date->format('d M'),
                'short' => $date->format('d'),
                'count' => (int) ($row?->count ?? 0),
                'revenue' => (float) ($row?->revenue ?? 0),
            ];
        })->toArray();
    }

    /** @return array<int, array{label: string, count: int, revenue: float}> */
    #[Computed]
    public function weeklyStats(): array
    {
        $raw = Transaction::where('status', TransactionStatus::Completed)
            ->where('created_at', '>=', now()->subWeeks(11)->startOfWeek())
            ->get(['created_at', 'amount']);

        return collect(range(11, 0))->map(function (int $weeksAgo) use ($raw): array {
            $start = now()->subWeeks($weeksAgo)->startOfWeek();
            $end = now()->subWeeks($weeksAgo)->endOfWeek();

            $weekTx = $raw->filter(fn ($tx) => $tx->created_at->between($start, $end));

            return [
                'label' => $start->format('d M'),
                'count' => $weekTx->count(),
                'revenue' => (float) $weekTx->sum('amount'),
            ];
        })->toArray();
    }

    /** @return array<int, array{label: string, short: string, count: int, revenue: float}> */
    #[Computed]
    public function monthlyStats(): array
    {
        $raw = Transaction::where('status', TransactionStatus::Completed)
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as count, SUM(amount) as revenue')
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(fn ($r) => $r->year.'-'.$r->month);

        return collect(range(11, 0))->map(function (int $monthsAgo) use ($raw): array {
            $date = now()->subMonths($monthsAgo);
            $row = $raw->get($date->year.'-'.$date->month);

            return [
                'label' => $date->format('M Y'),
                'short' => $date->format('M'),
                'count' => (int) ($row?->count ?? 0),
                'revenue' => (float) ($row?->revenue ?? 0),
            ];
        })->toArray();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Transaction Analytics</flux:heading>
        <flux:text class="text-zinc-500">{{ now()->format('d M Y') }}</flux:text>
    </div>

    {{-- ── Tab Bar ── --}}
    <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <button
            wire:click="$set('activeTab', 'transactions')"
            class="px-4 py-2 text-sm font-medium transition-colors {{ $activeTab === 'transactions' ? 'border-b-2 border-violet-500 text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
        >
            Transactions
        </button>
        <button
            wire:click="$set('activeTab', 'revenue')"
            class="px-4 py-2 text-sm font-medium transition-colors {{ $activeTab === 'revenue' ? 'border-b-2 border-violet-500 text-violet-600 dark:text-violet-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
        >
            Revenue
        </button>
    </div>

    {{-- ── Transactions Tab ── --}}
    @if ($activeTab === 'transactions')
        <div class="flex flex-col gap-6">

            {{-- Daily --}}
            <flux:card class="p-5">
                <flux:heading class="mb-1">Daily Transactions</flux:heading>
                <flux:text class="mb-4 text-xs text-zinc-500">Last 30 days — {{ collect($this->dailyStats)->sum('count') }} total</flux:text>

                @php $maxCount = collect($this->dailyStats)->max('count') ?: 1; @endphp

                <div class="overflow-x-auto">
                    <div class="flex h-36 items-end gap-1" style="min-width: 560px">
                        @foreach ($this->dailyStats as $i => $day)
                            @php $pct = max(3, ($day['count'] / $maxCount) * 100); @endphp
                            <div
                                class="group relative flex flex-1 flex-col items-center gap-1"
                                title="{{ $day['label'] }}: {{ $day['count'] }} transaction(s)"
                            >
                                <span class="text-[10px] text-zinc-500">
                                    @if ($day['count'] > 0) {{ $day['count'] }} @else &nbsp; @endif
                                </span>
                                <div
                                    class="w-full rounded-t bg-violet-500 transition-all duration-300 group-hover:bg-violet-400"
                                    style="height: {{ $pct }}%"
                                ></div>
                                <span class="text-[10px] text-zinc-400">
                                    @if ($i % 7 === 0) {{ $day['short'] }} @else &nbsp; @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </flux:card>

            {{-- Weekly --}}
            <flux:card class="p-5">
                <flux:heading class="mb-1">Weekly Transactions</flux:heading>
                <flux:text class="mb-4 text-xs text-zinc-500">Last 12 weeks — {{ collect($this->weeklyStats)->sum('count') }} total</flux:text>

                @php $maxCount = collect($this->weeklyStats)->max('count') ?: 1; @endphp

                <div class="flex h-40 items-end gap-3">
                    @foreach ($this->weeklyStats as $week)
                        @php $pct = max(3, ($week['count'] / $maxCount) * 100); @endphp
                        <div class="group flex flex-1 flex-col items-center gap-1.5">
                            <span class="text-xs text-zinc-500">
                                @if ($week['count'] > 0) {{ $week['count'] }} @else &nbsp; @endif
                            </span>
                            <div
                                class="w-full rounded-t bg-indigo-500 transition-all duration-300 group-hover:bg-indigo-400"
                                style="height: {{ $pct }}%"
                            ></div>
                            <span class="text-xs text-zinc-400">{{ $week['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </flux:card>

            {{-- Monthly --}}
            <flux:card class="p-5">
                <flux:heading class="mb-1">Monthly Transactions</flux:heading>
                <flux:text class="mb-4 text-xs text-zinc-500">Last 12 months — {{ collect($this->monthlyStats)->sum('count') }} total</flux:text>

                @php $maxCount = collect($this->monthlyStats)->max('count') ?: 1; @endphp

                <div class="flex h-40 items-end gap-3">
                    @foreach ($this->monthlyStats as $month)
                        @php $pct = max(3, ($month['count'] / $maxCount) * 100); @endphp
                        <div class="group flex flex-1 flex-col items-center gap-1.5">
                            <span class="text-xs text-zinc-500">
                                @if ($month['count'] > 0) {{ $month['count'] }} @else &nbsp; @endif
                            </span>
                            <div
                                class="w-full rounded-t bg-blue-500 transition-all duration-300 group-hover:bg-blue-400"
                                style="height: {{ $pct }}%"
                            ></div>
                            <span class="text-xs text-zinc-400">{{ $month['short'] }}</span>
                        </div>
                    @endforeach
                </div>
            </flux:card>

        </div>
    @endif

    {{-- ── Revenue Tab ── --}}
    @if ($activeTab === 'revenue')
        <div class="flex flex-col gap-6">

            {{-- Daily Revenue --}}
            <flux:card class="p-5">
                <flux:heading class="mb-1">Daily Revenue</flux:heading>
                <flux:text class="mb-4 text-xs text-zinc-500">
                    Last 30 days — R{{ fmt_price(collect($this->dailyStats)->sum('revenue')) }} total
                </flux:text>

                @php $maxRevenue = collect($this->dailyStats)->max('revenue') ?: 1; @endphp

                <div class="overflow-x-auto">
                    <div class="flex h-36 items-end gap-1" style="min-width: 560px">
                        @foreach ($this->dailyStats as $i => $day)
                            @php $pct = max(3, ($day['revenue'] / $maxRevenue) * 100); @endphp
                            <div
                                class="group relative flex flex-1 flex-col items-center gap-1"
                                title="{{ $day['label'] }}: R{{ fmt_price($day['revenue']) }}"
                            >
                                <span class="text-[10px] text-zinc-500">
                                    @if ($day['revenue'] > 0) R{{ number_format($day['revenue'], 0) }} @else &nbsp; @endif
                                </span>
                                <div
                                    class="w-full rounded-t bg-emerald-500 transition-all duration-300 group-hover:bg-emerald-400"
                                    style="height: {{ $pct }}%"
                                ></div>
                                <span class="text-[10px] text-zinc-400">
                                    @if ($i % 7 === 0) {{ $day['short'] }} @else &nbsp; @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </flux:card>

            {{-- Weekly Revenue --}}
            <flux:card class="p-5">
                <flux:heading class="mb-1">Weekly Revenue</flux:heading>
                <flux:text class="mb-4 text-xs text-zinc-500">
                    Last 12 weeks — R{{ fmt_price(collect($this->weeklyStats)->sum('revenue')) }} total
                </flux:text>

                @php $maxRevenue = collect($this->weeklyStats)->max('revenue') ?: 1; @endphp

                <div class="flex h-40 items-end gap-3">
                    @foreach ($this->weeklyStats as $week)
                        @php $pct = max(3, ($week['revenue'] / $maxRevenue) * 100); @endphp
                        <div class="group flex flex-1 flex-col items-center gap-1.5">
                            <span class="text-xs text-zinc-500">
                                @if ($week['revenue'] > 0) R{{ number_format($week['revenue'], 0) }} @else &nbsp; @endif
                            </span>
                            <div
                                class="w-full rounded-t bg-teal-500 transition-all duration-300 group-hover:bg-teal-400"
                                style="height: {{ $pct }}%"
                            ></div>
                            <span class="text-xs text-zinc-400">{{ $week['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </flux:card>

            {{-- Monthly Revenue --}}
            <flux:card class="p-5">
                <flux:heading class="mb-1">Monthly Revenue</flux:heading>
                <flux:text class="mb-4 text-xs text-zinc-500">
                    Last 12 months — R{{ fmt_price(collect($this->monthlyStats)->sum('revenue')) }} total
                </flux:text>

                @php $maxRevenue = collect($this->monthlyStats)->max('revenue') ?: 1; @endphp

                <div class="flex h-40 items-end gap-3">
                    @foreach ($this->monthlyStats as $month)
                        @php $pct = max(3, ($month['revenue'] / $maxRevenue) * 100); @endphp
                        <div class="group flex flex-1 flex-col items-center gap-1.5">
                            <span class="text-xs text-zinc-500">
                                @if ($month['revenue'] > 0) R{{ number_format($month['revenue'], 0) }} @else &nbsp; @endif
                            </span>
                            <div
                                class="w-full rounded-t bg-green-500 transition-all duration-300 group-hover:bg-green-400"
                                style="height: {{ $pct }}%"
                            ></div>
                            <span class="text-xs text-zinc-400">{{ $month['short'] }}</span>
                        </div>
                    @endforeach
                </div>
            </flux:card>

        </div>
    @endif
</div>
