<?php

namespace App\Notifications\Channels;

class TelegramMessage
{
    /** @param string $html Telegram-flavoured HTML: every dynamic value must already be escaped. */
    public function __construct(public string $html, public array $keyboard = []) {}
}
