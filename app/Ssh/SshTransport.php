<?php

namespace App\Ssh;

use App\Models\Machine;

interface SshTransport
{
    /**
     * Runs one already-validated command on the machine. Must never be called
     * with anything that did not come from a Tool.
     */
    public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult;
}
