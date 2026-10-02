<?php

namespace App\Ai;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Notifications\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/** Model spending per calendar month, against the global budget and each machine's own. */
class Budget
{
    public function __construct(private Notifier $notifier) {}

    public function spent(?Machine $machine = null, ?CarbonInterface $month = null): float
    {
        $month ??= now();

        return (float) AgentRun::whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->when($machine, fn ($q) => $q->where('machine_id', $machine->id))
            ->sum('cost_usd');
    }

    public function limit(?Machine $machine = null): ?float
    {
        return $machine ? ($machine->monthly_budget_usd ?: null) : config('sentinel.budget.monthly_usd');
    }

    /** True when the global budget or this machine's one is used up for this month. */
    public function exceeded(Machine $machine): bool
    {
        foreach ([null, $machine] as $scope) {
            $limit = $this->limit($scope);

            if ($limit !== null && $this->spent($scope) >= $limit) {
                return true;
            }
        }

        return false;
    }

    /** End-of-month spending at the pace of the month so far. */
    public function projection(?Machine $machine = null): float
    {
        $elapsed = max(now()->startOfMonth()->diffInSeconds(now()) / 86400, 1 / 24);

        return $this->spent($machine) / $elapsed * now()->daysInMonth;
    }

    /** Tells the humans when a budget passes the warning threshold or runs out, once per month and scope. */
    public function check(Machine $machine): void
    {
        foreach ([null, $machine] as $scope) {
            $limit = $this->limit($scope);

            if ($limit === null || $limit <= 0) {
                continue;
            }

            $spent = $this->spent($scope);
            $percent = $spent / $limit * 100;
            $level = match (true) {
                $percent >= 100 => 100,
                $percent >= config('sentinel.budget.warn_percent') => config('sentinel.budget.warn_percent'),
                default => null,
            };

            $key = 'sentinel.budget.'.($scope ? $scope->id : 'all').'.'.now()->format('Y-m').".{$level}";

            if ($level === null || ! Cache::add($key, true, now()->addDays(35))) {
                continue;
            }

            $what = $scope ? "The budget of {$scope->name}" : 'The monthly model budget';
            $title = $level >= 100 ? "{$what} is used up" : "{$what} is {$level}% used";
            $body = sprintf('$%.2f of $%.2f spent this month.', $spent, $limit)
                .($level >= 100 ? ' Routine scheduled AI scans are paused until next month; manual scans and checks of detected problems still run.' : '');

            // The alert goes out through a machine, so it reaches the channels that take scan notifications.
            $this->notifier->machineAlert($machine, $title, $body, urgent: false, evenWhenQuiet: true);
        }
    }
}
