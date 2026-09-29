<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'provider', 'objective', 'status', 'messages', 'report', 'trigger', 'severity', 'summary'];

    protected function casts(): array
    {
        return ['messages' => 'array'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
