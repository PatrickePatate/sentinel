<?php

namespace App\Ssh\Provisioning;

use App\Ssh\ActionCatalog;
use App\Ssh\ToolCatalog;
use InvalidArgumentException;

/**
 * Builds the sudoers policy from the catalogs: the machine can only be asked to do
 * what the code can request, and nothing else.
 */
class SudoersBuilder
{
    public function __construct(
        private ToolCatalog $tools,
        private ActionCatalog $actions,
    ) {}

    /** @return list<string> */
    public function rules(): array
    {
        $rules = [];

        foreach ([...$this->tools->all(), ...$this->actions->all()] as $tool) {
            if ($tool instanceof RequiresSudo) {
                array_push($rules, ...$tool->sudoRules());
            }
        }

        // The bundle updater is part of the base install: it only installs bundles signed by Sentinel, and validates them (see sentinel-self-update.sh).
        $rules[] = ClientBundle::UPDATER_PATH;

        $rules = array_values(array_unique($rules));
        sort($rules);

        return $rules;
    }

    public function render(string $user): string
    {
        self::assertValidUser($user);

        $rules = $this->rules();
        $commands = $rules === []
            ? '/bin/false'
            : implode(", \\\n    ", array_map($this->escape(...), $rules));

        return <<<SUDOERS
# Managed by Sentinel - do not edit by hand. Regenerate with `php artisan sentinel:provision`.
# {$user} may run ONLY the commands below, as root, without password.
Defaults:{$user} env_reset
Defaults:{$user} !requiretty
Defaults:{$user} logfile="/var/log/sentinel-sudo.log"

Cmnd_Alias SENTINEL_CMDS = \\
    {$commands}

{$user} ALL=(root) NOPASSWD: SENTINEL_CMDS

SUDOERS;
    }

    public static function assertValidUser(string $user): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) || $user === 'root') {
            throw new InvalidArgumentException("Refusing to provision unsafe user name [{$user}].");
        }
    }

    /** sudoers treats , : = \ specially inside command specs. */
    private function escape(string $rule): string
    {
        return preg_replace('/([,:=\\\\])/', '\\\\$1', $rule);
    }
}
