<?php

namespace App\Console\Commands;

use App\Ssh\ActionExecutor;
use Illuminate\Console\Command;

class RunScheduledActions extends Command
{
    protected $signature = 'sentinel:run-scheduled-actions';

    protected $description = 'Run the actions approved for a maintenance window whose window has started';

    public function handle(ActionExecutor $executor): int
    {
        $this->info($executor->runScheduled().' scheduled action(s) run.');

        return self::SUCCESS;
    }
}
