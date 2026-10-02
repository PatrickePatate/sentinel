<?php

namespace App\Monitoring;

use App\Models\Machine;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\Cache;

/**
 * Turns metric samples into alerts before something breaks: a filesystem that will be full within a few days at
 * its current pace, or one that is already nearly full. Each alert is sent at most once a day per machine.
 */
class TrendWatcher
{
    public const WATCHED = ['disk_root_percent' => 'Root filesystem', 'inode_root_percent' => 'Root inodes'];

    public function __construct(private Notifier $notifier) {}

    /** @return list<string> Titles of the alerts sent. */
    public function check(Machine $machine): array
    {
        $sent = [];
        $settings = config('sentinel.metrics');

        foreach (self::WATCHED as $metric => $label) {
            $trend = $machine->metricTrend($metric, $settings['trend_days']);

            if ($trend === null) {
                continue;
            }

            $percent = round($trend['percent']);

            if ($trend['percent'] >= $settings['full_percent']) {
                $title = "{$label} {$percent}% full on {$machine->name}";
                $body = "It is at {$percent}%. Services start failing when it reaches 100%.";
            } elseif ($trend['days_left'] !== null && $trend['days_left'] <= $settings['forecast_days']) {
                $title = "{$label} full in about {$trend['days_left']} days on {$machine->name}";
                $body = sprintf('It is at %d%% and grows by %.1f points a day (over the last %d days).', $percent, $trend['per_day'], $settings['trend_days']);
            } else {
                continue;
            }

            if (Cache::add("sentinel.trend-alert.{$machine->id}.{$metric}", true, now()->addDay())) {
                $this->notifier->machineAlert($machine, $title, $body, urgent: $trend['percent'] >= $settings['full_percent']);
                $sent[] = $title;
            }
        }

        return $sent;
    }
}
