<?php

namespace App\Sharp\Entities;

use App\Sharp\PendingActions\PendingActionList;
use App\Sharp\PendingActions\PendingActionShow;
use Code16\Sharp\Utils\Entities\SharpEntity;

class PendingActionEntity extends SharpEntity
{
    protected string $label = 'Pending action';

    protected ?string $icon = 'lucide-hand';

    protected ?string $list = PendingActionList::class;

    protected ?string $show = PendingActionShow::class;

    protected array $prohibitedActions = ['create', 'update', 'delete'];
}
