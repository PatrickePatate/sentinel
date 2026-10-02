<?php

use App\Ssh\ActionCatalog;
use App\Ssh\Provisioning\ClientBundle;
use App\Ssh\Provisioning\SudoersBuilder;
use App\Ssh\ToolCatalog;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Runs the kernel cleanup wrapper with uname, dpkg-query and apt-get replaced by stubs.
 *
 * @param  list<string>  $installed  Package names dpkg reports as installed.
 * @param  string  $simulation  What "apt-get -s purge" prints.
 * @return array{exit: int, output: string, purged: string}
 */
function kernelCleanup(string $running, array $installed, ?string $simulation = null): array
{
    $dir = sys_get_temp_dir().'/sentinel-kernel-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/installed", implode("\n", $installed)."\n");

    $stubs = [
        'uname' => "echo {$running}",
        // dpkg-query -W -f=... <pattern-or-name>: list installed packages matching the last argument (a shell glob).
        'dpkg-query' => 'pattern=${@: -1}; while read -r p; do [[ $p == $pattern ]] || continue; case "$*" in *Package*) echo "$p install ok installed";; *) echo -n "install ok installed";; esac; done < '.$dir.'/installed',
        'apt-get' => 'if [[ $1 == -s ]]; then '.($simulation !== null ? 'cat '.$dir.'/sim' : 'shift 2; for p in "$@"; do [[ $p == -- ]] || echo "Purg $p [1]"; done').'; else echo "$*" > '.$dir.'/purged; fi',
        'df' => 'echo "/dev/sda1 1G 100M 900M 10% /boot"',
    ];

    if ($simulation !== null) {
        file_put_contents("{$dir}/sim", $simulation);
    }

    foreach ($stubs as $name => $body) {
        file_put_contents("{$dir}/{$name}", "#!/usr/bin/env bash\n{$body}\n");
        chmod("{$dir}/{$name}", 0755);
    }

    $script = str_replace('PATH=/usr/sbin:/usr/bin:/sbin:/bin', "PATH={$dir}:/usr/bin:/bin", app(ClientBundle::class)->wrappers()['sentinel-kernel-cleanup']);
    file_put_contents("{$dir}/wrapper", $script);

    $result = Process::run(['bash', "{$dir}/wrapper"]);
    $purged = is_file("{$dir}/purged") ? trim(file_get_contents("{$dir}/purged")) : '';
    Process::run(['rm', '-rf', $dir]);

    return ['exit' => $result->exitCode(), 'output' => $result->output().$result->errorOutput(), 'purged' => $purged];
}

it('keeps the running and the newest kernel and purges the others', function () {
    $result = kernelCleanup('6.1.0-20-amd64', [
        'linux-image-6.1.0-18-amd64', 'linux-headers-6.1.0-18-amd64',
        'linux-image-6.1.0-20-amd64',
        'linux-image-6.1.0-21-amd64',
    ]);

    expect($result['exit'])->toBe(0)
        ->and($result['purged'])->toBe('purge -y -- linux-image-6.1.0-18-amd64 linux-headers-6.1.0-18-amd64')
        ->and($result['output'])->toContain('keeping 6.1.0-20-amd64 (running) and 6.1.0-21-amd64');
});

it('keeps a fallback kernel when the newest one is running', function () {
    $result = kernelCleanup('6.1.0-21-amd64', ['linux-image-6.1.0-18-amd64', 'linux-image-6.1.0-20-amd64', 'linux-image-6.1.0-21-amd64']);

    expect($result['purged'])->toBe('purge -y -- linux-image-6.1.0-18-amd64');
});

it('does nothing when only the kept kernels are installed', function () {
    $result = kernelCleanup('6.1.0-21-amd64', ['linux-image-6.1.0-20-amd64', 'linux-image-6.1.0-21-amd64']);

    expect($result['exit'])->toBe(0)->and($result['purged'])->toBe('')->and($result['output'])->toContain('nothing to remove');
});

it('refuses when apt would remove anything else', function (string $simulation) {
    $result = kernelCleanup('6.1.0-20-amd64', ['linux-image-6.1.0-18-amd64', 'linux-image-6.1.0-20-amd64', 'linux-image-6.1.0-21-amd64'], $simulation);

    expect($result['exit'])->toBe(6)->and($result['purged'])->toBe('');
})->with([
    'another package' => ["Purg linux-image-6.1.0-18-amd64 [1]\nRemv linux-image-amd64 [2]\n"],
    'an install' => ["Purg linux-image-6.1.0-18-amd64 [1]\nInst linux-image-6.1.0-22-amd64 (x)\n"],
]);

it('refuses when the running kernel is not an installed package', function () {
    $result = kernelCleanup('6.9.0-custom', ['linux-image-6.1.0-18-amd64', 'linux-image-6.1.0-20-amd64', 'linux-image-6.1.0-21-amd64']);

    expect($result['exit'])->toBe(4)->and($result['purged'])->toBe('');
});

it('takes no argument', function () {
    expect(fn () => ActionCatalog::default()->get('remove_old_kernels')->command(['all' => true]))->toThrow(InvalidToolArguments::class);
});

it('ships the new wrappers in the bundle with a sudo rule each', function () {
    $sudoers = app(SudoersBuilder::class)->render('sentinel');

    expect(app(ClientBundle::class)->wrappers())->toHaveKeys(['sentinel-account-audit', 'sentinel-kernel-cleanup'])
        ->and($sudoers)->toContain('/usr/local/sbin/sentinel-account-audit *')->toContain('/usr/local/sbin/sentinel-kernel-cleanup *');
});

it('offers the account audit and failed jobs as read-only tools', function () {
    config(['sentinel.actions.use_sudo' => true]);

    expect(ToolCatalog::default()->get('account_audit')->command([]))->toBe('sudo -n /usr/local/sbin/sentinel-account-audit 2>&1')
        ->and(ToolCatalog::default()->get('failed_jobs')->command([]))->not->toContain('sudo');
});

it('never prints the SSH keys themselves in the account audit', function () {
    $wrapper = app(ClientBundle::class)->wrappers()['sentinel-account-audit'];

    expect($wrapper)->toContain('ssh-keygen -lf')->not->toMatch('/cat\s+"?\$file/');
});
