<?php

namespace App\Console\Commands;

use App\Monitoring\SiteMonitor;
use Illuminate\Console\Command;

class CheckSites extends Command
{
    protected $signature = 'sentinel:check-sites';

    protected $description = 'Check the public sites of every machine (HTTP status, response time, certificate expiry) and alert on changes';

    public function handle(SiteMonitor $monitor): int
    {
        $this->info($monitor->checkAll().' site(s) checked.');

        return self::SUCCESS;
    }
}
