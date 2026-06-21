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

    public bool $showDetail = false;

    /** @var array<string, mixed> */
    public array $detail = [];

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

    public function openDetail(int $id): void
    {
        if ($this->tab === 'pending') {
            $job = DB::table('jobs')->find($id);

            if (! $job) {
                return;
            }

            $payload  = json_decode($job->payload, true);
            $props    = $this->decodeCommand($payload['data']['command'] ?? '');
            $isRunning = ! is_null($job->reserved_at);

            $this->detail = [
                'type'        => 'pending',
                'id'          => $job->id,
                'uuid'        => $payload['uuid'] ?? '—',
                'name'        => $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown'),
                'queue'       => $job->queue,
                'status'      => $isRunning ? 'Running' : 'Waiting',
                'attempts'    => $job->attempts,
                'maxTries'    => $payload['maxTries'] ?? '—',
                'backoff'     => $payload['backoff'] ?? '—',
                'timeout'     => $payload['timeout'] ?? '—',
                'createdAt'   => Carbon::createFromTimestamp($job->created_at)->format('Y-m-d H:i:s'),
                'availableAt' => Carbon::createFromTimestamp($job->available_at)->format('Y-m-d H:i:s'),
                'reservedAt'  => $job->reserved_at ? Carbon::createFromTimestamp($job->reserved_at)->format('Y-m-d H:i:s') : null,
                'props'       => $props,
                'rawPayload'  => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        } else {
            $job = DB::table('failed_jobs')->find($id);

            if (! $job) {
                return;
            }

            $payload = json_decode($job->payload, true);
            $props   = $this->decodeCommand($payload['data']['command'] ?? '');

            $this->detail = [
                'type'        => 'failed',
                'id'          => $job->id,
                'uuid'        => $job->uuid,
                'name'        => $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown'),
                'queue'       => $job->queue,
                'connection'  => $job->connection,
                'attempts'    => $payload['maxTries'] ?? '—',
                'maxTries'    => $payload['maxTries'] ?? '—',
                'backoff'     => $payload['backoff'] ?? '—',
                'timeout'     => $payload['timeout'] ?? '—',
                'failedAt'    => Carbon::parse($job->failed_at)->format('Y-m-d H:i:s'),
                'props'       => $props,
                'exception'   => $job->exception,
                'rawPayload'  => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        }

        $this->showDetail = true;
    }

    public function retryJob(int $id): void
    {
        $failed = DB::table('failed_jobs')->where('id', $id)->first();

        if (! $failed) {
            return;
        }

        DB::table('jobs')->insert([
            'queue'        => $failed->queue,
            'payload'      => $failed->payload,
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->getTimestamp(),
            'created_at'   => now()->getTimestamp(),
        ]);

        DB::table('failed_jobs')->where('id', $id)->delete();
        $this->showDetail = false;
        $this->refreshCounts();
    }

    public function deleteFailedJob(int $id): void
    {
        DB::table('failed_jobs')->where('id', $id)->delete();
        $this->showDetail = false;
        $this->refreshCounts();
    }

    public function clearAllFailed(): void
    {
        DB::table('failed_jobs')->truncate();
        $this->refreshCounts();
    }

    /** @return array<string, mixed> */
    private function decodeCommand(string $serialized): array
    {
        if (empty($serialized)) {
            return [];
        }

        try {
            $job = unserialize($serialized);

            if (! is_object($job)) {
                return [];
            }

            $props  = [];
            $ref    = new ReflectionClass($job);

            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                $value = $prop->getValue($job);
                $props[$prop->getName()] = is_scalar($value) || is_null($value) ? $value : json_encode($value);
            }

            return $props;
        } catch (Throwable) {
            return [];
        }
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
    <div class="grid grid-cols-2 gap-4">
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
    <div class="flex gap-2 border-b border-zinc-700">
        <button wire:click="setTab('pending')"
            class="border-b-2 px-4 pb-3 text-sm font-semibold transition-colors {{ $tab === 'pending' ? 'border-violet-500 text-violet-400' : 'border-transparent text-zinc-500 hover:text-zinc-300' }}">
            Pending / Running
        </button>
        <button wire:click="setTab('failed')"
            class="border-b-2 px-4 pb-3 text-sm font-semibold transition-colors {{ $tab === 'failed' ? 'border-red-500 text-red-400' : 'border-transparent text-zinc-500 hover:text-zinc-300' }}">
            Failed
        </button>
    </div>

    {{-- ── PENDING JOBS ── --}}
    @if ($tab === 'pending')
        @php $jobs = DB::table('jobs')->orderByDesc('id')->paginate(20); @endphp

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
                    <flux:table.column>Queued</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($jobs as $job)
                        @php
                            $payload   = json_decode($job->payload, true);
                            $jobName   = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown');
                            $jobShort  = class_basename($jobName);
                            $isRunning = ! is_null($job->reserved_at);
                        @endphp
                        <flux:table.row :key="$job->id">
                            <flux:table.cell>
                                <p class="font-medium text-zinc-100">{{ $jobShort }}</p>
                                <p class="mt-0.5 font-mono text-xs text-zinc-500">{{ Str::limit($payload['uuid'] ?? '', 18) }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="zinc" size="sm">{{ $job->queue }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge :color="$job->attempts > 0 ? 'yellow' : 'zinc'" size="sm">
                                    {{ $job->attempts }}{{ isset($payload['maxTries']) && $payload['maxTries'] ? '/'.$payload['maxTries'] : '' }}
                                </flux:badge>
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
                            <flux:table.cell align="end">
                                <flux:button wire:click="openDetail({{ $job->id }})" variant="ghost" size="sm" icon="eye" />
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
        @php $failedJobs = DB::table('failed_jobs')->orderByDesc('id')->paginate(20); @endphp

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
                            $payload        = json_decode($job->payload, true);
                            $jobName        = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown');
                            $jobShort       = class_basename($jobName);
                            $errorFirstLine = explode("\n", $job->exception)[0] ?? $job->exception;
                        @endphp
                        <flux:table.row :key="$job->id">
                            <flux:table.cell>
                                <p class="font-medium text-zinc-100">{{ $jobShort }}</p>
                                <p class="mt-0.5 font-mono text-xs text-zinc-500">{{ Str::limit($job->uuid, 18) }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="zinc" size="sm">{{ $job->queue }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <p class="max-w-xs truncate font-mono text-xs text-red-400">{{ $errorFirstLine }}</p>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-xs text-zinc-400">{{ Carbon::parse($job->failed_at)->diffForHumans() }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:tooltip content="View details">
                                        <flux:button wire:click="openDetail({{ $job->id }})" variant="ghost" size="sm" icon="eye" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Retry">
                                        <flux:button wire:click="retryJob({{ $job->id }})" variant="ghost" size="sm" icon="arrow-path" class="text-blue-400 hover:text-blue-300" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Delete">
                                        <flux:button wire:click="deleteFailedJob({{ $job->id }})" variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-400" />
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

    {{-- ── DETAIL MODAL ── --}}
    <flux:modal wire:model="showDetail" flyout position="right" class="md:w-[48rem]">
        @if (! empty($detail))
            <div class="space-y-5">
                {{-- Header --}}
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg">{{ class_basename($detail['name']) }}</flux:heading>
                        <p class="mt-1 font-mono text-xs text-zinc-500">{{ $detail['name'] }}</p>
                    </div>
                    @if ($detail['type'] === 'failed')
                        <flux:badge color="red">Failed</flux:badge>
                    @elseif (($detail['status'] ?? '') === 'Running')
                        <flux:badge color="blue">Running</flux:badge>
                    @else
                        <flux:badge color="green">Waiting</flux:badge>
                    @endif
                </div>

                {{-- Identity --}}
                <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4 space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Identity</p>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        <div>
                            <p class="text-zinc-500">UUID</p>
                            <p class="mt-0.5 break-all font-mono text-xs text-zinc-200">{{ $detail['uuid'] }}</p>
                        </div>
                        <div>
                            <p class="text-zinc-500">Queue</p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['queue'] }}</p>
                        </div>
                        @if ($detail['type'] === 'failed' && isset($detail['connection']))
                            <div>
                                <p class="text-zinc-500">Connection</p>
                                <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['connection'] }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Execution config --}}
                <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4 space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Execution</p>
                    <div class="grid grid-cols-3 gap-x-6 gap-y-2 text-sm">
                        <div>
                            <p class="text-zinc-500">Attempts</p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['attempts'] ?? 0 }}</p>
                        </div>
                        <div>
                            <p class="text-zinc-500">Max Tries</p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['maxTries'] ?? '∞' }}</p>
                        </div>
                        <div>
                            <p class="text-zinc-500">Backoff</p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['backoff'] !== '—' ? $detail['backoff'].'s' : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-zinc-500">Timeout</p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['timeout'] !== '—' ? $detail['timeout'].'s' : '—' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Timestamps --}}
                <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4 space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Timestamps</p>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        @if ($detail['type'] === 'pending')
                            <div>
                                <p class="text-zinc-500">Queued At</p>
                                <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['createdAt'] }}</p>
                            </div>
                            <div>
                                <p class="text-zinc-500">Available At</p>
                                <p class="mt-0.5 font-mono text-xs text-zinc-200">{{ $detail['availableAt'] }}</p>
                            </div>
                            @if ($detail['reservedAt'])
                                <div>
                                    <p class="text-zinc-500">Started Running</p>
                                    <p class="mt-0.5 font-mono text-xs text-blue-400">{{ $detail['reservedAt'] }}</p>
                                </div>
                            @endif
                        @else
                            <div>
                                <p class="text-zinc-500">Failed At</p>
                                <p class="mt-0.5 font-mono text-xs text-red-400">{{ $detail['failedAt'] }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Job properties --}}
                @if (! empty($detail['props']))
                    <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-4 space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Job Data</p>
                        <div class="space-y-2">
                            @foreach ($detail['props'] as $key => $value)
                                <div class="flex items-start gap-3 text-sm">
                                    <span class="w-32 shrink-0 text-zinc-500">{{ $key }}</span>
                                    <span class="break-all font-mono text-xs text-zinc-200">{{ is_null($value) ? 'null' : $value }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Exception (failed only) --}}
                @if ($detail['type'] === 'failed' && ! empty($detail['exception']))
                    <div x-data="{ expanded: false }" class="rounded-lg border border-red-800/50 bg-red-950/30 p-4 space-y-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wider text-red-400">Exception</p>
                            <button x-on:click="expanded = !expanded" class="text-xs text-zinc-500 hover:text-zinc-300">
                                <span x-show="!expanded">Show full trace</span>
                                <span x-show="expanded" x-cloak>Collapse</span>
                            </button>
                        </div>
                        <p class="font-mono text-xs text-red-300">{{ explode("\n", $detail['exception'])[0] }}</p>
                        <pre x-show="expanded" x-cloak x-transition
                            class="mt-2 max-h-64 overflow-auto rounded bg-zinc-900 p-3 font-mono text-xs text-zinc-400 whitespace-pre-wrap break-all">{{ $detail['exception'] }}</pre>
                    </div>
                @endif

                {{-- Actions for failed --}}
                @if ($detail['type'] === 'failed')
                    <div class="flex gap-3 pt-1">
                        <flux:button wire:click="retryJob({{ $detail['id'] }})" variant="primary" icon="arrow-path" class="flex-1">
                            Retry Job
                        </flux:button>
                        <flux:button wire:click="deleteFailedJob({{ $detail['id'] }})" variant="danger" icon="trash">
                            Delete
                        </flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
