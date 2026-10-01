<?php

namespace App\Ai;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * The provider APIs report tokens, never a price: the cost is estimated from the rates in config('sentinel.pricing').
 */
class CostEstimator
{
    /** @return float|null USD, or null when the model has no configured rate. */
    public function estimate(?string $model, TextUsage $usage): ?float
    {
        $rates = $model ? config("sentinel.pricing.{$model}") : null;

        if (! $rates) {
            return null;
        }

        $cacheRead = $usage->cacheReadInputTokens ?? 0;
        $cacheWrite = $usage->cacheWriteInputTokens ?? 0;
        $plainInput = max(0, $usage->inputTokens - $cacheRead - $cacheWrite);

        // Cached tokens are billed at their own rate when one is set, else as ordinary input.
        $cost = $plainInput * $rates['input']
            + $cacheRead * ($rates['cache_read'] ?? $rates['input'])
            + $cacheWrite * ($rates['cache_write'] ?? $rates['input'])
            + $usage->outputTokens * $rates['output'];

        return round($cost / 1_000_000, 6);
    }
}
