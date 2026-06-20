<?php

use App\Enums\TokenStatus;
use App\Models\Category;
use App\Models\Token;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tokens')] class extends Component
{
    use WithPagination;

    #[Url]
    public ?int $categoryFilter = null;

    #[Url]
    public string $statusFilter = 'all';

    // Bulk import
    public bool $showImport = false;
    public string $bulkTokens = '';
    public ?int $importCategoryId = null;

    // Bulk selection
    /** @var array<int, int> */
    public array $selected = [];
    public bool $showBulkDeleteConfirm = false;

    // Delete confirmation
    public ?int $deletingId = null;
    public bool $showDeleteConfirm = false;

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatedPage(): void
    {
        $this->selected = [];
    }

    /** @return array<int, int> */
    #[Computed]
    public function selectableIdsOnPage(): array
    {
        return $this->tokens
            ->filter(fn (Token $t) => $t->status !== TokenStatus::Sold)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    public function selectAll(): void
    {
        $this->selected = $this->selectableIdsOnPage;
    }

    public function deselectAll(): void
    {
        $this->selected = [];
    }

    public function confirmBulkDelete(): void
    {
        if (empty($this->selected)) {
            return;
        }

        $this->showBulkDeleteConfirm = true;
    }

    public function bulkDestroy(): void
    {
        Token::whereIn('id', $this->selected)
            ->where('status', '!=', TokenStatus::Sold->value)
            ->delete();

        $count = count($this->selected);
        $this->selected = [];
        $this->showBulkDeleteConfirm = false;
        unset($this->tokens);

        Flux::toast(variant: 'success', text: "{$count} token(s) deleted.");
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::orderBy('name')->get();
    }

    #[Computed]
    public function tokens(): LengthAwarePaginator
    {
        return Token::with('category')
            ->when($this->categoryFilter, fn ($q) => $q->where('category_id', $this->categoryFilter))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest()
            ->paginate(25);
    }

    public function openImport(): void
    {
        $this->reset(['bulkTokens', 'importCategoryId']);
        $this->showImport = true;
    }

    public function importTokens(): void
    {
        $this->validate([
            'importCategoryId' => ['required', 'integer', 'exists:categories,id'],
            'bulkTokens' => ['required', 'string'],
        ]);

        $lines = collect(explode("\n", $this->bulkTokens))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->unique()
            ->values();

        if ($lines->isEmpty()) {
            Flux::toast(variant: 'warning', text: 'No valid token codes found.');

            return;
        }

        $now = now();
        $inserted = 0;

        foreach ($lines as $code) {
            Token::create([
                'category_id' => $this->importCategoryId,
                'token_code' => $code,
                'status' => TokenStatus::Available,
            ]);
            $inserted++;
        }

        $this->showImport = false;
        $this->reset(['bulkTokens', 'importCategoryId']);
        unset($this->tokens);

        Flux::toast(variant: 'success', text: "{$inserted} token(s) imported successfully.");
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDeleteConfirm = true;
    }

    public function destroy(): void
    {
        $token = Token::findOrFail($this->deletingId);

        if ($token->status === TokenStatus::Sold) {
            Flux::toast(variant: 'danger', text: 'Sold tokens cannot be deleted.');
            $this->showDeleteConfirm = false;

            return;
        }

        $token->delete();
        $this->showDeleteConfirm = false;
        $this->deletingId = null;
        unset($this->tokens);

        Flux::toast(variant: 'success', text: 'Token deleted.');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">Token Pool</flux:heading>
        <flux:button wire:click="openImport" variant="primary" icon="arrow-up-tray">
            Bulk Import
        </flux:button>
    </div>

    {{-- ── Filters ── --}}
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <flux:select wire:model.live="categoryFilter" placeholder="All categories" class="w-48">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach ($this->categories as $cat)
                <flux:select.option :value="$cat->id">{{ $cat->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="statusFilter" class="w-40">
            <flux:select.option value="all">All statuses</flux:select.option>
            <flux:select.option value="available">Available</flux:select.option>
            <flux:select.option value="reserved">Reserved</flux:select.option>
            <flux:select.option value="sold">Sold</flux:select.option>
        </flux:select>

        <flux:text class="text-sm text-zinc-500">
            {{ $this->tokens->total() }} token(s)
        </flux:text>
    </div>

    {{-- ── Bulk action bar ── --}}
    @if (count($selected) > 0)
        <div class="flex items-center gap-3 rounded-xl border border-red-500/20 bg-red-500/5 px-4 py-3">
            <span class="text-sm font-medium text-red-400">{{ count($selected) }} token(s) selected</span>
            <flux:button wire:click="confirmBulkDelete" variant="danger" size="sm" icon="trash">
                Delete selected
            </flux:button>
            <flux:button wire:click="deselectAll" variant="ghost" size="sm">
                Clear
            </flux:button>
        </div>
    @endif

    {{-- ── Tokens Table ── --}}
    <div class="mt-4">
        <flux:table :paginate="$this->tokens" pagination:scroll-to>
            <flux:table.columns>
                <flux:table.column class="w-10">
                    @php $selectableIds = $this->selectableIdsOnPage; @endphp
                    @if (count($selectableIds) > 0)
                        <input
                            type="checkbox"
                            class="rounded border-zinc-600"
                            x-data="{
                                get allSelected() {
                                    return {{ json_encode($selectableIds) }}.every(id => $wire.selected.includes(id));
                                },
                                get someSelected() {
                                    return {{ json_encode($selectableIds) }}.some(id => $wire.selected.includes(id));
                                }
                            }"
                            :checked="allSelected"
                            :indeterminate="someSelected && !allSelected"
                            @change="allSelected ? $wire.deselectAll() : $wire.selectAll()"
                        />
                    @endif
                </flux:table.column>
                <flux:table.column>Token Code</flux:table.column>
                <flux:table.column>Category</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Added</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->tokens as $token)
                    @php $deletable = $token->status !== \App\Enums\TokenStatus::Sold; @endphp
                    <flux:table.row :key="$token->id">
                        <flux:table.cell>
                            @if ($deletable)
                                <input
                                    type="checkbox"
                                    class="rounded border-zinc-600"
                                    wire:model="selected"
                                    value="{{ $token->id }}"
                                />
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @php
                                $code   = $token->token_code;
                                $len    = strlen($code);
                                $masked = $len > 8
                                    ? substr($code, 0, 4).str_repeat('•', $len - 8).substr($code, -4)
                                    : $code;
                            @endphp
                            <div class="flex items-center gap-2" x-data="{ revealed: false }">
                                <span class="font-mono text-sm" x-show="!revealed">{{ $masked }}</span>
                                <span class="font-mono text-sm" x-show="revealed" style="display:none">{{ $code }}</span>
                                <button
                                    type="button"
                                    x-on:click="revealed = !revealed"
                                    class="text-zinc-500 transition-colors hover:text-zinc-200"
                                    :title="revealed ? 'Hide code' : 'Show code'"
                                >
                                    <flux:icon.eye x-show="!revealed" class="size-4" />
                                    <flux:icon.eye-slash x-show="revealed" class="size-4" style="display:none" />
                                </button>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>{{ $token->category->name }}</flux:table.cell>

                        <flux:table.cell>
                            <flux:badge
                                size="sm"
                                :color="match($token->status->value) {
                                    'available' => 'green',
                                    'reserved'  => 'yellow',
                                    'sold'      => 'zinc',
                                    default     => 'zinc',
                                }"
                            >
                                {{ $token->status->value }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-500">
                            {{ $token->created_at->format('d M Y') }}
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            @if ($deletable)
                                <flux:button
                                    wire:click="confirmDelete({{ $token->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    class="text-red-500 hover:text-red-400"
                                />
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-12 text-center text-zinc-500">
                            No tokens found. Use "Bulk Import" to add inventory.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- ── Bulk Import Modal ── --}}
    <flux:modal wire:model="showImport" flyout class="md:w-[30rem]">
        <flux:heading size="lg">Bulk Import Tokens</flux:heading>
        <flux:text class="mt-1 text-zinc-500">
            Paste one token code per line. Duplicates and blank lines are ignored automatically.
        </flux:text>

        <form wire:submit="importTokens" class="mt-6 space-y-5">
            <flux:select
                wire:model="importCategoryId"
                label="Category"
                placeholder="Select a category…"
                required
            >
                @foreach ($this->categories as $cat)
                    <flux:select.option :value="$cat->id">{{ $cat->name."-".$cat->description }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea
                wire:model="bulkTokens"
                label="Token Codes"
                placeholder="ABCD-1234-EFGH-5678&#10;WXYZ-9012-MNOP-3456&#10;…"
                rows="10"
                class="font-mono text-sm"
                required
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray">
                    Import
                    <span wire:loading wire:target="importTokens">…</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ── Bulk Delete Confirmation ── --}}
    <flux:modal wire:model="showBulkDeleteConfirm" class="md:w-80">
        <div class="space-y-4">
            <flux:heading size="lg">Delete {{ count($selected) }} Token(s)?</flux:heading>
            <flux:text class="text-zinc-500">
                This will permanently remove the selected tokens. Sold tokens are excluded automatically.
            </flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="bulkDestroy" variant="danger">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ── Delete Confirmation ── --}}
    <flux:modal wire:model="showDeleteConfirm" class="md:w-80">
        <div class="space-y-4">
            <flux:heading size="lg">Delete Token?</flux:heading>
            <flux:text class="text-zinc-500">
                This will permanently remove the token from inventory. Sold tokens cannot be deleted.
            </flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="destroy" variant="danger">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
