@props(['title' => null])
@php
    $nav = [
        ['Dashboard', 'dashboard', 'lucide-layout-dashboard', 'dashboard'],
        ['Fleet', 'fleet', 'lucide-layout-grid', 'fleet'],
        ['Machines', 'machines.index', 'lucide-server', 'machines*'],
        ['Sites', 'sites.index', 'lucide-globe', 'sites*'],
        ['Scans', 'scans.index', 'lucide-scan-search', 'scans*'],
        ['Issues', 'findings.index', 'lucide-alert-triangle', 'findings*'],
        ['Pending actions', 'actions.index', 'lucide-hand', 'actions*'],
        ['Costs', 'costs', 'lucide-coins', 'costs'],
        ['Notifications', 'channels.index', 'lucide-bell', 'channels*', 'admin'],
        ['Users', 'users.index', 'lucide-users', 'users*', 'admin'],
        ['Audit log', 'audit', 'lucide-clipboard-list', 'audit'],
    ];
@endphp
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
<body class="min-h-screen" x-data="{ sidebar: false }">
    <div x-cloak x-show="sidebar" x-transition.opacity class="fixed inset-0 z-30 bg-black/50 lg:hidden" x-on:click="sidebar = false"></div>

    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r bg-sidebar transition-transform lg:translate-x-0" :class="sidebar && 'translate-x-0'">
        <div class="flex h-14 items-center gap-2 border-b px-5">
            <span class="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">@svg('lucide-shield-check', 'size-4')</span>
            <span class="font-semibold tracking-tight">Sentinel</span>
        </div>
        <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
            @foreach ($nav as $item)
                @php([$label, $route, $icon, $pattern, $ability] = $item + [4 => null])
                @continue($ability && ! auth()->user()->can($ability))
                <a href="{{ route($route) }}" wire:navigate x-on:click="sidebar = false"
                   @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors [&_svg]:size-4', 'bg-accent text-accent-foreground' => request()->routeIs($pattern), 'text-muted-foreground hover:bg-accent/60 hover:text-foreground' => ! request()->routeIs($pattern)])>
                    @svg($icon) <span class="flex-1">{{ $label }}</span>
                    @if ($route === 'actions.index')<livewire:pending-badge />@endif
                </a>
            @endforeach
        </nav>
        <div class="border-t p-3">
            <div class="flex items-center gap-2 px-2 py-1.5">
                <span class="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-semibold uppercase">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p><p class="truncate text-xs text-muted-foreground">{{ auth()->user()->email }}</p></div>
            </div>
            <div class="mt-1 flex gap-1">
                <x-ui.button variant="ghost" size="sm" class="flex-1" x-on:click="$store.theme.cycle()" title="Theme">
                    <span x-show="$store.theme.mode === 'light'">@svg('lucide-sun')</span><span x-cloak x-show="$store.theme.mode === 'dark'">@svg('lucide-moon')</span><span x-cloak x-show="$store.theme.mode === 'system'">@svg('lucide-monitor')</span>
                    <span class="capitalize" x-text="$store.theme.mode"></span>
                </x-ui.button>
                <form method="POST" action="{{ route('logout') }}" class="flex-1">@csrf<x-ui.button type="submit" variant="ghost" size="sm" class="w-full">@svg('lucide-log-out') Log out</x-ui.button></form>
            </div>
        </div>
    </aside>

    <div class="lg:pl-64">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b bg-background/80 px-4 backdrop-blur lg:hidden">
            <x-ui.button variant="ghost" size="icon" x-on:click="sidebar = true" aria-label="Menu">@svg('lucide-menu')</x-ui.button>
            <span class="font-semibold">Sentinel</span>
        </header>
        <main class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">{{ $slot }}</main>
    </div>

    @if (session('toast'))<div x-data x-init="$nextTick(() => $store.toasts.push(@js(session('toast'))))"></div>@endif
    <x-ui.toaster />
    <x-ui.confirm />
    @livewireScripts
</body>
</html>
