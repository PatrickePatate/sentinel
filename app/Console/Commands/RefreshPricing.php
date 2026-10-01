<?php

namespace App\Console\Commands;

use App\Ai\PricingFetcher;
use Illuminate\Console\Command;
use Throwable;

class RefreshPricing extends Command
{
    protected $signature = 'sentinel:pricing {model?* : Models to price (default: every model in use without a rate in config)}';

    protected $description = 'Look the API prices of the models in use up on the web (with the agent) to estimate run costs';

    public function handle(PricingFetcher $fetcher): int
    {
        try {
            $found = $fetcher->refresh($this->argument('model') ?: null);
        } catch (Throwable $e) {
            $this->error("Could not fetch the prices: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($found === []) {
            $this->info('Nothing to price: every model in use already has a rate in config.');
        }

        foreach ($found as $model => $rates) {
            $this->line(sprintf('%s: $%s in / $%s out per million tokens (%s)', $model, $rates['input'], $rates['output'], $rates['source_url'] ?? 'no source'));
        }

        return self::SUCCESS;
    }
}
