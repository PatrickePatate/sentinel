<?php

namespace App\Sharp\Entities;

use App\Sharp\Scans\AgentRunList;
use App\Sharp\Scans\AgentRunShow;
use Code16\Sharp\Utils\Entities\SharpEntity;

class AgentRunEntity extends SharpEntity
{
    protected string $label = 'Scan';

    protected ?string $icon = 'lucide-scan-search';

    protected ?string $list = AgentRunList::class;

    protected ?string $show = AgentRunShow::class;

    protected array $prohibitedActions = ['create', 'update'];
}
