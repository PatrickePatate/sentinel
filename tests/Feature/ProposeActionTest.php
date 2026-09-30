<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\Tools\ProposeActionTool;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use App\Ssh\CommandResult;
use App\Ssh\Gate\RiskGate;
use App\Ssh\SshTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Classification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function proposingExecutor(array &$commands): ActionExecutor
{
    $transport = new class($commands) implements SshTransport
    {
        public function __construct(private array &$commands) {}

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult('done', 0);
        }
    };

    return new ActionExecutor(ActionCatalog::default(), $transport, new RiskGate, new AuditTrail);
}

it('files a proposed fix for approval, linked to the run, without running it or asking the risk model', function () {
    Classification::fake()->preventStrayClassifications();
    $commands = [];
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'audit', 'status' => 'running']);

    $out = proposingExecutor($commands)->propose($machine, 'fail2ban_unban', ['jail' => 'sshd', 'ip' => '203.0.113.7'], $run, 'Office IP banned after typos.');

    $pending = PendingAction::sole();
    expect($out)->toStartWith("PROPOSED (#{$pending->id})")
        ->and($commands)->toBeEmpty()
        ->and($pending->agent_run_id)->toBe($run->id)
        ->and($pending->status)->toBe('pending')
        ->and($pending->reason)->toContain('Office IP banned')
        ->and(Activity::where('event', 'action_proposed')->count())->toBe(1);
});

it('runs a proposed fix only once a human approves it', function () {
    $commands = [];
    $machine = Machine::factory()->create();
    $executor = proposingExecutor($commands);

    $executor->propose($machine, 'clean_apt_cache', [], null, 'cache is 4 GB');
    $executor->approve(PendingAction::sole());

    expect($commands)->toBe(['apt-get clean 2>&1'])->and(PendingAction::sole()->status)->toBe('executed');
});

it('rejects invalid arguments and does not duplicate a pending proposal', function () {
    $commands = [];
    $machine = Machine::factory()->create();
    $executor = proposingExecutor($commands);

    expect($executor->propose($machine, 'fail2ban_unban', ['jail' => 'sshd', 'ip' => 'nope; rm -rf /'], null, 'x'))->toStartWith('ERROR')
        ->and($executor->propose($machine, 'clean_apt_cache', [], null, 'a'))->toStartWith('PROPOSED')
        ->and($executor->propose($machine, 'clean_apt_cache', [], null, 'b'))->toStartWith('ALREADY_PENDING')
        ->and(PendingAction::count())->toBe(1);
});

it('offers the agent a propose tool for every action that is not high risk', function () {
    $tools = collect((new SysadminAgent(Machine::factory()->create()))->tools())->map->name();

    expect($tools)->toContain('propose_clean_apt_cache', 'propose_fail2ban_unban', 'clean_apt_cache')
        ->and(collect((new SysadminAgent(Machine::factory()->create()))->tools())->whereInstanceOf(ProposeActionTool::class)->count())
        ->toBe(collect(ActionCatalog::default()->all())->filter(fn ($a) => $a->risk()->value !== 'high')->count());
});
