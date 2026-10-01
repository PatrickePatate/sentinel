<?php

use App\Livewire\Sites\Index;
use App\Models\Machine;
use App\Models\SiteCheck;
use App\Models\User;
use App\Monitoring\CertificateReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->mock(CertificateReader::class)->shouldReceive('expiry')->andReturn(null);
});

it('adds a site, checks it right away and lists it', function () {
    Http::fake(['*shop.test*' => Http::response('ok', 200)]);
    $machine = Machine::factory()->create(['name' => 'web-01']);

    Livewire::test(Index::class)->set(['machine_id' => $machine->id, 'url' => 'https://shop.test/', 'analyze_on_down' => true])->call('add')->assertHasNoErrors()
        ->assertSee('https://shop.test/')->assertSee('web-01')->assertSee('Up');

    $site = SiteCheck::first();
    expect($site->ok)->toBeTrue()->and($site->checked_at)->not->toBeNull()->and($site->analyze_on_down)->toBeTrue();
});

it('rejects bad URLs, duplicates and too many sites', function () {
    Http::fake();
    $machine = Machine::factory()->create();
    $add = fn (string $url) => Livewire::test(Index::class)->set(['machine_id' => $machine->id, 'url' => $url])->call('add');

    foreach (['ftp://x.test', 'javascript:alert(1)', 'not a url', 'https://'] as $bad) {
        $add($bad)->assertHasErrors('url');
    }

    $add('https://a.test')->assertHasNoErrors();
    $add('https://a.test')->assertHasErrors('url');

    foreach (range(1, 9) as $i) {
        SiteCheck::create(['machine_id' => $machine->id, 'url' => "https://s{$i}.test"]);
    }
    $add('https://over.test')->assertHasErrors('url');
});

it('shows a down site, toggles the analysis and removes the site', function () {
    Http::fake(['*' => Http::response('boom', 502)]);
    $machine = Machine::factory()->create(['webserver_enabled' => false]);
    $site = SiteCheck::create(['machine_id' => $machine->id, 'url' => 'https://shop.test']);

    $page = Livewire::test(Index::class)->call('checkNow', $site->id)->assertSee('Down')->assertSee('web server analysis');
    $page->call('toggleAnalysis', $site->id);
    expect($site->fresh()->analyze_on_down)->toBeFalse();

    $page->call('remove', $site->id);
    expect(SiteCheck::count())->toBe(0);
});

it('is for admins only', function () {
    auth()->logout();
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get(route('sites.index'))->assertForbidden();
});
