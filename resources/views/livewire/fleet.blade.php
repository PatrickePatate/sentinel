<div @realtime wire:poll.120s @else wire:poll.60s @endrealtime class="space-y-6">
    <x-ui.page-header title="Fleet" description="Every machine side by side, worst first: open issues, latest health sample and pending updates." />

    <div class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat label="Machines" :value="$totals['machines']" />
        <x-ui.stat label="High or critical issues" :value="$totals['serious']" />
        <x-ui.stat label="Security updates pending" :value="$totals['security']" />
        <x-ui.stat label="Reboots required" :value="$totals['reboot']" />
    </div>

    <x-ui.select wire:model.live="filter" class="w-60" :options="\App\Livewire\Fleet::FILTERS" />

    <x-ui.card flush>
        @if ($rows->isEmpty())
            <x-ui.empty icon="lucide-server" title="No machine" description="No machine matches this filter." />
        @else
            <x-ui.table>
                <thead><tr><th>Machine</th><th>Open issues</th><th>Disk</th><th>Memory</th><th>Load / CPU</th><th>Updates</th><th>Last scan</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($m = $row['metrics'])
                        <tr wire:key="fleet{{ $row['machine']->id }}" class="cursor-pointer" onclick="Livewire.navigate('{{ route('machines.show', $row['machine']) }}')">
                            <td><span class="font-medium">{{ $row['machine']->name }}</span> <span class="text-xs text-muted-foreground">{{ $row['machine']->environment }}</span></td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @foreach (['critical', 'high', 'medium', 'low'] as $level)
                                        @if ($row['issues'][$level] ?? 0)<x-ui.status-badge :status="$level">{{ $row['issues'][$level] }} {{ $level }}</x-ui.status-badge>@endif
                                    @endforeach
                                    @if ($row['issues']->sum() === 0)<span class="text-muted-foreground">None</span>@endif
                                </div>
                            </td>
                            <td class="tabular-nums">
                                @if (isset($m['disk_root_percent']))
                                    <span @class(['text-destructive font-medium' => $m['disk_root_percent'] >= 90, 'text-warning' => $m['disk_root_percent'] >= 80 && $m['disk_root_percent'] < 90])>{{ round($m['disk_root_percent']) }}%</span>
                                    @if ($row['disk'] && $row['disk']['days_left'] !== null && $row['disk']['days_left'] <= 30)<span class="block text-xs text-warning">full in ~{{ $row['disk']['days_left'] }} d</span>@endif
                                @else<span class="text-muted-foreground">—</span>@endif
                            </td>
                            <td class="tabular-nums">{{ isset($m['memory_used_percent']) ? round($m['memory_used_percent']).'%' : '—' }}</td>
                            <td class="tabular-nums">{{ isset($m['load_per_cpu']) ? number_format($m['load_per_cpu'], 2) : '—' }}</td>
                            <td class="text-xs">
                                @if (isset($m['updates_pending']))
                                    {{ (int) $m['updates_pending'] }} pending{{ ($m['security_updates_pending'] ?? 0) > 0 ? ',' : '' }}
                                    @if (($m['security_updates_pending'] ?? 0) > 0)<span class="font-medium text-destructive">{{ (int) $m['security_updates_pending'] }} security</span>@endif
                                @else<span class="text-muted-foreground">—</span>@endif
                                @if (($m['reboot_required'] ?? 0) > 0)<x-ui.badge variant="warning" class="ml-1">Reboot</x-ui.badge>@endif
                            </td>
                            <td class="whitespace-nowrap text-xs text-muted-foreground">{{ $row['last_scan'] ? \Illuminate\Support\Carbon::parse($row['last_scan'])->diffForHumans() : 'never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
    <p class="text-xs text-muted-foreground">Health numbers come from the last sample of the past 24 hours (php artisan sentinel:collect-metrics, scheduled every {{ config('sentinel.metrics.interval_minutes') }} minutes).</p>
</div>
