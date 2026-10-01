<?php

use App\Ai\Agents\PricingAgent;
use App\Ai\CostEstimator;
use App\Ai\PricingFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\Data\TextUsage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('stores sane prices for the models asked and drops the rest', function () {
    PricingAgent::fake([['prices' => [
        ['model' => 'model-a', 'input' => 3, 'output' => 15, 'cache_read' => 0.3, 'source_url' => 'https://example.com/pricing'],
        ['model' => 'model-b', 'input' => 99999, 'output' => 1, 'source_url' => 'https://example.com'],
        ['model' => 'unasked', 'input' => 1, 'output' => 2, 'source_url' => 'https://example.com'],
    ]]]);

    $found = app(PricingFetcher::class)->refresh(['model-a', 'model-b']);

    expect(array_keys($found))->toBe(['model-a'])
        ->and(app(PricingFetcher::class)->stored()['model-a']['output'])->toEqual(15);
});

it('estimates a cost from fetched prices but lets config win', function () {
    Storage::put('pricing.json', json_encode(['model-a' => ['input' => 3.0, 'output' => 15.0]]));
    $usage = new TextUsage(1_000_000, 1_000_000);

    expect(app(CostEstimator::class)->estimate('model-a', $usage))->toBe(18.0);

    config(['sentinel.pricing.model-a' => ['input' => 1, 'output' => 2]]);

    expect(app(CostEstimator::class)->estimate('model-a', $usage))->toBe(3.0)
        ->and(app(CostEstimator::class)->estimate('unknown', $usage))->toBeNull();
});
