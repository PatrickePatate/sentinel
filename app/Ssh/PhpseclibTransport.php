<?php

namespace App\Ssh;

use App\Models\Machine;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class PhpseclibTransport implements SshTransport
{
    public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
    {
        $machine->assertActive();

        $ssh = SshConnection::open($machine);
        $ssh->setTimeout($timeoutSeconds);

        $this->verifyHostKey($ssh, $machine);

        $key = PublicKeyLoader::load($machine->private_key, $machine->passphrase ?: false);

        if (! $ssh->login($machine->username, $key)) {
            throw new RuntimeException("SSH authentication failed for {$machine->username}@{$machine->host}:{$machine->port} ({$machine->name}): ".($ssh->getLastError() ?: 'the server rejected the key').'. Run `php artisan sentinel:check '.$machine->id.'` to diagnose.');
        }

        $output = $ssh->exec($command);

        return new CommandResult((string) $output, (int) $ssh->getExitStatus());
    }

    private function verifyHostKey(SSH2 $ssh, Machine $machine): void
    {
        $publicKey = $ssh->getServerPublicHostKey();

        if (! $publicKey) {
            throw new RuntimeException("Could not read host key of {$machine->name}.");
        }

        $fingerprint = HostKeyFingerprint::of($publicKey);

        if ($machine->host_key_fingerprint === null) {
            throw new RuntimeException("Host key of {$machine->name} is not pinned yet ({$fingerprint}). Pin it before use.");
        }

        if (HostKeyFingerprint::matches($machine->host_key_fingerprint, $publicKey)) {
            return;
        }

        // A pin made before the fingerprint was computed like ssh-keygen does: same key, old notation. Upgrade it.
        if (hash_equals($machine->host_key_fingerprint, HostKeyFingerprint::legacy($publicKey))) {
            $machine->forceFill(['host_key_fingerprint' => $fingerprint])->save();

            return;
        }

        throw new RuntimeException("Host key mismatch for {$machine->name} (pinned {$machine->host_key_fingerprint}, server presents {$fingerprint}): refusing to connect.");
    }
}
