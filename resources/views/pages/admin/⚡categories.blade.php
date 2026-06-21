<?php

use App\Enums\TokenStatus;
use App\Models\Category;
use App\Models\Token;
use Illuminate\Support\Str;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Categories')] class extends Component
{
    use WithFileUploads;
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

    // Image fields
    #[Validate('nullable|image|max:5120')]
    public $imageUpload = null;

    public string $imageExternalUrl = '';
    public string $imageMode = 'url'; // 'url' or 'file'
    public ?string $currentImagePreview = null;

    // JSON import
    #[Validate('nullable|file|max:10240')]
    public $importFile = null;

    public bool $showImportModal = false;

    // Delete confirmation
    public ?int $deletingId = null;
    public bool $showDeleteConfirm = false;

    // Checkout URL popup
    public bool $showCheckoutUrlModal = false;
    public string $checkoutUrl = '';

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
        $this->reset(['editingId', 'name', 'price', 'description', 'imageUpload', 'imageExternalUrl', 'currentImagePreview']);
        $this->isToken = true;
        $this->imageMode = 'url';
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
        $this->imageUpload = null;

        $img = $category->image ?? '';

        if ($img && (str_starts_with($img, 'http://') || str_starts_with($img, 'https://'))) {
            $this->imageExternalUrl = $img;
            $this->imageMode = 'url';
            $this->currentImagePreview = $img;
        } elseif ($img) {
            $this->imageExternalUrl = '';
            $this->imageMode = 'file';
            $this->currentImagePreview = Storage::url($img);
        } else {
            $this->imageExternalUrl = '';
            $this->imageMode = 'url';
            $this->currentImagePreview = null;
        }

        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $imageValue = null;

        if ($this->imageMode === 'file') {
            if ($this->imageUpload) {
                $imageValue = $this->imageUpload->store('categories', 'public');
            } elseif ($this->editingId) {
                $existing = Category::find($this->editingId)?->image;
                if ($existing && ! str_starts_with($existing, 'http')) {
                    $imageValue = $existing;
                }
            }
        } else {
            $imageValue = $this->imageExternalUrl ?: null;
        }

        $data = [
            'name'        => $this->name,
            'price'       => $this->price,
            'description' => $this->description ?: null,
            'is_token'    => $this->isToken,
            'image'       => $imageValue,
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

    public function openImportModal(): void
    {
        $this->reset(['importFile']);
        $this->resetValidation();
        $this->showImportModal = true;
    }

    public function importFromJson(): void
    {
        $this->validateOnly('importFile');

        if (! $this->importFile) {
            Flux::toast(variant: 'warning', text: 'Please select a JSON file.');

            return;
        }

        $contents = file_get_contents($this->importFile->getRealPath());
        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            Flux::toast(variant: 'danger', text: 'Invalid JSON. Expected an array of category objects.');

            return;
        }

        $categoriesImported = 0;
        $tokensImported = 0;
        $rowErrors = 0;

        foreach ($data as $index => $item) {
            if (! isset($item['name']) || ! isset($item['price'])) {
                $rowErrors++;

                continue;
            }

            try {
                $category = Category::updateOrCreate(
                    ['name' => $item['name']],
                    [
                        'price'       => (float) $item['price'],
                        'description' => $item['description'] ?? null,
                        'is_token'    => (bool) ($item['is_token'] ?? true),
                        'image'       => $item['image'] ?? null,
                    ]
                );

                $categoriesImported++;

                foreach ((array) ($item['keys'] ?? []) as $code) {
                    if (! is_string($code) || trim($code) === '') {
                        continue;
                    }

                    Token::create([
                        'category_id' => $category->id,
                        'token_code'  => trim($code),
                        'status'      => TokenStatus::Available,
                    ]);

                    $tokensImported++;
                }
            } catch (\Throwable) {
                $rowErrors++;
            }
        }

        $this->showImportModal = false;
        $this->reset(['importFile']);
        unset($this->categories);

        $categoryWord = $categoriesImported === 1 ? 'category' : 'categories';
        $keyWord = $tokensImported === 1 ? 'key' : 'keys';
        $message = "Imported {$categoriesImported} {$categoryWord} and {$tokensImported} {$keyWord}.";

        if ($rowErrors > 0) {
            $message .= " {$rowErrors} row(s) skipped due to errors.";
            Flux::toast(variant: 'warning', text: $message);
        } else {
            Flux::toast(variant: 'success', text: $message);
        }
    }

    public function openCheckoutUrl(int $id): void
    {
        $this->checkoutUrl = route('checkout').'?product='.$id;
        $this->showCheckoutUrlModal = true;
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
        <div class="flex items-center gap-2">
            <flux:button wire:click="openImportModal" variant="ghost" icon="arrow-up-tray">
                Import JSON
            </flux:button>
            <flux:button wire:click="openCreate" variant="primary" icon="plus">
                New Category
            </flux:button>
        </div>
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
                            <div class="flex items-center gap-3">
                                @if ($category->image_url)
                                    <img src="{{ $category->image_url }}" alt="" class="size-9 rounded-lg object-cover flex-shrink-0" />
                                @endif
                                <div>
                                    <p class="font-medium">{{ $category->name }}</p>
                                    @if ($category->description)
                                        <p class="text-xs text-zinc-500">{{ Str::limit($category->description, 60) }}</p>
                                    @endif
                                </div>
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
                                @if (! $category->is_token)
                                    <flux:tooltip content="Checkout URL">
                                        <flux:button
                                            wire:click="openCheckoutUrl({{ $category->id }})"
                                            variant="ghost"
                                            size="sm"
                                            icon="link"
                                        />
                                    </flux:tooltip>
                                @endif
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

            {{-- Image --}}
            <div>
                <flux:label>Image</flux:label>
                <div class="mt-2 flex gap-2">
                    <flux:button
                        wire:click="$set('imageMode', 'url')"
                        type="button"
                        :variant="$imageMode === 'url' ? 'primary' : 'ghost'"
                        size="sm"
                        class="flex-1"
                    >
                        URL
                    </flux:button>
                    <flux:button
                        wire:click="$set('imageMode', 'file')"
                        type="button"
                        :variant="$imageMode === 'file' ? 'primary' : 'ghost'"
                        size="sm"
                        class="flex-1"
                    >
                        Upload File
                    </flux:button>
                </div>

                @if ($imageMode === 'url')
                    <flux:input
                        wire:model="imageExternalUrl"
                        class="mt-2"
                        placeholder="https://example.com/image.jpg"
                        type="url"
                    />
                    @if ($imageExternalUrl)
                        <img src="{{ $imageExternalUrl }}" alt="Preview" class="mt-2 h-28 w-full rounded-lg object-cover" onerror="this.style.display='none'" />
                    @endif
                @else
                    <div class="mt-2">
                        <input
                            wire:model="imageUpload"
                            type="file"
                            accept="image/*"
                            class="block w-full text-sm text-zinc-400 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-zinc-700 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-zinc-200 hover:file:bg-zinc-600"
                        />
                        @error('imageUpload')
                            <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($imageUpload)
                        <img src="{{ $imageUpload->temporaryUrl() }}" alt="Preview" class="mt-2 h-28 w-full rounded-lg object-cover" />
                    @elseif ($currentImagePreview)
                        <img src="{{ $currentImagePreview }}" alt="Current image" class="mt-2 h-28 w-full rounded-lg object-cover opacity-60" />
                        <p class="mt-1 text-xs text-zinc-500">Current image — upload a new file to replace it</p>
                    @endif
                @endif
            </div>

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

    {{-- ── JSON Import ── --}}
    <flux:modal wire:model="showImportModal" flyout class="md:w-96">
        <flux:heading size="lg">Import Categories via JSON</flux:heading>
        <flux:text class="mt-1 text-zinc-400">
            Creates or updates categories and adds their keys in one step.
        </flux:text>

        <div class="mt-5 rounded-lg border border-zinc-700 bg-zinc-900 p-4">
            <p class="mb-2 text-xs font-semibold text-zinc-400">Expected format</p>
            <pre class="overflow-x-auto text-xs text-zinc-300 leading-relaxed">[
  {
    "name": "Netflix Premium",
    "price": 249.00,
    "description": "Optional",
    "is_token": true,
    "image": "https://example.com/img.jpg",
    "keys": ["ABC-123", "DEF-456"]
  }
]</pre>
        </div>

        <form wire:submit="importFromJson" class="mt-5 space-y-5">
            <div>
                <flux:label>JSON File</flux:label>
                <div class="mt-2">
                    <input
                        wire:model="importFile"
                        type="file"
                        accept=".json,application/json"
                        class="block w-full text-sm text-zinc-400 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-zinc-700 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-zinc-200 hover:file:bg-zinc-600"
                    />
                    @error('importFile')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3 text-xs text-amber-300">
                Existing categories with the same name will be <strong>updated</strong>. Their keys will be <strong>added</strong> (not replaced).
            </div>

            <div class="flex justify-end gap-2 pt-1">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray">
                    <span wire:loading.remove wire:target="importFromJson">Import</span>
                    <span wire:loading wire:target="importFromJson">Importing…</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ── Checkout URL ── --}}
    <flux:modal wire:model="showCheckoutUrlModal" class="md:w-xl">
        <flux:heading size="lg">Checkout URL</flux:heading>
        <flux:text class="mt-1 text-zinc-400">
            Share this link to send customers directly to the checkout for this product.
        </flux:text>

        <div
            x-data="{ copied: false }"
            class="mt-5 space-y-3"
        >
            <div class="flex items-center gap-2 rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2.5">
                <flux:icon.link class="size-4 shrink-0 text-zinc-500" />
                <p class="flex-1 truncate font-mono text-sm text-zinc-200">{{ $checkoutUrl }}</p>
            </div>

            <flux:button
                x-on:click="navigator.clipboard.writeText('{{ $checkoutUrl }}'); copied = true; setTimeout(() => copied = false, 2000)"
                variant="primary"
                icon="clipboard-document"
                class="w-full"
            >
                <span x-show="!copied">Copy URL</span>
                <span x-show="copied" x-cloak>Copied!</span>
            </flux:button>
        </div>
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
