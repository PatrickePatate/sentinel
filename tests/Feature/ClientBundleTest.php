<?php

use App\Models\Machine;
use App\Ssh\Provisioning\ClientBundle;
use App\Ssh\Provisioning\ProvisionScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bundleMachine(): Machine
{
    return Machine::factory()->create(['username' => 'sentinel', 'private_key' => Machine::generatePrivateKey()]);
}

function bundleFor(string $user = 'sentinel'): string
{
    config(['sentinel.actions.restartable_services' => ['nginx', 'php8.3-fpm'], 'sentinel.actions.reloadable_services' => ['nginx']]);

    return app(ClientBundle::class)->render($user);
}

/** Runs the real updater script in validation mode (installs nothing, needs no root). */
function validateBundle(string $bundle): array
{
    $updater = tempnam(sys_get_temp_dir(), 'upd');
    file_put_contents($updater, app(ClientBundle::class)->updaterBody());
    $result = Process::input($bundle)->run(['bash', $updater, '--validate']);
    unlink($updater);

    return [$result->successful(), $result->output().$result->errorOutput()];
}

/** Rebuilds a bundle with the sudoers policy replaced. */
function bundleWithSudoers(string $sudoers): string
{
    $bundle = bundleFor();

    return preg_replace_callback('/SUDOERS\n.*?\n\.\n/s', fn () => "SUDOERS\n".chunk_split(base64_encode($sudoers), 76, "\n").".\n", $bundle);
}

it('changes its version when a limit or a rule changes, and only then', function () {
    config(['sentinel.actions.restartable_services' => ['nginx']]);
    $bundle = app(ClientBundle::class);
    $before = $bundle->version('sentinel');

    expect($bundle->version('sentinel'))->toBe($before);

    config(['sentinel.actions.restartable_services' => ['nginx', 'redis-server']]);
    expect($bundle->version('sentinel'))->not->toBe($before);
});

it('is accepted by the updater it ships with', function () {
    [$ok, $output] = validateBundle(bundleFor());

    expect($ok)->toBeTrue($output)->and($output)->toContain('valid');
});

it('lets the updater accept every command the catalogs can ever ask for', function () {
    // The updater's allow-list is hard-coded on the machines: adding a sudo rule that it does not know must fail here, not in production.
    config(['sentinel.actions.restartable_services' => ['nginx', 'php8.3-fpm', 'my-app@1.service'], 'sentinel.actions.reloadable_services' => ['nginx', 'fail2ban']]);
    [$ok, $output] = validateBundle(app(ClientBundle::class)->render('sentinel'));

    expect($ok)->toBeTrue($output);
});

it('refuses a policy that grants more than the fixed command shapes', function (string $extra) {
    $sudoers = app(ClientBundle::class)->sudoers('sentinel');
    $evil = str_replace('    /usr/bin/apt-get clean', "    {$extra}, \\\n    /usr/bin/apt-get clean", $sudoers);

    expect($evil)->not->toBe($sudoers);
    [$ok, $output] = validateBundle(bundleWithSudoers($evil));

    expect($ok)->toBeFalse()->and($output)->toContain('not allowed');
})->with([
    'a shell' => '/bin/bash',
    'any systemctl verb' => '/usr/bin/systemctl edit -- nginx',
    'a wrapper outside sbin' => '/tmp/sentinel-evil *',
    'a package installer' => '/usr/bin/apt-get install *',
    'a free systemctl service' => '/usr/bin/systemctl restart -- nginx;reboot',
]);

it('refuses a grant for another user, a second grant, and root', function () {
    $sudoers = app(ClientBundle::class)->sudoers('sentinel');

    foreach ([
        $sudoers."root ALL=(ALL) NOPASSWD: ALL\n",
        $sudoers."sentinel ALL=(ALL) NOPASSWD: ALL\n",
        str_replace('Defaults:sentinel env_reset', 'Defaults:sentinel !authenticate', $sudoers),
    ] as $evil) {
        [$ok] = validateBundle(bundleWithSudoers($evil));
        expect($ok)->toBeFalse();
    }

    [$ok] = validateBundle(str_replace('USER sentinel', 'USER root', bundleFor()));
    expect($ok)->toBeFalse();
});

it('refuses wrappers that are not bash scripts, badly named, or that replace the updater', function (string $name, string $body) {
    $bundle = bundleFor().'';
    $bundle = str_replace("SUDOERS\n", "FILE {$name}\n".chunk_split(base64_encode($body), 76, "\n").".\nSUDOERS\n", $bundle);

    [$ok, $output] = validateBundle($bundle);

    expect($ok)->toBeFalse($output);
})->with([
    'not bash' => ['sentinel-x', "#!/usr/bin/python3\nprint(1)\n"],
    'name with a path' => ['sentinel-../../etc/passwd', "#!/usr/bin/env bash\n"],
    'outside the namespace' => ['backdoor', "#!/usr/bin/env bash\n"],
    'the updater itself' => ['sentinel-self-update', "#!/usr/bin/env bash\n"],
]);

it('puts the same bundle in the provisioning script as the updater later installs', function () {
    $machine = bundleMachine();
    config(['sentinel.actions.restartable_services' => ['nginx']]);
    $script = app(ProvisionScript::class)->render($machine);
    preg_match("/<<'SENTINEL_BUNDLE'\n(.*?)\nSENTINEL_BUNDLE/s", $script, $m);

    expect($m[1].PHP_EOL)->toBe(app(ClientBundle::class)->render($machine));
});

it('reports the host keys to the callback only when asked to', function () {
    $machine = bundleMachine();

    expect(app(ProvisionScript::class)->render($machine))->toContain('local callback=')->not->toContain('https://')
        ->and(app(ProvisionScript::class)->render($machine, 'https://sentinel.test/provision/1/tok/callback'))->toContain("local callback='https://sentinel.test/provision/1/tok/callback'");
});
