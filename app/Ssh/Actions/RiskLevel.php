<?php

namespace App\Ssh\Actions;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
