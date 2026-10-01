<?php

namespace App\Ssh\Actions;

/**
 * An action whose root-owned wrapper enforces guarantees the risk classifier cannot see in a command line
 * (e.g. "never touches a running service"). They are given to the classifier as facts about the command;
 * they never lower the thresholds, and the wrapper, not the model, is what actually enforces them.
 */
interface HasSafeguards
{
    public function safeguards(): string;
}
