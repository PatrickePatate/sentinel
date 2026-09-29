<?php

use App\Models\AuditLog;
use App\Models\Machine;
use App\Ssh\CommandResult;
use App\Ssh\SafeExecutor;
use App\Ssh\SshTransport;
use App\Ssh\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fakeTransport(): object
{
    return new class implements SshTransport
    {
        public array $commands = [];

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult("ran\n", 0);
        }
    };
}

it('runs a catalog tool and audits it', function () {
    $transport = fakeTransport();
    $machine = Machine::factory()->create();

    $output = (new SafeExecutor(ToolCatalog::default(), $transport))->execute($machine, 'disk_usage');

    expect($output)->toBe("ran\n")
        ->and($transport->commands)->toBe(['df -hT -x tmpfs -x devtmpfs'])
        ->and(AuditLog::first()->status)->toBe('ok');
});

it('never runs unknown tools', function () {
    $transport = fakeTransport();
    $machine = Machine::factory()->create();

    $output = (new SafeExecutor(ToolCatalog::default(), $transport))->execute($machine, 'run_shell', ['command' => 'rm -rf /']);

    expect($output)->toStartWith('ERROR')
        ->and($transport->commands)->toBeEmpty()
        ->and(AuditLog::first()->status)->toBe('rejected');
});

it('rejects injection through tool arguments', function (string $service) {
    $transport = fakeTransport();
    $machine = Machine::factory()->create();

    $output = (new SafeExecutor(ToolCatalog::default(), $transport))->execute($machine, 'service_status', ['service' => $service]);

    expect($output)->toStartWith('ERROR')->and($transport->commands)->toBeEmpty();
})->with(['nginx; reboot', '$(id)', '--help', 'a b', '`id`', '']);

it('rejects arguments on argument-less tools', function () {
    $transport = fakeTransport();

    $output = (new SafeExecutor(ToolCatalog::default(), $transport))->execute(Machine::factory()->create(), 'disk_usage', ['x' => 1]);

    expect($output)->toStartWith('ERROR')->and($transport->commands)->toBeEmpty();
});

it('truncates huge outputs', function () {
    $transport = new class implements SshTransport
    {
        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            return new CommandResult(str_repeat('a', 50000), 0);
        }
    };

    $output = (new SafeExecutor(ToolCatalog::default(), $transport))->execute(Machine::factory()->create(), 'memory_usage');

    expect(strlen($output))->toBeLessThan(SafeExecutor::MAX_OUTPUT_BYTES + 50);
});

it('does not leak the private key when serialized', function () {
    expect(Machine::factory()->create()->toArray())->not->toHaveKey('private_key');
});
