<?php

use App\Models\Machine;
use App\Ssh\Fake\FakeTransport;
use App\Ssh\ToolCatalog;
use Database\Seeders\FakeMachinesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['sentinel.transport' => 'fake', 'sentinel.fake.latency_ms' => 0]);
    $this->seed(FakeMachinesSeeder::class);
});

it('answers every catalog tool without a command-not-found', function () {
    $machine = Machine::where('name', 'app-prod-02')->first();
    $commands = collect(ToolCatalog::default()->all())->map(fn ($t) => $t->command(match ($t->name()) {
        'service_status' => ['service' => 'nginx'],
        'fail2ban_test_regex' => ['log' => 'sshd', 'failregex' => '^Failed from <HOST>'],
        default => [],
    }));

    foreach ($commands as $command) {
        expect((new FakeTransport)->run($machine, $command, 5)->exitCode)->not->toBe(127, $command);
    }
});

it('really fixes a restartable service and frees disk', function () {
    $transport = new FakeTransport;
    $machine = Machine::where('name', 'app-prod-02')->first();

    expect($transport->run($machine, 'systemctl --failed --no-pager', 5)->output)->toContain('php8.3-fpm');
    $transport->run($machine, "systemctl restart -- 'php8.3-fpm' 2>&1", 5);
    expect($transport->run($machine, 'systemctl --failed --no-pager', 5)->output)->not->toContain('php8.3-fpm')
        ->and($transport->run($machine, "systemctl restart -- 'redis-server' 2>&1", 5)->exitCode)->toBe(1);

    $full = Machine::where('name', 'db-prod-01')->first();
    $before = $transport->run($full, 'df -hT -x tmpfs -x devtmpfs', 5)->output;
    $transport->run($full, 'journalctl --vacuum-time=14d 2>&1', 5);
    $transport->run($full, 'apt-get clean 2>&1', 5);
    expect($transport->run($full, 'df -hT -x tmpfs -x devtmpfs', 5)->output)->not->toBe($before);
});

it('refuses unpinned or unreachable machines like production', function () {
    $machine = Machine::where('name', 'web-prod-01')->first();
    $machine->update(['host_key_fingerprint' => null]);
    expect(fn () => (new FakeTransport)->run($machine, 'free -m', 5))->toThrow(RuntimeException::class, 'not pinned');

    $down = Machine::where('name', 'legacy-01')->first();
    expect(fn () => (new FakeTransport)->run($down, 'free -m', 5))->toThrow(RuntimeException::class, 'timed out');
});
