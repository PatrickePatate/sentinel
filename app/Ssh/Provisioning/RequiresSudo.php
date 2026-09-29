<?php

namespace App\Ssh\Provisioning;

/**
 * Implemented by every tool/action that needs root: the sudoers file installed on
 * the machines is generated from these rules, so it can never drift from the code.
 */
interface RequiresSudo
{
    /**
     * Exact sudoers command specs (absolute path + exact arguments). Wildcards are
     * only acceptable for root-owned wrappers that validate their own arguments.
     *
     * @return list<string>
     */
    public function sudoRules(): array;
}
