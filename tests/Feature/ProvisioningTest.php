<?php

use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\ActionCatalog;
use App\Ssh\PhpseclibTransport;
use App\Ssh\Provisioning\ProvisionScript;
use App\Ssh\Provisioning\RevokeScript;
use App\Ssh\Provisioning\SudoersBuilder;
use App\Ssh\SafeExecutor;
use App\Ssh\SshTransport;
use App\Ssh\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use phpseclib3\Crypt\EC;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function provisionedMachine(): Machine
{
    return Machine::factory()->create([
        'username' => 'sentinel',
        'private_key' => EC::createKey('Ed25519')->toString('OpenSSH'),
    ]);
}

function builder(): SudoersBuilder
{
    config(['sentinel.actions.restartable_services' => ['nginx', 'php8.3-fpm'], 'sentinel.actions.reloadable_services' => ['nginx']]);

    return new SudoersBuilder(ToolCatalog::default(), ActionCatalog::default());
}

it('derives sudoers rules from the catalogs, with absolute paths and no wildcard except validating wrappers', function () {
    $rules = builder()->rules();

    expect($rules)->toContain('/usr/bin/apt-get clean', '/usr/bin/systemctl restart -- nginx', '/usr/bin/systemctl reload -- nginx', '/usr/bin/systemctl reset-failed -- php8.3-fpm', '/usr/sbin/sshd -T')
        ->and($rules)->not->toContain('/usr/bin/systemctl restart -- mysql');

    foreach ($rules as $rule) {
        expect($rule)->toStartWith('/');

        if (str_contains($rule, '*')) {
            expect($rule)->toStartWith('/usr/local/sbin/sentinel-')->toEndWith(' *');
        }
    }
});

it('never grants a shell, a package installer or a generic systemctl', function () {
    $sudoers = builder()->render('sentinel');

    foreach (['ALL=(ALL)', 'NOPASSWD: ALL', '/bin/bash', '/bin/sh', 'apt-get install', 'apt-get upgrade', 'systemctl *', 'fail2ban-client'] as $forbidden) {
        expect($sudoers)->not->toContain($forbidden);
    }
});

it('refuses unsafe user names', function (string $user) {
    builder()->render($user);
})->with(['root', 'a b', 'x;reboot', '', 'Sentinel'])->throws(InvalidArgumentException::class);

it('renders a script with valid bash syntax and a restricted key', function () {
    config(['sentinel.provisioning.source_ips' => ['203.0.113.9', '2001:db8::/64']]);
    $machine = provisionedMachine();
    $script = app(ProvisionScript::class)->render($machine);
    $file = tempnam(sys_get_temp_dir(), 'prov');
    file_put_contents($file, $script);

    expect(Process::run(['bash', '-n', $file])->successful())->toBeTrue()
        ->and($script)->toContain('set -euo pipefail', 'visudo -cf', "usermod -p '*' sentinel", 'restrict,from="203.0.113.9,2001:db8::/64" ssh-ed25519', 'ignoreip = 127.0.0.1/8 ::1 203.0.113.9 2001:db8::/64')
        ->and($script)->not->toContain('PRIVATE KEY');
    unlink($file);
});

it('rejects source IPs that are not addresses or CIDR ranges', function (string $entry) {
    config(['sentinel.provisioning.source_ips' => ['203.0.113.9', $entry]]);

    app(ProvisionScript::class)->render(provisionedMachine());
})->with(['10.0.0.0/8; reboot', '10.0.0.0/33', '2001:db8::/129', '"; command="id', 'example.com', '*'])->throws(InvalidArgumentException::class, 'SENTINEL_SOURCE_IPS');

it('accepts the key from anywhere when no source IP is configured', function () {
    config(['sentinel.provisioning.source_ips' => []]);

    $script = app(ProvisionScript::class)->render(provisionedMachine());

    expect($script)->toContain("'restrict ssh-ed25519")->not->toContain('from=');
});

it('generates a sudoers file that visudo accepts', function () {
    if (Process::run('command -v visudo')->failed()) {
        $this->markTestSkipped('visudo not installed');
    }

    $file = tempnam(sys_get_temp_dir(), 'sudoers');
    file_put_contents($file, builder()->render('sentinel'));
    $check = Process::run(['visudo', '-cf', $file]);
    unlink($file);

    expect($check->successful())->toBeTrue($check->output().$check->errorOutput());
});

it('ships wrappers that reject malicious arguments before doing anything', function (string $wrapper, array $args, int $expectedExit) {
    config(['sentinel.actions.package_allowlist' => ['zz-nonexistent-pkg', 'openssh-*', 'linux-*']]);
    $file = tempnam(sys_get_temp_dir(), 'wrapper');
    file_put_contents($file, app(ProvisionScript::class)->wrapperBody($wrapper));
    chmod($file, 0755);

    $result = Process::run(['bash', $file, ...$args]);
    unlink($file);

    expect($result->exitCode())->toBe($expectedExit);
})->with([
    'upgrade: not on allowlist' => ['sentinel-upgrade-package', ['curl'], 3],
    'upgrade: allowlisted but not installed' => ['sentinel-upgrade-package', ['zz-nonexistent-pkg'], 4],
    'upgrade: injection' => ['sentinel-upgrade-package', ['curl; reboot'], 2],
    'upgrade: option' => ['sentinel-upgrade-package', ['--purge'], 2],
    'upgrade: two args' => ['sentinel-upgrade-package', ['curl', 'wget'], 2],
    'upgrade: protected' => ['sentinel-upgrade-package', ['openssh-server'], 3],
    'upgrade: protected glob' => ['sentinel-upgrade-package', ['linux-image-6.8.0'], 3],
    'unban: bad jail' => ['sentinel-fail2ban-unban', ['sshd; id', '1.2.3.4'], 2],
    'unban: bad ip' => ['sentinel-fail2ban-unban', ['sshd', '1.2.3.4; id'], 2],
    'unban: too few' => ['sentinel-fail2ban-unban', ['sshd'], 2],
    'filter: bad name' => ['sentinel-fail2ban-filter', ['../etc/passwd'], 2],
    'jail: bad name' => ['sentinel-fail2ban-jail', ['a b', 'f', 'sshd', '5', '600', '3600'], 2],
    'jail: filter missing' => ['sentinel-fail2ban-jail', ['ok', 'nofilter', 'sshd', '5', '600', '3600'], 4],
    'remove: traversal' => ['sentinel-fail2ban-remove', ['../../etc/shadow'], 2],
]);

it('renders every wrapper as valid bash', function (string $wrapper) {
    $file = tempnam(sys_get_temp_dir(), 'wrapper');
    file_put_contents($file, app(ProvisionScript::class)->wrapperBody($wrapper));

    expect(Process::run(['bash', '-n', $file])->successful())->toBeTrue();
    unlink($file);
})->with(['sentinel-upgrade-package', 'sentinel-fail2ban-unban', 'sentinel-fail2ban-filter', 'sentinel-fail2ban-jail', 'sentinel-fail2ban-remove']);

it('validates fail2ban filter payloads in the wrapper before writing anything', function (string $payload, int $expectedExit) {
    $file = tempnam(sys_get_temp_dir(), 'wrapper');
    file_put_contents($file, app(ProvisionScript::class)->wrapperBody('sentinel-fail2ban-filter'));

    $result = Process::input(base64_encode($payload))->run(['bash', $file, 'my-filter']);
    unlink($file);

    expect($result->exitCode())->toBe($expectedExit, $result->errorOutput());
})->with([
    'interpolation' => ['^%(__prefix_line)s <HOST>', 2],
    'no HOST' => ['^nothing$', 2],
    'leading space' => [' ^<HOST> x', 2],
    'six lines' => [implode("\n", array_fill(0, 6, '^<HOST> x')), 2],
    'empty line in the middle' => ["^<HOST> x\n\n^<HOST> y", 2],
    'control char' => ["^<HOST> \x01", 2],
]);

it('revokes a machine: blocks every SSH path locally, cancels pending actions and audits it', function () {
    config(['sentinel.transport' => 'fake']);
    $machine = provisionedMachine();
    $pending = PendingAction::create([
        'machine_id' => $machine->id, 'action' => 'clean_apt_cache', 'arguments' => [], 'command' => 'x', 'risk' => 'low', 'reason' => 'r',
    ]);

    $this->artisan('sentinel:revoke', ['machine' => $machine->id])->assertSuccessful();

    $machine->refresh();
    expect($machine->isRevoked())->toBeTrue()
        ->and($machine->autonomy_enabled)->toBeFalsy()
        ->and($pending->refresh()->status)->toBe('rejected')
        ->and(Activity::where('event', 'machine_revoked')->count())->toBe(1)
        ->and(fn () => app(SshTransport::class)->run($machine, 'true', 5))->toThrow(RuntimeException::class, 'revoked');

    $output = app(SafeExecutor::class)->execute($machine, 'disk_usage');
    expect($output)->toStartWith('ERROR');

    $this->artisan('sentinel:revoke', ['machine' => $machine->id, '--lift' => true])->assertSuccessful();
    expect($machine->refresh()->isRevoked())->toBeFalse();
});

it('renders a revoke script that closes the door before killing sessions and is valid bash', function () {
    $machine = provisionedMachine();
    $script = app(RevokeScript::class);

    foreach ([false, true] as $purge) {
        $body = $script->render($machine, $purge);
        $path = tempnam(sys_get_temp_dir(), 'revoke');
        file_put_contents($path, $body);
        expect(Process::run(['bash', '-n', $path])->successful())->toBeTrue();
        unlink($path);
    }

    $plain = $script->render($machine);
    expect(strpos($plain, 'authorized_keys'))->toBeLessThan(strpos($plain, 'terminate-user'))
        ->and(strpos($plain, '/etc/sudoers.d/sentinel'))->toBeLessThan(strpos($plain, 'pkill'))
        ->and($plain)->not->toContain('userdel')->not->toContain('fail2ban')
        ->and($script->render($machine, true))->toContain('userdel -r sentinel');
});

it('writes the provisioning script of a Sentinel-generated key to a private file, valid bash, with the public half only', function () {
    config(['sentinel.provisioning.source_ips' => ['203.0.113.7', '2001:db8::/64']]);
    $machine = Machine::factory()->create(['username' => 'sentinel', 'private_key' => Machine::generatePrivateKey()]);
    $path = tempnam(sys_get_temp_dir(), 'prov');

    $this->artisan('sentinel:provision', ['machine' => $machine->id, '--output' => $path])->assertSuccessful();

    $script = file_get_contents($path);
    expect(Process::run(['bash', '-n', $path])->successful())->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0700')
        ->and($script)->toContain($machine->publicKey(), 'restrict,from="203.0.113.7,2001:db8::/64"', 'AllowUsers')
        ->not->toContain('PRIVATE KEY');

    unlink($path);
});

it('explains the SSH failure with the user, host and a pointer to the diagnostic command', function () {
    config(['sentinel.transport' => 'ssh']);
    $machine = Machine::factory()->create(['host' => '127.0.0.1', 'port' => 1, 'private_key' => Machine::generatePrivateKey()]);

    try {
        (new PhpseclibTransport)->run($machine, 'true', 3);
        $this->fail('should not connect');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('127.0.0.1');
    }

    $this->artisan('sentinel:check', ['machine' => $machine->id])->expectsOutputToContain('Cannot reach')->assertFailed();
});

it('does not pretend to check anything with the fake transport', function () {
    config(['sentinel.transport' => 'fake']);

    $this->artisan('sentinel:check', ['machine' => Machine::factory()->create()->id])->expectsOutputToContain('nothing to check')->assertSuccessful();
});
