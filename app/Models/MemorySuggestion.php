<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemorySuggestion extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'agent_run_id', 'note', 'status'];

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
