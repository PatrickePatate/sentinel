<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Ssh\HostKeyFingerprint;
use Illuminate\Console\Command;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

class CheckMachine extends Command
{
    protected $signature = 'sentinel:check {machine : Machine id}';

    protected $description = 'Diagnose the SSH connection to a machine step by step (host key, key authentication, sudo)';

    public function handle(): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        if (config('sentinel.transport') === 'fake') {
            $this->warn('SENTINEL_TRANSPORT=fake: nothing to check, no real connection is made.');

            return self::SUCCESS;
        }

        $this->line("Machine {$machine->name}: {$machine->username}@{$machine->host}:{$machine->port}");

        try {
            $key = PublicKeyLoader::load($machine->private_key, $machine->passphrase ?: false);
        } catch (Throwable $e) {
            return $this->failWith('The private key stored in Sentinel cannot be read. Re-create the machine, or restore the key.');
        }

        $this->line('Key Sentinel presents: '.$key->getPublicKey()->getFingerprint('sha256').' (must be the one in authorized_keys on the machine)');

        $ssh = new SSH2($machine->host, $machine->port, 10);

        try {
            $hostKey = $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            $hostKey = false;
            $reason = $e->getMessage();
        }

        if (! $hostKey) {
            return $this->failWith("Cannot reach {$machine->host}:{$machine->port} (".($reason ?? 'no host key offered').'): firewall, wrong host or port, or sshd is down.');
        }

        $fingerprint = HostKeyFingerprint::of($hostKey);
        $this->info("✔ Server reachable, host key {$fingerprint}");

        if ($machine->host_key_fingerprint === null) {
            $this->warn('  Host key not pinned yet: pin it in the back-office before scans can run.');
        } elseif (! hash_equals($machine->host_key_fingerprint, $fingerprint)) {
            return $this->failWith("Host key differs from the pinned one ({$machine->host_key_fingerprint}). Wrong host, or the server was reinstalled: verify, then pin again.");
        } else {
            $this->info('✔ Host key matches the pinned fingerprint');
        }

        if (! $ssh->login($machine->username, $key)) {
            $this->error("✘ The server rejected the key for user '{$machine->username}'.");
            $this->line('  Look at the machine (as root) for the reason:');
            $this->line("    journalctl -u ssh -u sshd --since '10 min ago' | grep -i {$machine->username}");
            $this->line('  Common causes:');
            $this->line("    - the provisioning script was not run (or was run for another key / another machine): rerun `php artisan sentinel:provision {$machine->id}`");
            $this->line('    - the script was generated with --from=<IP> but Sentinel connects from another address (NAT, IPv6, proxy):');
            $this->line('      compare with `grep from= ~'.$machine->username.'/.ssh/authorized_keys`');
            $this->line('    - sshd AllowUsers/AllowGroups does not list the user (`sshd -T | grep -i allow`)');
            $this->line('    - the key in authorized_keys is not the fingerprint above (`ssh-keygen -lf ~'.$machine->username.'/.ssh/authorized_keys`)');
            $this->line('    - UsePAM is off and the locked account is refused: use `usermod -p "*" '.$machine->username.'` instead of a `!` lock');
            $this->line('    - permissions: home and ~/.ssh must not be group/world-writable (StrictModes)');

            return self::FAILURE;
        }

        $this->info('✔ Key accepted');

        $id = trim((string) $ssh->exec('id -un'));
        $sudo = trim((string) $ssh->exec('sudo -n -l 2>&1 | head -3'));
        $this->line("  logged in as: {$id}");
        $this->line('  sudo: '.($sudo === '' ? '(no output)' : str_replace("\n", "\n        ", $sudo)));

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error("✘ {$message}");

        return self::FAILURE;
    }
}
