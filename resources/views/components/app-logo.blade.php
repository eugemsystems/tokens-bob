@props([
    'sidebar' => false,
])

@php
    $customLogo = cache()->remember('setting.logo', 300, fn () => \App\Models\Setting::get('logo', ''));
@endphp

@if($sidebar)
    <flux:sidebar.brand name="{{ config('app.name') }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md {{ $customLogo ? '' : 'bg-accent-content text-accent-foreground' }} overflow-hidden">
            @if ($customLogo)
                <img src="{{ $customLogo }}" alt="{{ config('app.name') }}" class="h-full w-full object-contain" />
            @else
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
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
