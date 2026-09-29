<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'user_id', 'role', 'content'];
}
