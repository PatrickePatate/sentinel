<?php

namespace App\Ssh\Actions;

use App\Ssh\Tools\FixedCommandTool;

class FixedCommandAction extends FixedCommandTool implements ActionTool
{
    public function __construct(
        string $name,
        string $description,
        string $command,
        private RiskLevel $risk,
    ) {
        parent::__construct($name, $description, $command);
    }

    public function risk(): RiskLevel
    {
        return $this->risk;
    }
}
