<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class HardenSshAction implements ActionTool, RequiresSudo, Verifiable
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-sshd-harden';

    public function name(): string
    {
        return 'harden_ssh';
    }

    public function description(): string
    {
        return 'Disable SSH root login and/or password authentication through a Sentinel drop-in file, then reload sshd (open sessions stay up). '
            .'If root logs in with a key and no other admin has one, use permit_root_login=prohibit-password (\"no\" would lock root out and is refused). '
            .'Refuses when no administrator other than Sentinel has an SSH key, and reverts if sshd rejects the change or another setting overrides it. Always needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'permit_root_login' => $schema->string()->enum(['no', 'prohibit-password', 'keep'])->description('no: root cannot log in; prohibit-password: root only with a key; keep: unchanged')->required(),
            'password_authentication' => $schema->string()->enum(['no', 'keep'])->description('no: disable password and keyboard-interactive logins; keep: unchanged')->required(),
        ];
    }

    public function command(array $arguments): string
    {
        $rootLogin = $arguments['permit_root_login'] ?? null;
        $passwordAuth = $arguments['password_authentication'] ?? null;

        if (! in_array($rootLogin, ['no', 'prohibit-password', 'keep'], true) || ! in_array($passwordAuth, ['no', 'keep'], true)) {
            throw new InvalidToolArguments('Invalid SSH hardening values.');
        }

        if ($rootLogin === 'keep' && $passwordAuth === 'keep') {
            throw new InvalidToolArguments('Nothing to change.');
        }

        return $this->sudo().self::WRAPPER.' '.escapeshellarg($rootLogin).' '.escapeshellarg($passwordAuth).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }

    /** sshd -T prints the effective settings: what was asked must be what sshd now applies. */
    public function verification(array $arguments): ?Verification
    {
        $expected = array_filter(['permitrootlogin' => $arguments['permit_root_login'], 'passwordauthentication' => $arguments['password_authentication']], fn ($v) => $v !== 'keep');
        $expected = array_map(fn ($v) => $v === 'prohibit-password' ? ['prohibit-password', 'without-password'] : [$v], $expected);

        return new Verification(
            $this->sudo().'sshd -T 2>/dev/null | grep -Ei "^(permitrootlogin|passwordauthentication) "',
            function ($r) use ($expected) {
                $settings = collect(preg_split('/\R/', trim($r->output)))->mapWithKeys(fn ($line) => [strtolower(strtok($line, ' ')) => strtolower(trim((string) strtok('')))]);

                return collect($expected)->every(fn ($values, $key) => in_array($settings->get($key), $values, true));
            },
            'sshd applies '.collect($expected)->map(fn ($v, $k) => "{$k} {$v[0]}")->implode(' and '),
        );
    }
}
