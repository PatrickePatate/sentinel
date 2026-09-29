<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chat</title>
    <style>
        /* shadcn/ui "zinc" tokens */
        :root {
            color-scheme: light dark;
            --background: 0 0% 100%; --foreground: 240 10% 3.9%;
            --card: 0 0% 100%; --muted: 240 4.8% 95.9%; --muted-foreground: 240 3.8% 46.1%;
            --primary: 240 5.9% 10%; --primary-foreground: 0 0% 98%;
            --secondary: 240 4.8% 95.9%; --accent: 240 4.8% 95.9%;
            --destructive: 0 84.2% 60.2%; --border: 240 5.9% 90%; --input: 240 5.9% 90%; --ring: 240 5.9% 10%;
            --radius: .5rem;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --background: 240 10% 3.9%; --foreground: 0 0% 98%;
                --card: 240 10% 3.9%; --muted: 240 3.7% 15.9%; --muted-foreground: 240 5% 64.9%;
                --primary: 0 0% 98%; --primary-foreground: 240 5.9% 10%;
                --secondary: 240 3.7% 15.9%; --accent: 240 3.7% 15.9%;
                --destructive: 0 62.8% 30.6%; --border: 240 3.7% 15.9%; --input: 240 3.7% 15.9%; --ring: 240 4.9% 83.9%;
            }
        }
        * { box-sizing: border-box; border-color: hsl(var(--border)); }
        html, body { height: 100%; margin: 0; }
        body { background: hsl(var(--background)); color: hsl(var(--foreground)); font: 14px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        svg { width: 1rem; height: 1rem; flex: none; }

        .chat { display: flex; flex-direction: column; height: 100%; }
        .header { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .75rem 1rem; border-bottom: 1px solid hsl(var(--border)); }
        .header-title { display: flex; align-items: center; gap: .5rem; min-width: 0; font-weight: 600; }
        .header-title span.name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .badge { display: inline-flex; align-items: center; border: 1px solid hsl(var(--border)); border-radius: 9999px; padding: .1rem .55rem; font-size: 11px; font-weight: 600; color: hsl(var(--muted-foreground)); }
        .badge.prod { background: hsl(var(--destructive) / .1); color: hsl(var(--destructive)); border-color: hsl(var(--destructive) / .3); }
        .hint { font-size: 12px; color: hsl(var(--muted-foreground)); }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; height: 2.25rem; padding: 0 .9rem; border-radius: calc(var(--radius) - 2px); border: 1px solid transparent; font: inherit; font-size: 13px; font-weight: 500; cursor: pointer; transition: background .15s, opacity .15s; }
        .btn:focus-visible, .input:focus-visible { outline: 2px solid hsl(var(--ring)); outline-offset: 2px; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .btn-primary { background: hsl(var(--primary)); color: hsl(var(--primary-foreground)); }
        .btn-primary:hover:not(:disabled) { opacity: .9; }
        .btn-ghost { background: transparent; color: hsl(var(--muted-foreground)); height: 2rem; padding: 0 .6rem; }
        .btn-ghost:hover { background: hsl(var(--accent)); color: hsl(var(--foreground)); }
        .btn-outline { background: hsl(var(--background)); border-color: hsl(var(--input)); color: hsl(var(--foreground)); height: auto; padding: .45rem .75rem; text-align: left; font-weight: 400; }
        .btn-outline:hover { background: hsl(var(--accent)); }
        .btn-icon { width: 2.25rem; padding: 0; }

        .messages { flex: 1; overflow-y: auto; padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 1.25rem; scroll-behavior: smooth; }
        .row { display: flex; gap: .65rem; align-items: flex-start; max-width: 100%; }
        .row.user { flex-direction: row-reverse; }
        .avatar { display: flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 9999px; background: hsl(var(--muted)); color: hsl(var(--muted-foreground)); border: 1px solid hsl(var(--border)); }
        .row.assistant .avatar { background: hsl(var(--primary)); color: hsl(var(--primary-foreground)); border-color: transparent; }
        .bubble { max-width: min(85%, 46rem); padding: .55rem .85rem; border-radius: var(--radius); white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; }
        .row.assistant .bubble { background: hsl(var(--card)); border: 1px solid hsl(var(--border)); box-shadow: 0 1px 2px hsl(0 0% 0% / .04); }
        .row.user .bubble { background: hsl(var(--primary)); color: hsl(var(--primary-foreground)); }
        .status { margin-top: .35rem; font-size: 12px; color: hsl(var(--muted-foreground)); }
        .status:empty { display: none; }

        .empty { margin: auto; text-align: center; max-width: 34rem; display: flex; flex-direction: column; align-items: center; gap: .5rem; }
        .empty .avatar { width: 2.75rem; height: 2.75rem; }
        .empty .avatar svg { width: 1.25rem; height: 1.25rem; }
        .empty h2 { margin: .25rem 0 0; font-size: 1.05rem; letter-spacing: -.01em; }
        .empty p { margin: 0; color: hsl(var(--muted-foreground)); }
        .suggestions { display: grid; gap: .5rem; width: 100%; margin-top: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }

        .typing { display: inline-flex; gap: 4px; padding: 6px 0; }
        .typing i { width: 6px; height: 6px; border-radius: 9999px; background: hsl(var(--muted-foreground)); animation: bounce 1.2s infinite ease-in-out; }
        .typing i:nth-child(2) { animation-delay: .15s; } .typing i:nth-child(3) { animation-delay: .3s; }
        @keyframes bounce { 0%, 80%, 100% { opacity: .3; transform: translateY(0); } 40% { opacity: 1; transform: translateY(-3px); } }
        [wire\:stream="answer"]:not(:empty) + .typing-wrap { display: none; }

        .composer { padding: .75rem 1rem 1rem; border-top: 1px solid hsl(var(--border)); }
        .composer form { display: flex; gap: .5rem; }
        .input { flex: 1; height: 2.25rem; padding: 0 .75rem; border: 1px solid hsl(var(--input)); border-radius: calc(var(--radius) - 2px); background: transparent; color: inherit; font: inherit; }
        .input::placeholder { color: hsl(var(--muted-foreground)); }
        .input:disabled { opacity: .5; }
        .error { display: block; margin-top: .4rem; font-size: 12px; color: hsl(var(--destructive)); }
        .footnote { margin-top: .5rem; text-align: center; font-size: 11px; color: hsl(var(--muted-foreground)); }
    </style>
    @livewireStyles
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
