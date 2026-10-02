<?php

namespace App\Ssh\Provisioning;

use App\Models\Machine;

/**
 * What runs on a machine on Sentinel's behalf: root-owned wrappers and the sudoers policy, plus the updater
 * that installs them. The very same bundle is installed by the provisioning script and by a later remote update.
 */
class ClientBundle
{
    public const VERSION_FILE = '/usr/local/share/sentinel/version';

    public const UPDATER_PATH = '/usr/local/sbin/sentinel-self-update';

    public const WRAPPERS = [
        'sentinel-upgrade-package',
        'sentinel-fail2ban-unban',
        'sentinel-fail2ban-filter',
        'sentinel-fail2ban-jail',
        'sentinel-fail2ban-remove',
        'sentinel-sshd-harden',
        'sentinel-install-package',
        'sentinel-service-recover',
        'sentinel-web-config',
        'sentinel-account-audit',
        'sentinel-kernel-cleanup',
    ];

    public function __construct(private SudoersBuilder $sudoers, private BundleSigner $signer) {}

    /** @return array<string, string> wrapper name => body */
    public function wrappers(): array
    {
        $wrappers = [];

        foreach (self::WRAPPERS as $name) {
            $wrappers[$name] = rtrim($this->wrapperBody($name))."\n";
        }

        return $wrappers;
    }

    public function sudoers(string $user): string
    {
        return $this->sudoers->render($user);
    }

    /** The bundle text the updater reads on stdin, signed (see resources/provisioning/sentinel-self-update.sh). */
    public function render(Machine|string $machine): string
    {
        return $this->sign($this->unsigned($machine));
    }

    /** Appends the signature the updater checks before reading anything else of the bundle. */
    public function sign(string $bundle): string
    {
        return $bundle.$this->signer->sign($bundle);
    }

    public function unsigned(Machine|string $machine): string
    {
        $user = $machine instanceof Machine ? $machine->username : $machine;
        SudoersBuilder::assertValidUser($user);

        $out = "SENTINEL-BUNDLE 1\nUSER {$user}\nVERSION {$this->version($user)}\n";

        foreach ($this->wrappers() as $name => $body) {
            $out .= "FILE {$name}\n".chunk_split(base64_encode($body), 76, "\n").".\n";
        }

        return $out."SUDOERS\n".chunk_split(base64_encode($this->sudoers($user)), 76, "\n").".\nEND\n";
    }

    /** Identifies the content of the bundle: it changes whenever a wrapper, a limit or a sudo rule changes. */
    public function version(string $user): string
    {
        $content = $this->sudoers($user);

        foreach ($this->wrappers() as $name => $body) {
            $content .= "\0{$name}\0{$body}";
        }

        return substr(hash('sha256', $content), 0, 12);
    }

    public function updaterBody(): string
    {
        return strtr($this->updaterTemplate(), ['__UPDATER_VERSION__' => $this->updaterVersion()]);
    }

    /**
     * The updater is not updatable remotely: when this changes (its code, or the signing key it trusts), the machine
     * needs the provisioning one-liner again.
     */
    public function updaterVersion(): string
    {
        return substr(hash('sha256', $this->updaterTemplate()), 0, 12);
    }

    private function updaterTemplate(): string
    {
        return strtr(file_get_contents(resource_path('provisioning/sentinel-self-update.sh')), ['__ALLOWED_SIGNER__' => $this->signer->allowedSigner()]);
    }

    /** The content of the version file a machine on this bundle carries. */
    public function versionLine(string $user): string
    {
        return "bundle={$this->version($user)} updater={$this->updaterVersion()}";
    }

    private function wrapperBody(string $wrapper): string
    {
        $body = file_get_contents(resource_path("provisioning/{$wrapper}.sh"));

        $logCases = collect(config('sentinel.fail2ban.logs'))
            ->map(fn (string $path, string $key) => "    {$key}) logpath={$path} ;;")
            ->implode("\n");

        return strtr($body, [
            '__ALLOWLIST__' => collect(config('sentinel.actions.package_allowlist'))->filter()->map(fn ($p) => "'{$p}'")->implode(' '),
            '__DENYLIST__' => collect(config('sentinel.actions.package_denylist'))->map(fn ($p) => "'{$p}'")->implode(' '),
            '__INSTALLABLE__' => collect(config('sentinel.actions.installable_packages'))->map(fn ($p) => "'{$p}'")->implode(' '),
            '__RECOVERABLE__' => collect(config('sentinel.actions.recoverable_services'))->map(fn ($p) => "'{$p}'")->implode(' '),
            '__LOG_CASES__' => $logCases,
            '__IGNOREIP__' => implode(' ', SourceIps::configured()),
        ]);
    }
}
