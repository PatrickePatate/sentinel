<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $recorded_at
 * @property float $value
 */
class MachineMetric extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['machine_id', 'name', 'value', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'value' => 'float'];
    }
}
