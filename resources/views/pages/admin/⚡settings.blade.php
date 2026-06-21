<?php

use App\Models\Setting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Site Settings')] class extends Component
{
    use WithFileUploads;

    // Logo
    #[Validate('nullable|image|max:2048')]
    public $logoUpload = null;

    public string $logoUrl = '';
    public string $logoMode = 'url';
    public string $currentLogoUrl = '';

    // Favicon
    #[Validate('nullable|file|mimes:ico,png,svg,gif|max:512')]
    public $faviconUpload = null;

    public string $faviconUrl = '';
    public string $faviconMode = 'url';
    public string $currentFaviconUrl = '';

    public function mount(): void
    {
        $logo = Setting::get('logo', '');
        $this->currentLogoUrl = $logo;
        $this->logoUrl = $logo;

        $favicon = Setting::get('favicon', '');
        $this->currentFaviconUrl = $favicon;
        $this->faviconUrl = $favicon;
    }

    public function saveLogo(): void
    {
        $this->validateOnly('logoUpload');

        if ($this->logoMode === 'file' && $this->logoUpload) {
            $path = $this->logoUpload->store('branding', 'public');
            $url = Storage::url($path);
        } elseif ($this->logoMode === 'url' && $this->logoUrl) {
            $url = $this->logoUrl;
        } else {
            $url = '';
        }

        Setting::set('logo', $url);
        cache()->forget('setting.logo');

        $this->currentLogoUrl = $url;
        $this->logoUrl = $url;
        $this->logoUpload = null;
        $this->logoMode = 'url';

        Flux::toast(variant: 'success', text: 'Logo saved successfully.');
    }

    public function removeLogo(): void
    {
        Setting::set('logo', '');
        cache()->forget('setting.logo');

        $this->currentLogoUrl = '';
        $this->logoUrl = '';

        Flux::toast(variant: 'success', text: 'Logo removed.');
    }

    public function saveFavicon(): void
    {
        $this->validateOnly('faviconUpload');

        if ($this->faviconMode === 'file' && $this->faviconUpload) {
            $path = $this->faviconUpload->store('branding', 'public');
            $url = Storage::url($path);
        } elseif ($this->faviconMode === 'url' && $this->faviconUrl) {
            $url = $this->faviconUrl;
        } else {
            $url = '';
        }

        Setting::set('favicon', $url);
        cache()->forget('setting.favicon');

        $this->currentFaviconUrl = $url;
        $this->faviconUrl = $url;
        $this->faviconUpload = null;
        $this->faviconMode = 'url';

        Flux::toast(variant: 'success', text: 'Favicon saved successfully.');
    }

    public function removeFavicon(): void
    {
        Setting::set('favicon', '');
        cache()->forget('setting.favicon');

        $this->currentFaviconUrl = '';
        $this->faviconUrl = '';

        Flux::toast(variant: 'success', text: 'Favicon removed.');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-8 p-6">
    <div>
        <flux:heading size="xl">Site Settings</flux:heading>
        <flux:text class="mt-1 text-zinc-400">Manage your site logo and favicon shown across all pages.</flux:text>
    </div>

    {{-- ── Logo Section ── --}}
    <div class="rounded-xl border border-zinc-700/60 bg-zinc-900/50 p-6">
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                <flux:heading size="lg">Site Logo</flux:heading>
                <flux:text class="mt-1 text-zinc-400 text-sm">Displayed in the header, sidebar, and footer on all pages.</flux:text>
            </div>
            @if ($currentLogoUrl)
                <flux:button wire:click="removeLogo" variant="ghost" size="sm" class="text-red-400 hover:text-red-300 flex-shrink-0">
                    Remove
                </flux:button>
            @endif
        </div>

        {{-- Current preview --}}
        @if ($currentLogoUrl)
            <div class="mb-6 flex items-center gap-4">
                <div class="flex h-16 w-40 items-center justify-center rounded-lg border border-zinc-700 bg-zinc-800 px-3">
                    <img src="{{ $currentLogoUrl }}" alt="Current logo" class="max-h-12 max-w-full object-contain" />
                </div>
                <div>
                    <p class="text-xs font-semibold text-zinc-400">Current Logo</p>
                    <p class="mt-0.5 max-w-xs truncate text-xs text-zinc-500">{{ $currentLogoUrl }}</p>
                </div>
            </div>
        @else
            <div class="mb-6 flex h-16 w-40 items-center justify-center rounded-lg border border-dashed border-zinc-700 bg-zinc-800/50">
                <p class="text-xs text-zinc-600">No logo set</p>
            </div>
        @endif

        {{-- Mode tabs --}}
        <div class="flex gap-2 mb-4">
            <flux:button
                wire:click="$set('logoMode', 'url')"
                type="button"
                :variant="$logoMode === 'url' ? 'primary' : 'ghost'"
                size="sm"
            >
                Image URL
            </flux:button>
            <flux:button
                wire:click="$set('logoMode', 'file')"
                type="button"
                :variant="$logoMode === 'file' ? 'primary' : 'ghost'"
                size="sm"
            >
                Upload File
            </flux:button>
        </div>

        @if ($logoMode === 'url')
            <div class="flex gap-3 items-end">
                <div class="flex-1">
                    <flux:input
                        wire:model="logoUrl"
                        label="Logo URL"
                        placeholder="https://example.com/logo.png"
                        type="url"
                    />
                </div>
                <flux:button wire:click="saveLogo" variant="primary">Save Logo</flux:button>
            </div>
            @if ($logoUrl && $logoUrl !== $currentLogoUrl)
                <img src="{{ $logoUrl }}" alt="Preview" class="mt-3 h-12 max-w-[180px] object-contain rounded-lg border border-zinc-700 bg-zinc-800 p-2" onerror="this.style.display='none'" />
            @endif
        @else
            <div class="flex gap-3 items-end">
                <div class="flex-1">
                    <flux:label>Upload Logo</flux:label>
                    <div class="mt-2">
                        <input
                            wire:model="logoUpload"
                            type="file"
                            accept="image/*"
                            class="block w-full text-sm text-zinc-400 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-zinc-700 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-zinc-200 hover:file:bg-zinc-600"
                        />
                        @error('logoUpload')
                            <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <flux:button wire:click="saveLogo" variant="primary">Save Logo</flux:button>
            </div>
            @if ($logoUpload)
                @php try { $logoPreviewUrl = $logoUpload->temporaryUrl(); } catch (\Throwable) { $logoPreviewUrl = null; } @endphp
                @if ($logoPreviewUrl)
                    <img src="{{ $logoPreviewUrl }}" alt="Preview" class="mt-3 h-12 max-w-[180px] object-contain rounded-lg border border-zinc-700 bg-zinc-800 p-2" />
                @else
                    <p class="mt-3 text-xs text-zinc-400">Selected: <span class="text-zinc-200">{{ $logoUpload->getClientOriginalName() }}</span></p>
                @endif
            @endif
        @endif
    </div>

    {{-- ── Favicon Section ── --}}
    <div class="rounded-xl border border-zinc-700/60 bg-zinc-900/50 p-6">
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                <flux:heading size="lg">Favicon</flux:heading>
                <flux:text class="mt-1 text-zinc-400 text-sm">The small icon shown in browser tabs. Supports .ico, .png, .svg, or .gif files.</flux:text>
            </div>
            @if ($currentFaviconUrl)
                <flux:button wire:click="removeFavicon" variant="ghost" size="sm" class="text-red-400 hover:text-red-300 flex-shrink-0">
                    Remove
                </flux:button>
            @endif
        </div>

        {{-- Current preview --}}
        @if ($currentFaviconUrl)
            <div class="mb-6 flex items-center gap-4">
                <div class="flex h-16 w-16 items-center justify-center rounded-lg border border-zinc-700 bg-zinc-800">
                    <img src="{{ $currentFaviconUrl }}" alt="Current favicon" class="h-10 w-10 object-contain" />
                </div>
                <div>
                    <p class="text-xs font-semibold text-zinc-400">Current Favicon</p>
                    <p class="mt-0.5 max-w-xs truncate text-xs text-zinc-500">{{ $currentFaviconUrl }}</p>
                </div>
            </div>
        @else
            <div class="mb-6 flex h-16 w-16 items-center justify-center rounded-lg border border-dashed border-zinc-700 bg-zinc-800/50">
                <p class="text-xs text-zinc-600">None</p>
            </div>
        @endif

        {{-- Mode tabs --}}
        <div class="flex gap-2 mb-4">
            <flux:button
                wire:click="$set('faviconMode', 'url')"
                type="button"
                :variant="$faviconMode === 'url' ? 'primary' : 'ghost'"
                size="sm"
            >
                Image URL
            </flux:button>
            <flux:button
                wire:click="$set('faviconMode', 'file')"
                type="button"
                :variant="$faviconMode === 'file' ? 'primary' : 'ghost'"
                size="sm"
            >
                Upload File
            </flux:button>
        </div>

        @if ($faviconMode === 'url')
            <div class="flex gap-3 items-end">
                <div class="flex-1">
                    <flux:input
                        wire:model="faviconUrl"
                        label="Favicon URL"
                        placeholder="https://example.com/favicon.ico"
                        type="url"
                    />
                </div>
                <flux:button wire:click="saveFavicon" variant="primary">Save Favicon</flux:button>
            </div>
            @if ($faviconUrl && $faviconUrl !== $currentFaviconUrl)
                <img src="{{ $faviconUrl }}" alt="Preview" class="mt-3 h-10 w-10 object-contain rounded border border-zinc-700 bg-zinc-800 p-1" onerror="this.style.display='none'" />
            @endif
        @else
            <div class="flex gap-3 items-end">
                <div class="flex-1">
                    <flux:label>Upload Favicon</flux:label>
                    <div class="mt-2">
                        <input
                            wire:model="faviconUpload"
                            type="file"
                            accept=".ico,.png,.svg,.gif,image/*"
                            class="block w-full text-sm text-zinc-400 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-zinc-700 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-zinc-200 hover:file:bg-zinc-600"
                        />
                        @error('faviconUpload')
                            <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <flux:button wire:click="saveFavicon" variant="primary">Save Favicon</flux:button>
            </div>
            @if ($faviconUpload)
                @php try { $faviconPreviewUrl = $faviconUpload->temporaryUrl(); } catch (\Throwable) { $faviconPreviewUrl = null; } @endphp
                @if ($faviconPreviewUrl)
                    <img src="{{ $faviconPreviewUrl }}" alt="Preview" class="mt-3 h-10 w-10 object-contain rounded border border-zinc-700 bg-zinc-800 p-1" />
                @else
                    <p class="mt-3 text-xs text-zinc-400">Selected: <span class="text-zinc-200">{{ $faviconUpload->getClientOriginalName() }}</span></p>
                @endif
            @endif
        @endif
    </div>
</div>
