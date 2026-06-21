@props([
    'sidebar' => false,
])

@php
    $customLogo = cache()->remember('setting.logo', 300, fn () => \App\Models\Setting::get('logo', ''));
@endphp

@if($sidebar)
    <a {{ $attributes }} class="flex flex-col items-center gap-1.5 py-2 text-center">
        <div class="flex size-40 items-center justify-center rounded-xl {{ $customLogo ? '' : 'bg-zinc-700' }} overflow-hidden" >
            @if ($customLogo)
                <img src="{{ $customLogo }}" alt="{{ config('app.name') }}" class="object-contain"  />
            @else
                <x-app-logo-icon class="size-6 fill-current text-white" />
            @endif
        </div>
        <span class="text-xs font-semibold tracking-wide text-zinc-200">{{ config('app.name') }}</span>
    </a>
@else
    <flux:brand name="{{ config('app.name') }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md {{ $customLogo ? '' : 'bg-accent-content text-accent-foreground' }} overflow-hidden">
            @if ($customLogo)
                <img src="{{ $customLogo }}" alt="{{ config('app.name') }}" class="h-full w-full object-contain" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:brand>
@endif
