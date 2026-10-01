<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Ssh\Provisioning\ClientStatus;
use App\Ssh\Provisioning\ClientUpdater;
use Illuminate\Console\Command;
use Throwable;

class UpdateClients extends Command
{
    protected $signature = 'sentinel:update {machine? : Machine id} {--all : Every active machine} {--check : Only report the client version, change nothing}';

    protected $description = 'Check, or bring up to date, the Sentinel client (wrappers + sudoers) installed on machines, over SSH';

    public function handle(ClientUpdater $updater): int
    {
        $machines = $this->argument('machine')
            ? Machine::whereKey($this->argument('machine'))->get()
            : ($this->option('all') ? Machine::whereNull('revoked_at')->whereNotNull('host_key_fingerprint')->get() : collect());

        if ($machines->isEmpty()) {
            $this->error('Give a machine id, or --all.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($machines as $machine) {
            $status = $updater->check($machine);

            if (! $this->option('check') && $status->canUpdateRemotely()) {
                try {
                    $status = $updater->deploy($machine);
                } catch (Throwable $e) {
                    $this->error("{$machine->name}: update failed: {$e->getMessage()}");
                    $failed = true;

                    continue;
                }
            }

            $line = "{$machine->name}: {$status->label()}".($status->installed ? " ({$status->installed})" : '');
            $status->isUpToDate() ? $this->info($line) : $this->warn($line.($status->error ? ": {$status->error}" : ''));

            if ($status->state === ClientStatus::UPDATER_OUTDATED || $status->state === ClientStatus::NOT_INSTALLED) {
                $this->line('  Run the provisioning one-liner again as root on the machine (see `php artisan sentinel:provision --help`).');
            }

            $failed = $failed || $status->state === ClientStatus::UNREACHABLE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
