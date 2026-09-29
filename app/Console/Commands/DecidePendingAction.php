<?php

namespace App\Console\Commands;

use App\Models\PendingAction;
use App\Ssh\ActionExecutor;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class DecidePendingAction extends Command
{
    protected $signature = 'sentinel:pending {id? : Pending action id to review} {--reject : Reject instead of approving}';

    protected $description = 'List pending corrective actions, or approve/reject one';

    public function handle(ActionExecutor $executor): int
    {
        if (! $this->argument('id')) {
            $this->table(['id', 'machine', 'action', 'risk', 'command', 'reason'], PendingAction::with('machine')->where('status', 'pending')->get()
                ->map(fn ($p) => [$p->id, $p->machine->name, $p->action, $p->risk, $p->command, $p->reason])->all());

            return self::SUCCESS;
        }

        $pending = PendingAction::with('machine')->findOrFail($this->argument('id'));

        if ($this->option('reject')) {
            $executor->reject($pending);
            $this->info('Rejected.');

            return self::SUCCESS;
        }

        $this->line("{$pending->machine->name} ({$pending->machine->environment}): {$pending->command}");

        if (confirm('Run this command now?', false)) {
            $this->line($executor->approve($pending));
        }

        return self::SUCCESS;
    }
}
