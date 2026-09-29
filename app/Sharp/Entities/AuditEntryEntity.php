<?php

namespace App\Sharp\Entities;

use App\Sharp\AuditLog\AuditEntryList;
use Code16\Sharp\Utils\Entities\SharpEntity;

class AuditEntryEntity extends SharpEntity
{
    protected string $label = 'Audit entry';

    protected ?string $icon = 'lucide-clipboard-list';

    protected ?string $list = AuditEntryList::class;

    protected array $prohibitedActions = ['create', 'update', 'delete'];
}
