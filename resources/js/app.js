import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// WebSockets (Laravel Reverb): the server pushes "something changed" on a private channel and Livewire components re-render.
// Without a key (or a reachable server) nothing breaks: the views keep polling.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

// Livewire ships Alpine: components are registered on its init event. Everything here is progressive enhancement.
const storage = {
    get: (key) => { try { return localStorage.getItem(key); } catch { return null; } },
    set: (key, value) => { try { localStorage.setItem(key, value); } catch { /* private mode */ } },
};

const wantsDark = (mode) => mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
const currentMode = () => storage.get('theme') || 'system';
const applyTheme = (mode = currentMode()) => {
    if (document.documentElement.classList.contains('dark') !== wantsDark(mode)) {
        document.documentElement.classList.toggle('dark', wantsDark(mode));
    }
};

document.cookie = `theme=${currentMode()}; path=/; max-age=31536000; samesite=lax`;

// wire:navigate replaces the <html> attributes with the new page's (which carry no theme). A MutationObserver callback runs as a
// microtask, before the browser paints, so the class is put back within the same frame and nothing flashes.
new MutationObserver(() => applyTheme()).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
document.addEventListener('livewire:navigated', () => applyTheme());
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme());

document.addEventListener('alpine:init', () => {
    // Light / dark / system, applied before first paint by the inline script in the layout.
    Alpine.store('theme', {
        mode: storage.get('theme') || 'system',
        set(mode) {
            this.mode = mode;
            storage.set('theme', mode);
            // Read by the server to render <html class="dark"> up front (the browser storage is not visible to it).
            document.cookie = `theme=${mode}; path=/; max-age=31536000; samesite=lax`;
            applyTheme(mode);
        },
        cycle() { this.set({ light: 'dark', dark: 'system', system: 'light' }[this.mode]); },
    });

    // Toasts (Pines-style): $dispatch('toast', { message, type }) from anywhere, or $this->dispatch('toast', ...) from Livewire.
    Alpine.store('toasts', {
        items: [],
        push({ message, type = 'default', description = '' }) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type, description });
            setTimeout(() => this.dismiss(id), 5000);
        },
        dismiss(id) { this.items = this.items.filter((t) => t.id !== id); },
    });

    // Confirm dialog: $store.confirm.ask({ title, message, action: () => $wire.delete(), destructive: true })
    Alpine.store('confirm', {
        open: false, title: '', message: '', label: 'Confirm', destructive: false, action: null,
        ask({ title, message = '', label = 'Confirm', destructive = false, action }) {
            Object.assign(this, { open: true, title, message, label, destructive, action });
        },
        accept() { this.open = false; this.action?.(); },
    });

    // Copy to clipboard with feedback.
    Alpine.data('copy', (text) => ({
        done: false,
        async copy() {
            try { await navigator.clipboard.writeText(text); } catch {
                const el = document.createElement('textarea'); el.value = text; document.body.append(el); el.select(); document.execCommand('copy'); el.remove();
            }
            this.done = true; setTimeout(() => (this.done = false), 1800);
        },
    }));

    // Keeps a scrolling container pinned to the bottom while content streams in, unless the reader scrolled up.
    Alpine.data('follow', () => ({
        stuck: true,
        init() {
            const el = this.$el;
            el.addEventListener('scroll', () => (this.stuck = el.scrollHeight - el.scrollTop - el.clientHeight < 80));
            new MutationObserver(() => this.stuck && el.scrollTo({ top: el.scrollHeight })).observe(el, { childList: true, subtree: true, characterData: true });
            el.scrollTo({ top: el.scrollHeight });
        },
    }));
});

window.addEventListener('toast', (event) => window.Alpine?.store('toasts').push(event.detail));
