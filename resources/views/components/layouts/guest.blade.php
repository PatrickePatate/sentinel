@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($_COOKIE['theme'] ?? '') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? "$title · " : '' }}Sentinel</title>
    <script>document.documentElement.classList.toggle('dark', (localStorage.getItem('theme') || 'system') === 'dark' || ((localStorage.getItem('theme') || 'system') === 'system' && matchMedia('(prefers-color-scheme: dark)').matches))</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-sm space-y-6">
        <div class="flex flex-col items-center gap-2 text-center">
            <span class="flex size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">@svg('lucide-shield-check', 'size-5')</span>
            <h1 class="text-xl font-semibold tracking-tight">Sentinel</h1>
            <p class="text-sm text-muted-foreground">An AI sysadmin that cannot run anything you didn't allow.</p>
        </div>
        {{ $slot }}
    </div>
    @livewireScripts
</body>
</html>
