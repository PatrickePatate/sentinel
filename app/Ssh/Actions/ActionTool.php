<?php

namespace App\Ssh\Actions;

use App\Ssh\Tools\Tool;

/**
 * A corrective (state-changing) action. Same contract as a read-only Tool,
 * plus a static risk level the RiskGate uses before anything runs.
 */
interface ActionTool extends Tool
{
    public function risk(): RiskLevel;
}
