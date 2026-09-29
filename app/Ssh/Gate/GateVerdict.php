<?php

namespace App\Ssh\Gate;

enum GateVerdict: string
{
    case Execute = 'execute';
    case AskHuman = 'ask_human';
    case Refuse = 'refuse';
}
