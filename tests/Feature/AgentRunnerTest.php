<?php

use App\Agent\AgentRunner;
use App\Agent\LlmClient;
use App\Agent\LlmManager;
use App\Agent\LlmResponse;
use App\Agent\ToolCall;
use App\Models\Machine;
use App\Ssh\CommandResult;
use App\Ssh\SafeExecutor;
use App\Ssh\SshTransport;
use App\Ssh\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('loops over tool calls until the model reports', function () {
    $llm = new class implements LlmClient
    {
        public int $calls = 0;

        public function name(): string
        {
            return 'fake';
        }

        public function chat(string $system, array $messages, array $tools): LlmResponse
        {
            return $this->calls++ === 0
                ? new LlmResponse('', [new ToolCall('1', 'disk_usage', []), new ToolCall('2', 'rm_everything', [])])
                : new LlmResponse('Report: disk ok.');
        }
    };
    $manager = Mockery::mock(LlmManager::class);
    $manager->shouldReceive('driver')->andReturn($llm);

    $transport = new class implements SshTransport
    {
        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            return new CommandResult('/dev/sda1 40%', 0);
        }
    };
    $catalog = ToolCatalog::default();

    $run = (new AgentRunner($manager, new SafeExecutor($catalog, $transport), $catalog))
        ->run(Machine::factory()->create(), 'check disks');

    expect($run->status)->toBe('completed')
        ->and($run->report)->toBe('Report: disk ok.')
        ->and($run->machine->auditLogs()->pluck('status')->all())->toBe(['ok', 'rejected']);
});
