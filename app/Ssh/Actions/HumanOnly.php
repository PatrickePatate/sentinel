<?php

namespace App\Ssh\Actions;

/**
 * Marker for actions that exist in the catalog (so they are validated, audited, approved and in the sudoers policy like
 * any other) but are never offered to the agent as a tool: a human starts them, or approves each one.
 */
interface HumanOnly {}
