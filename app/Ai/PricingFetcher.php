<?php

namespace App\Ai;

use App\Ai\Agents\PricingAgent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;
use RuntimeException;

/**
 * Keeps the model price list up to date by asking an agent with web search. The answer is untrusted: it is
 * sanity-checked, only kept for models we asked about, and never overrides the rates set by hand in config.
 */
class PricingFetcher
{
    public const FILE = 'pricing.json';

    /** Highest believable price, USD per million tokens: anything above is a misread page. */
    private const MAX_RATE = 500;

    /** @return list<string> Every model Sentinel uses or has used, that has no rate set by hand. */
    public function models(): array
    {
        $provider = config('sentinel.agent.provider');
        $models = collect([
            config('sentinel.agent.model'),
            config('sentinel.agent.scheduled_model'),
            config('sentinel.agent.model') ? null : Ai::textProvider($provider)->defaultTextModel(),
        ])->merge(AgentRun::whereNotNull('model')->distinct()->pluck('model'));

        return $models->filter()->unique()->reject(fn ($m) => config("sentinel.pricing.{$m}"))->values()->all();
    }

    /**
     * @param  list<string>|null  $models
     * @return array<string, array<string, mixed>> The rates saved.
     */
    public function refresh(?array $models = null): array
    {
        $models ??= $this->models();

        if ($models === []) {
            return [];
        }

        $response = (new PricingAgent)->prompt(
            "Models: \n- ".implode("\n- ", $models),
            provider: config('sentinel.agent.provider'),
            model: config('sentinel.agent.model'),
        );

        $found = [];

        foreach ($response['prices'] ?? [] as $row) {
            $rates = $this->validated($row);

            if ($rates && in_array($row['model'], $models, true)) {
                $found[$row['model']] = $rates + ['source_url' => $row['source_url'] ?? null, 'fetched_at' => now()->toIso8601String()];
            }
        }

        if ($found === [] && $models !== []) {
            throw new RuntimeException('The agent returned no usable price.');
        }

        Storage::put(self::FILE, json_encode($found + $this->stored(), JSON_PRETTY_PRINT));

        return $found;
    }

    /** @return array<string, array<string, mixed>> */
    public function stored(): array
    {
        return Storage::exists(self::FILE) ? (json_decode(Storage::get(self::FILE), true) ?: []) : [];
    }

    /** @return array<string, float>|null */
    private function validated(array $row): ?array
    {
        $rates = [];

        foreach (['input', 'output', 'cache_read', 'cache_write'] as $key) {
            $value = $row[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_numeric($value) || $value < 0 || $value > self::MAX_RATE) {
                return null;
            }

            $rates[$key] = (float) $value;
        }

        return isset($rates['input'], $rates['output']) ? $rates : null;
    }
}
