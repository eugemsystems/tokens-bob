<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

@php
    $customFavicon = cache()->remember('setting.favicon', 300, fn () => \App\Models\Setting::get('favicon', ''));
@endphp

@if ($customFavicon)
    <link rel="icon" href="{{ $customFavicon }}">
    <link rel="apple-touch-icon" href="{{ $customFavicon }}">
@else
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
@endif

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>localStorage.setItem('flux.appearance','dark')</script>
@fluxAppearance
