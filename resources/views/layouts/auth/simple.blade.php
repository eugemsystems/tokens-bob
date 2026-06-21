<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-950 antialiased">
        @php
            $customLogo = cache()->remember('setting.logo', 300, fn () => \App\Models\Setting::get('logo', ''));
        @endphp

        <div class="flex min-h-svh flex-col items-center justify-center p-4">
            {{-- Logo --}}
            <a href="{{ route('home') }}" class="mb-8 flex items-center gap-3" wire:navigate>
                @if ($customLogo)
                    <img src="{{ $customLogo }}" alt="{{ config('app.name') }}" style="height:131px;object-fit:contain;" />
                @else
                    <div class="flex size-16 items-center justify-center rounded-2xl bg-zinc-800">
                        <x-app-logo-icon class="size-10 fill-current text-white" />
                    </div>
                    <span class="text-xl font-bold text-white">{{ config('app.name') }}</span>
                @endif
            </a>

            {{-- Card --}}
            <div class="w-full max-w-sm rounded-2xl border border-zinc-800 bg-zinc-900 p-8 shadow-2xl">
                {{ $slot }}
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
