<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCheck extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['machine_id', 'url', 'ok', 'status_code', 'response_ms', 'cert_expires_at', 'error', 'failures', 'alerted', 'checked_at', 'analyze_on_down'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'analyze_on_down' => 'boolean', 'cert_expires_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
