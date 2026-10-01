<?php

namespace App\Ssh\Provisioning;

use App\Models\Machine;
use App\Ssh\AuditTrail;
use App\Ssh\SshTransport;
use RuntimeException;
use Throwable;

/**
 * Brings a machine's client bundle (wrappers + sudoers policy) up to date over the existing SSH access, without
 * re-running the provisioning script. Only ever triggered by a human (dashboard / artisan), never by the agent: it is
 * not in the tool or action catalogs. The machine's own root updater validates what it is handed.
 */
class ClientUpdater
{
    private const TIMEOUT_SECONDS = 90;

    public function __construct(
        private ClientBundle $bundle,
        private SshTransport $transport,
        private AuditTrail $audit,
    ) {}

    /** What Sentinel last saw on the machine, compared with today's bundle: no connection made. */
    public function lastKnown(Machine $machine): ClientStatus
    {
        $expected = $this->bundle->versionLine($machine->username);

        return new ClientStatus($this->stateOf($machine->client_version, $expected), $expected, $machine->client_version);
    }

    public function check(Machine $machine): ClientStatus
    {
        $expected = $this->bundle->versionLine($machine->username);

        try {
            $result = $this->transport->run($machine, 'cat '.ClientBundle::VERSION_FILE.' 2>/dev/null', 20);
        } catch (Throwable $e) {
            return new ClientStatus(ClientStatus::UNREACHABLE, $expected, $machine->client_version, $e->getMessage());
        }

        $installed = trim($result->output) ?: null;
        $machine->forceFill(['client_version' => $installed, 'client_checked_at' => now()])->save();

        return new ClientStatus($this->stateOf($installed, $expected), $expected, $installed);
    }

    /** @throws RuntimeException when the machine refuses the update; the message is the updater's own. */
    public function deploy(Machine $machine): ClientStatus
    {
        $before = $this->check($machine);

        if ($before->state === ClientStatus::UNREACHABLE) {
            throw new RuntimeException($before->error ?? 'The machine is unreachable.');
        }

        if ($before->state === ClientStatus::NOT_INSTALLED || $before->state === ClientStatus::UPDATER_OUTDATED) {
            throw new RuntimeException('This machine needs the provisioning one-liner run as root (it has no updater, or an older one).');
        }

        $sudo = config('sentinel.actions.use_sudo') ? 'sudo -n ' : '';
        $command = $sudo.ClientBundle::UPDATER_PATH." 2>&1 <<'SENTINEL_BUNDLE_EOF'\n".$this->bundle->render($machine).'SENTINEL_BUNDLE_EOF';

        $result = $this->transport->run($machine, $command, self::TIMEOUT_SECONDS);
        $output = trim($result->output);

        if ($result->exitCode !== 0) {
            $this->audit->record($machine, null, 'client_update_failed', 'client update refused', ['from' => $before->installed, 'output' => $output]);

            throw new RuntimeException($output ?: "The updater exited with code {$result->exitCode}.");
        }

        $after = $this->check($machine);

        $this->audit->record($machine, null, 'client_updated', 'client bundle updated', ['from' => $before->installed, 'to' => $after->installed, 'output' => $output]);

        return $after;
    }

    private function stateOf(?string $installed, string $expected): string
    {
        if ($installed === null || ! preg_match('/^bundle=([0-9a-f]+) updater=([0-9a-f]+)$/', $installed, $have)) {
            return ClientStatus::NOT_INSTALLED;
        }

        preg_match('/^bundle=([0-9a-f]+) updater=([0-9a-f]+)$/', $expected, $want);

        return match (true) {
            $have[2] !== $want[2] => ClientStatus::UPDATER_OUTDATED,
            $have[1] !== $want[1] => ClientStatus::OUTDATED,
            default => ClientStatus::UP_TO_DATE,
        };
    }
}
