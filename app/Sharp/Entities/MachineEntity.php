<?php

namespace App\Sharp\Entities;

use App\Sharp\Machines\MachineForm;
use App\Sharp\Machines\MachineList;
use App\Sharp\Machines\MachineShow;
use Code16\Sharp\Utils\Entities\SharpEntity;

class MachineEntity extends SharpEntity
{
    protected string $label = 'Machine';

    protected ?string $icon = 'lucide-server';

    protected ?string $list = MachineList::class;

    protected ?string $show = MachineShow::class;

    protected ?string $form = MachineForm::class;
}
