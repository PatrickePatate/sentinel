<?php

namespace App\Ssh\Actions;

trait UsesSudo
{
    /**
     * Actions change system state, so they run through non-interactive sudo. The SSH
     * account stays unprivileged; the server's sudoers file is the last line of defence.
     */
    protected function sudo(): string
    {
        return config('sentinel.actions.use_sudo') ? 'sudo -n ' : '';
    }
}
