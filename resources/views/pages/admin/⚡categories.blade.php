<?php

use App\Enums\TokenStatus;
use App\Models\Category;
use App\Models\Token;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Categories')] class extends Component
{
    use WithPagination;

    // Modal state
    public bool $showModal = false;
    public ?int $editingId = null;

    // Form fields
    #[Validate('required|string|max:100')]
    public string $name = '';

    #[Validate('required|numeric|min:0.01|max:99999.99')]
    public string $price = '';

    #[Validate('nullable|string|max:500')]
    public string $description = '';

    public bool $isToken = true;

    // Delete confirmation
    public ?int $deletingId = null;
    public bool $showDeleteConfirm = false;

    #[Computed]
    public function categories(): LengthAwarePaginator
    {
        return Category::withCount([
            'tokens as total_tokens_count',
            'tokens as available_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Available),
            'tokens as sold_tokens_count' => fn ($q) => $q->where('status', TokenStatus::Sold),
        ])
            ->orderBy('name')
            ->paginate(15);
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'name', 'price', 'description']);
        $this->isToken = true;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $category = Category::findOrFail($id);
        $this->editingId = $id;
        $this->name = $category->name;
        $this->price = (string) $category->price;
        $this->description = $category->description ?? '';
        $this->isToken = (bool) $category->is_token;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'name'        => $this->name,
            'price'       => $this->price,
            'description' => $this->description ?: null,
            'is_token'    => $this->isToken,
        ];

        if ($this->editingId) {
            $category        = Category::findOrFail($this->editingId);
            $wasToken        = (bool) $category->is_token;
            $switchedToWebhook = $wasToken && ! $this->isToken;

            DB::transaction(function () use ($category, $data, $switchedToWebhook): void {
                $category->update($data);

                // Release any reserved tokens when switching to webhook mode.
                if ($switchedToWebhook) {
                    Token::where('category_id', $category->id)
                        ->where('status', TokenStatus::Reserved)
                        ->update(['status' => TokenStatus::Available, 'transaction_id' => null]);
                }
            });

            $message = 'Category updated.';

            if ($switchedToWebhook) {
                $message = 'Category updated — switched to webhook mode. Any reserved tokens have been released.';
            } elseif (! $wasToken && $this->isToken) {
                $message = 'Category updated — switched to token delivery mode.';
            }

            Flux::toast(variant: 'success', text: $message);
        } else {
            Category::create($data);
            Flux::toast(variant: 'success', text: 'Category created.');
        }

        $this->showModal = false;
        unset($this->categories);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDeleteConfirm = true;
    }

    public function destroy(): void
    {
        $category = Category::findOrFail($this->deletingId);

        if ($category->tokens()->exists()) {
            Flux::toast(variant: 'danger', text: 'Cannot delete a category that has tokens. Remove the tokens first.');
            $this->showDeleteConfirm = false;

            return;
        }

        $category->delete();
        $this->showDeleteConfirm = false;
        $this->deletingId = null;
        unset($this->categories);

        Flux::toast(variant: 'success', text: 'Category deleted.');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Categories</flux:heading>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">
            New Category
        </flux:button>
    </div>

    <div class="mt-6">
        <flux:table :paginate="$this->categories" pagination:scroll-to>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Price</flux:table.column>
                <flux:table.column align="center">Type</flux:table.column>
                <flux:table.column align="center">Available</flux:table.column>
                <flux:table.column align="center">Sold</flux:table.column>
                <flux:table.column align="center">Total</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->categories as $category)
                    <flux:table.row :key="$category->id">
                        <flux:table.cell>
                            <div>
                                <p class="font-medium">{{ $category->name }}</p>
                                @if ($category->description)
                                    <p class="text-xs text-zinc-500">{{ Str::limit($category->description, 60) }}</p>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell variant="strong">
                            R{{ number_format($category->price, 2) }}
                        </flux:table.cell>

                        <flux:table.cell align="center">
                            <flux:badge
                                :color="$category->is_token ? 'blue' : 'violet'"
                                size="sm"
                            >
                                {{ $category->is_token ? 'Token' : 'Webhook' }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell align="center">
                            <flux:badge
                                :color="$category->available_tokens_count > 0 ? 'green' : 'red'"
                                size="sm"
                            >
                                {{ $category->available_tokens_count }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell align="center">
                            <flux:badge color="zinc" size="sm">{{ $category->sold_tokens_count }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell align="center">
                            {{ $category->total_tokens_count }}
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button
                                    wire:click="openEdit({{ $category->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                />
                                <flux:button
                                    wire:click="confirmDelete({{ $category->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    class="text-red-500 hover:text-red-400"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="py-12 text-center text-zinc-500">
                            No categories yet.
                            <flux:button wire:click="openCreate" variant="ghost" size="sm">Create one</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- ── Create / Edit Modal ── --}}
    <flux:modal wire:model="showModal" flyout class="md:w-96">
        <flux:heading size="lg">
            {{ $editingId ? 'Edit Category' : 'New Category' }}
        </flux:heading>

        <form wire:submit="save" class="mt-6 space-y-5">
            <flux:input
                wire:model="name"
                label="Name"
                placeholder="e.g. Netflix Premium 1 Month"
                required
                autofocus
            />

            <flux:input
                wire:model="price"
                label="Price (ZAR)"
                type="number"
                step="0.01"
                min="0.01"
                placeholder="249.00"
                required
            />

            <div>
                <flux:label>Purchase Type</flux:label>
                <div class="mt-2 flex gap-2">
                    <flux:button
                        wire:click="$set('isToken', true)"
                        type="button"
                        :variant="$isToken ? 'primary' : 'ghost'"
                        class="flex-1"
                    >
                        Token delivery
                    </flux:button>
                    <flux:button
                        wire:click="$set('isToken', false)"
                        type="button"
                        :variant="$isToken ? 'ghost' : 'primary'"
                        class="flex-1"
                    >
                        Webhook
                    </flux:button>
                </div>
                <p class="mt-2 text-xs text-zinc-400">
                    @if ($isToken)
                        After payment the customer receives their token code by email.
                    @else
                        After payment no token is shown — the configured webhook URL is called with the customer's email instead.
                    @endif
                </p>
            </div>

            <flux:textarea
                wire:model="description"
                label="Description"
                placeholder="Optional short description…"
                rows="3"
            />

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? 'Save Changes' : 'Create' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ── Delete Confirmation ── --}}
    <flux:modal wire:model="showDeleteConfirm" class="md:w-80">
        <div class="space-y-4">
            <flux:heading size="lg">Delete Category?</flux:heading>
            <flux:text class="text-zinc-500">
                This action cannot be undone. Categories with tokens cannot be deleted.
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
