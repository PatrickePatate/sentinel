<?php

namespace App\Sharp\Entities;

use App\Sharp\Notifications\NotificationChannelForm;
use App\Sharp\Notifications\NotificationChannelList;
use Code16\Sharp\Utils\Entities\SharpEntity;

class NotificationChannelEntity extends SharpEntity
{
    protected string $label = 'Notification channel';

    protected ?string $icon = 'lucide-bell';

    protected ?string $list = NotificationChannelList::class;

    protected ?string $form = NotificationChannelForm::class;
}
