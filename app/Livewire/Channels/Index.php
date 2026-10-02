<?php

namespace App\Livewire\Channels;

use App\Livewire\Concerns\AuthorizesAccess;
use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramWebhook;
use App\Notifications\TestNotification;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.app', ['title' => 'Notifications'])]
class Index extends Component
{
    use AuthorizesAccess;

    protected function requiredAbility(): string
    {
        return 'admin';
    }

    public function test(int $id): void
    {
        try {
            // Sent synchronously so a wrong token or address is reported right here.
            NotificationChannel::findOrFail($id)->notifyNow(new TestNotification);
            $this->dispatch('toast', message: 'Test notification sent', type: 'success');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'Sending failed', description: $e->getMessage(), type: 'error');
        }
    }

    public function registerWebhook(int $id): void
    {
        try {
            TelegramWebhook::register(NotificationChannel::where('type', 'telegram')->findOrFail($id));
            $this->dispatch('toast', message: 'Webhook registered', type: 'success');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'Registration failed', description: $e->getMessage(), type: 'error');
        }
    }

    public function delete(int $id): void
    {
        NotificationChannel::findOrFail($id)->delete();
    }

    public function render()
    {
        return view('livewire.channels.index', ['channels' => NotificationChannel::orderBy('name')->get()]);
    }
}
