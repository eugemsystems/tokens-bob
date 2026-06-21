<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Queue Monitor')] class extends Component
{
    use WithPagination;

    public string $tab = 'pending';

    /** @var array<string, int> */
    public array $counts = [];

    public function mount(): void
    {
        $this->refreshCounts();
    }

    public function refreshCounts(): void
    {
        $this->counts = [
            'pending' => DB::table('jobs')->count(),
            'failed'  => DB::table('failed_jobs')->count(),
        ];
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
        $this->refreshCounts();
    }

    public function retryJob(int $id): void
    {
        $failed = DB::table('failed_jobs')->where('id', $id)->first();

        if (! $failed) {
            return;
        }

        $payload = json_decode($failed->payload, true);
        $className = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown');

        DB::table('jobs')->insert([
            'queue'        => $failed->queue,
            'payload'      => $failed->payload,
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->getTimestamp(),
            'created_at'   => now()->getTimestamp(),
        ]);

        DB::table('failed_jobs')->where('id', $id)->delete();

        $this->refreshCounts();
    }

    public function deleteFailedJob(int $id): void
    {
        DB::table('failed_jobs')->where('id', $id)->delete();
        $this->refreshCounts();
    }

    public function clearAllFailed(): void
    {
        DB::table('failed_jobs')->truncate();
        $this->refreshCounts();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Queue Monitor</flux:heading>
            <flux:text class="mt-1 text-zinc-400">Track background jobs — emails, webhooks, and retries.</flux:text>
        </div>
        <flux:button wire:click="refreshCounts" variant="ghost" icon="arrow-path" size="sm">Refresh</flux:button>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-2">
        <flux:card class="flex items-center gap-4 p-5">
            <div class="flex size-10 items-center justify-center rounded-xl bg-blue-500/10">
                <flux:icon.clock class="size-5 text-blue-400" />
            </div>
            <div>
                <p class="text-2xl font-bold text-zinc-100">{{ $counts['pending'] ?? 0 }}</p>
                <p class="text-xs text-zinc-500">Pending / Running</p>
            </div>
        </flux:card>

        <flux:card class="flex items-center gap-4 p-5">
            <div class="flex size-10 items-center justify-center rounded-xl bg-red-500/10">
                <flux:icon.x-circle class="size-5 text-red-400" />
            </div>
            <div>
                <p class="text-2xl font-bold text-zinc-100">{{ $counts['failed'] ?? 0 }}</p>
                <p class="text-xs text-zinc-500">Failed</p>
            </div>
        </flux:card>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-2 border-b border-zinc-700 pb-0">
        <button
            wire:click="setTab('pending')"
            class="border-b-2 px-4 pb-3 text-sm font-semibold transition-colors {{ $tab === 'pending' ? 'border-violet-500 text-violet-400' : 'border-transparent text-zinc-500 hover:text-zinc-300' }}"
        >Pending / Running</button>
        <button
            wire:click="setTab('failed')"
            class="border-b-2 px-4 pb-3 text-sm font-semibold transition-colors {{ $tab === 'failed' ? 'border-red-500 text-red-400' : 'border-transparent text-zinc-500 hover:text-zinc-300' }}"
        >Failed</button>
    </div>

    {{-- ── PENDING JOBS ── --}}
    @if ($tab === 'pending')
        @php
            $jobs = DB::table('jobs')->orderByDesc('id')->paginate(20);
        @endphp

        @if ($jobs->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-zinc-500">
                <flux:icon.check-circle class="mb-3 size-10 text-green-500" />
                <p class="font-medium">Queue is clear</p>
                <p class="mt-1 text-sm">No pending or running jobs.</p>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Job</flux:table.column>
                    <flux:table.column>Queue</flux:table.column>
                    <flux:table.column align="center">Attempts</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Queued At</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($jobs as $job)
                        @php
                            $payload  = json_decode($job->payload, true);
                            $jobName  = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown Job');
                            $jobShort = class_exists($jobName) ? class_basename($jobName) : $jobName;
                            $isRunning = ! is_null($job->reserved_at);
                            $jobData  = json_decode($payload['data']['command'] ?? '{}', true) ?? [];
                        @endphp
                        <flux:table.row :key="$job->id">
                            <flux:table.cell>
                                <p class="font-medium text-zinc-100">{{ $jobShort }}</p>
                                @if (isset($jobData['transactionId']) || isset($jobData['email']) || isset($jobData['url']))
                                    <p class="mt-0.5 font-mono text-xs text-zinc-500">
                                        @isset($jobData['transactionId']) Txn #{{ $jobData['transactionId'] }} @endisset
                                        @isset($jobData['email']) {{ $jobData['email'] }} @endisset
                                        @isset($jobData['url']) {{ Str::limit($jobData['url'], 50) }} @endisset
                                    </p>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="zinc" size="sm">{{ $job->queue }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge :color="$job->attempts > 0 ? 'yellow' : 'zinc'" size="sm">{{ $job->attempts }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($isRunning)
                                    <flux:badge color="blue" size="sm">Running</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">Waiting</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-xs text-zinc-400">{{ Carbon::createFromTimestamp($job->created_at)->diffForHumans() }}</span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="mt-4">{{ $jobs->links() }}</div>
        @endif
    @endif

    {{-- ── FAILED JOBS ── --}}
    @if ($tab === 'failed')
        @php
            $failedJobs = DB::table('failed_jobs')->orderByDesc('id')->paginate(20);
        @endphp

        <div class="flex items-center justify-between">
            <p class="text-sm text-zinc-400">{{ $failedJobs->total() }} failed job{{ $failedJobs->total() === 1 ? '' : 's' }}</p>
            @if ($failedJobs->total() > 0)
                <flux:button wire:click="clearAllFailed" wire:confirm="Delete all failed jobs? This cannot be undone." variant="danger" size="sm" icon="trash">
                    Clear All
                </flux:button>
            @endif
        </div>

        @if ($failedJobs->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-zinc-500">
                <flux:icon.check-circle class="mb-3 size-10 text-green-500" />
                <p class="font-medium">No failed jobs</p>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Job</flux:table.column>
                    <flux:table.column>Queue</flux:table.column>
                    <flux:table.column>Error</flux:table.column>
                    <flux:table.column>Failed At</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($failedJobs as $job)
                        @php
                            $payload  = json_decode($job->payload, true);
                            $jobName  = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown Job');
                            $jobShort = class_exists($jobName) ? class_basename($jobName) : $jobName;
                            $errorFirstLine = explode("\n", $job->exception)[0] ?? $job->exception;
                        @endphp
                        <flux:table.row :key="$job->id">
                            <flux:table.cell>
                                <p class="font-medium text-zinc-100">{{ $jobShort }}</p>
                                <p class="font-mono text-xs text-zinc-500">{{ $job->uuid }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="zinc" size="sm">{{ $job->queue }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <p class="max-w-xs truncate font-mono text-xs text-red-400">{{ $errorFirstLine }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-xs text-zinc-400">
                                    {{ \Illuminate\Support\Carbon::parse($job->failed_at)->diffForHumans() }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:tooltip content="Retry">
                                        <flux:button
                                            wire:click="retryJob({{ $job->id }})"
                                            variant="ghost"
                                            size="sm"
                                            icon="arrow-path"
                                            class="text-blue-400 hover:text-blue-300"
                                        />
                                    </flux:tooltip>
                                    <flux:tooltip content="Delete">
                                        <flux:button
                                            wire:click="deleteFailedJob({{ $job->id }})"
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            class="text-red-500 hover:text-red-400"
                                        />
                                    </flux:tooltip>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="mt-4">{{ $failedJobs->links() }}</div>
        @endif
    @endif
</div>
