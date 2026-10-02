<?php

namespace App\Console\Commands;

use App\Monitoring\FleetDigest;
use App\Notifications\Notifier;
use Illuminate\Console\Command;

class SendDigest extends Command
{
    protected $signature = 'sentinel:digest {--days=7 : Period covered}';

    protected $description = 'Send the fleet digest (open issues, what changed, health warnings) to every channel that takes scan notifications';

    public function handle(FleetDigest $digest, Notifier $notifier): int
    {
        $notifier->digest($digest->build(now()->subDays((int) $this->option('days'))));
        $this->info('Digest sent.');

        return self::SUCCESS;
    }
}
