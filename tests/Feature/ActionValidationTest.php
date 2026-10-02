<?php

use App\Ssh\ActionCatalog;
use App\Ssh\Actions\ActionTool;
use App\Ssh\Actions\RiskLevel;
use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Argument validation is the security boundary between the model and the shell: every action must turn
 * well-formed arguments into one escaped command and refuse everything else with InvalidToolArguments.
 */

beforeEach(function () {
    config([
        'sentinel.actions.use_sudo' => true,
        'sentinel.actions.package_allowlist' => ['nginx', 'curl', 'php*'],
        'sentinel.actions.restartable_services' => ['nginx', 'php8.3-fpm'],
        'sentinel.actions.reloadable_services' => ['nginx'],
    ]);
});

function catalogAction(string $name): ActionTool
{
    return ActionCatalog::default()->get($name);
}

/** Valid arguments for every action of the default catalog. */
function validActionArguments(): array
{
    return [
        'vacuum_journal' => [],
        'clean_apt_cache' => [],
        'refresh_package_lists' => [],
        'renew_certificates' => [],
        'reset_failed_unit' => ['service' => 'nginx'],
        'reload_service' => ['service' => 'nginx'],
        'restart_service' => ['service' => 'php8.3-fpm'],
        'fail2ban_unban' => ['jail' => 'sshd', 'ip' => '203.0.113.7'],
        'fail2ban_write_filter' => ['name' => 'nginx-wp-login', 'failregex' => '^<HOST> .* "POST /wp-login.php'],
        'fail2ban_create_jail' => ['name' => 'wp-login', 'filter' => 'nginx-wp-login', 'log' => 'nginx-access', 'maxretry' => 5, 'findtime' => 600, 'bantime' => 3600],
        'fail2ban_remove_custom' => ['name' => 'wp-login'],
        'update_package' => ['package' => 'nginx'],
        'install_security_package' => ['package' => 'fail2ban'],
        'harden_ssh' => ['permit_root_login' => 'prohibit-password', 'password_authentication' => 'no'],
        'start_crashed_service' => ['service' => 'php8.3-fpm'],
        'reload_web_config' => ['service' => 'nginx'],
        'rollback_web_config' => ['service' => 'nginx'],
        'remove_old_kernels' => [],
    ];
}

it('has valid arguments listed for every catalog action', function () {
    expect(array_keys(ActionCatalog::default()->all()))->toEqualCanonicalizing(array_keys(validActionArguments()));
});

it('builds a sudo command from valid arguments', function (string $name) {
    $action = catalogAction($name);

    expect($action->risk())->toBeInstanceOf(RiskLevel::class)
        ->and($action->command(validActionArguments()[$name]))->toContain('sudo -n ');

    if ($action instanceof RequiresSudo) {
        expect($action->sudoRules())->not->toBeEmpty()->each->toStartWith('/');
    }
})->with(fn () => array_keys(validActionArguments()));

it('drops sudo when it is disabled', function (string $name) {
    config(['sentinel.actions.use_sudo' => false]);

    expect(catalogAction($name)->command(validActionArguments()[$name]))->not->toContain('sudo');
})->with(fn () => array_keys(validActionArguments()));

it('refuses missing arguments', function (string $name) {
    catalogAction($name)->command([]);
})->throws(InvalidToolArguments::class)
    ->with(fn () => array_keys(array_filter(validActionArguments())));

it('refuses arguments to actions that take none', function (string $name) {
    catalogAction($name)->command(['extra' => 'x']);
})->throws(InvalidToolArguments::class)
    ->with(fn () => array_keys(array_filter(validActionArguments(), fn ($arguments) => $arguments === [])));

it('refuses non-scalar values in any argument', function (string $name, string $key) {
    catalogAction($name)->command([...validActionArguments()[$name], $key => ['nginx']]);
})->throws(InvalidToolArguments::class)
    ->with(function () {
        foreach (validActionArguments() as $name => $arguments) {
            foreach (array_keys($arguments) as $key) {
                yield "{$name}.{$key}" => [$name, $key];
            }
        }
    });

it('refuses or quotes shell metacharacters in every argument', function (string $name, string $key, string $payload) {
    try {
        $command = catalogAction($name)->command([...validActionArguments()[$name], $key => $payload]);
    } catch (InvalidToolArguments) {
        expect(true)->toBeTrue();

        return;
    }

    // Accepted values must only reach the shell as one single-quoted word.
    expect($command)->toContain(escapeshellarg($payload));
})->with(function () {
    $payloads = ['nginx; rm -rf /', 'nginx && id', '$(id)', '`id`', "nginx\nid", '--help', 'nginx | sh', "x' 'y"];

    foreach (validActionArguments() as $name => $arguments) {
        foreach (array_keys($arguments) as $key) {
            if (is_string($arguments[$key])) {
                foreach ($payloads as $i => $payload) {
                    yield "{$name}.{$key} #{$i}" => [$name, $key, $payload];
                }
            }
        }
    }
});

dataset('invalid arguments', [
    'service off the restart allowlist' => ['restart_service', ['service' => 'sshd']],
    'service off the reload allowlist' => ['reload_service', ['service' => 'php8.3-fpm']],
    'restart allowlist is exact, not a prefix' => ['restart_service', ['service' => 'nginx.service']],
    'unban: bad jail' => ['fail2ban_unban', ['jail' => 'ssh d', 'ip' => '203.0.113.7']],
    'unban: jail too long' => ['fail2ban_unban', ['jail' => str_repeat('a', 33), 'ip' => '203.0.113.7']],
    'unban: hostname instead of ip' => ['fail2ban_unban', ['jail' => 'sshd', 'ip' => 'example.com']],
    'unban: cidr instead of ip' => ['fail2ban_unban', ['jail' => 'sshd', 'ip' => '203.0.113.0/24']],
    'filter: uppercase name' => ['fail2ban_write_filter', ['name' => 'Nginx', 'failregex' => '^<HOST> x']],
    'filter: name with a path' => ['fail2ban_write_filter', ['name' => '../jail', 'failregex' => '^<HOST> x']],
    'filter: no <HOST>' => ['fail2ban_write_filter', ['name' => 'f', 'failregex' => '^bad login']],
    'filter: more than 5 lines' => ['fail2ban_write_filter', ['name' => 'f', 'failregex' => implode("\n", array_fill(0, 6, '^<HOST> x'))]],
    'filter: empty' => ['fail2ban_write_filter', ['name' => 'f', 'failregex' => '   ']],
    'jail: unknown log key' => ['fail2ban_create_jail', ['name' => 'j', 'filter' => 'f', 'log' => '/etc/shadow', 'maxretry' => 5, 'findtime' => 600, 'bantime' => 3600]],
    'jail: maxretry too low' => ['fail2ban_create_jail', ['name' => 'j', 'filter' => 'f', 'log' => 'sshd', 'maxretry' => 2, 'findtime' => 600, 'bantime' => 3600]],
    'jail: bantime too high' => ['fail2ban_create_jail', ['name' => 'j', 'filter' => 'f', 'log' => 'sshd', 'maxretry' => 5, 'findtime' => 600, 'bantime' => 604801]],
    'jail: numeric string' => ['fail2ban_create_jail', ['name' => 'j', 'filter' => 'f', 'log' => 'sshd', 'maxretry' => '5', 'findtime' => 600, 'bantime' => 3600]],
    'jail: trailing dash slug' => ['fail2ban_create_jail', ['name' => 'j-', 'filter' => 'f', 'log' => 'sshd', 'maxretry' => 5, 'findtime' => 600, 'bantime' => 3600]],
    'remove: path traversal' => ['fail2ban_remove_custom', ['name' => '../../etc']],
    'update: off the allowlist' => ['update_package', ['package' => 'vim']],
    'update: on the denylist despite the allowlist' => ['update_package', ['package' => 'openssl'], ['*']],
    'update: denylisted kernel' => ['update_package', ['package' => 'linux-image-generic'], ['*']],
    'update: denylisted ssh' => ['update_package', ['package' => 'openssh-server'], ['*']],
    'update: uppercase name' => ['update_package', ['package' => 'Nginx']],
    'update: option-looking name' => ['update_package', ['package' => '-y']],
    'install: not installable' => ['install_security_package', ['package' => 'netcat']],
    'harden: unknown value' => ['harden_ssh', ['permit_root_login' => 'yes', 'password_authentication' => 'no']],
    'harden: nothing to change' => ['harden_ssh', ['permit_root_login' => 'keep', 'password_authentication' => 'keep']],
    'harden: root key login is never disabled' => ['harden_ssh', ['permit_root_login' => 'no', 'password_authentication' => 'keep']],
    'start: option-looking unit' => ['start_crashed_service', ['service' => '-nginx']],
    'start: unit with a slash' => ['start_crashed_service', ['service' => '../nginx']],
    'start: unit too long' => ['start_crashed_service', ['service' => str_repeat('a', 65)]],
    'reload web config: unknown service' => ['reload_web_config', ['service' => 'sshd']],
    'rollback web config: unknown service' => ['rollback_web_config', ['service' => 'mysql']],
]);

it('refuses invalid arguments', function (string $name, array $arguments, ?array $allowlist = null) {
    if ($allowlist !== null) {
        config(['sentinel.actions.package_allowlist' => $allowlist]);
    }

    catalogAction($name)->command($arguments);
})->throws(InvalidToolArguments::class)->with('invalid arguments');

it('fails closed when the package allowlist is empty', function () {
    config(['sentinel.actions.package_allowlist' => []]);

    catalogAction('update_package')->command(['package' => 'nginx']);
})->throws(InvalidToolArguments::class);

it('fails closed when the restart allowlist is empty', function () {
    config(['sentinel.actions.restartable_services' => []]);

    catalogAction('restart_service')->command(['service' => 'nginx']);
})->throws(InvalidToolArguments::class);

it('base64-encodes fail2ban filter regexes so they never reach the shell raw', function () {
    $regex = '^<HOST> .* "$(id)"';
    $command = catalogAction('fail2ban_write_filter')->command(['name' => 'f', 'failregex' => $regex]);

    expect($command)->not->toContain('$(id)')->toContain(base64_encode($regex));
});
