<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chat</title>
    <style>
        :root { color-scheme: light dark; --bg: #fff; --fg: #1f2937; --muted: #6b7280; --user: #e0e7ff; --bot: #f3f4f6; --border: #e5e7eb; --accent: #4f46e5; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111827; --fg: #e5e7eb; --muted: #9ca3af; --user: #312e81; --bot: #1f2937; --border: #374151; --accent: #818cf8; } }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { background: var(--bg); color: var(--fg); font: 14px/1.5 system-ui, sans-serif; }
    </style>
    @livewireStyles
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
