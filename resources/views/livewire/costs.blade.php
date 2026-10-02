<div class="space-y-6">
    <x-ui.page-header title="Costs" description="Estimated model spending (tokens reported by the provider × the price list). Runs without a known price count tokens only.">
        <x-slot:actions><x-ui.select wire:model.live="month" class="w-48 shrink-0" :options="$months->all()" /></x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat :label="$current ? 'Spent this month' : 'Spent'" :value="sprintf('$%.2f', $total)" :hint="$limit ? sprintf('of $%.2f budget (%d%%)', $limit, $total / $limit * 100) : 'No global budget set'" />
        <x-ui.stat label="Projected end of month" :value="$projection !== null ? sprintf('$%.2f', $projection) : '—'" :hint="$projection !== null && $limit && $projection > $limit ? 'Over budget at this pace' : null" />
        <x-ui.stat label="Tokens" :value="number_format($tokens)" />
        <x-ui.stat label="AI calls avoided" :value="$avoided" hint="Healthy pre-checks and unchanged audits" />
    </div>

    <x-ui.card title="Per day" description="Hover a bar for the amount.">
        @php($max = max($days->max('cost'), 0.0001))
        <div class="flex h-40 items-end gap-[2px]" role="img" aria-label="Spending per day">
            @foreach ($days as $day)
                <div class="group relative flex h-full flex-1 items-end" title="{{ $day['day']->format('M d') }}: ${{ number_format($day['cost'], 2) }}">
                    <div @class(['w-full rounded-t-[4px] bg-primary transition-opacity group-hover:opacity-80', 'bg-muted' => $day['cost'] == 0]) style="height: {{ $day['cost'] > 0 ? max(2, $day['cost'] / $max * 100) : 1 }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex justify-between text-xs text-muted-foreground"><span>{{ $days->first()['day']->format('M d') }}</span><span>max ${{ number_format($max, 2) }} / day</span><span>{{ $days->last()['day']->format('M d') }}</span></div>
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="By machine" flush>
            @if ($byMachine->isEmpty())
                <x-ui.empty icon="lucide-server" title="No spending" description="No priced run this month." />
            @else
                <x-ui.table>
                    <thead><tr><th>Machine</th><th class="text-right">Runs</th><th class="text-right">Cost</th><th>Budget</th></tr></thead>
                    <tbody>
                        @foreach ($byMachine as $row)
                            <tr wire:key="bm{{ $row['machine']->id }}">
                                <td><a class="hover:underline" href="{{ route('machines.show', $row['machine']) }}" wire:navigate>{{ $row['machine']->name }}</a></td>
                                <td class="text-right tabular-nums">{{ $row['runs'] }}</td>
                                <td class="text-right tabular-nums">${{ number_format($row['cost'], 2) }}</td>
                                <td class="w-40">
                                    @if ($row['machine']->monthly_budget_usd)
                                        @php($pct = min(100, $row['cost'] / $row['machine']->monthly_budget_usd * 100))
                                        <div class="h-1.5 rounded-full bg-muted"><div @class(['h-1.5 rounded-full', 'bg-destructive' => $pct >= 100, 'bg-warning' => $pct >= 80 && $pct < 100, 'bg-primary' => $pct < 80]) style="width: {{ $pct }}%"></div></div>
                                        <span class="text-xs text-muted-foreground">{{ round($pct) }}% of ${{ number_format($row['machine']->monthly_budget_usd, 2) }}</span>
                                    @else<span class="text-xs text-muted-foreground">none</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <div class="space-y-6">
            <x-ui.card title="By kind of run" flush>
                <x-ui.table>
                    <tbody>
                        @forelse ($kinds as $kind)
                            <tr><td>{{ $kind['label'] }}</td><td class="text-right tabular-nums text-muted-foreground">{{ $kind['runs'] }} runs</td><td class="text-right tabular-nums">${{ number_format($kind['cost'], 2) }}</td></tr>
                        @empty
                            <tr><td class="text-muted-foreground">Nothing yet.</td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </x-ui.card>
            <x-ui.card title="By model" flush>
                <x-ui.table>
                    <tbody>
                        @forelse ($byModel as $model)
                            <tr><td class="font-mono text-xs">{{ $model->name }}</td><td class="text-right tabular-nums text-muted-foreground">{{ $model->runs }} runs</td><td class="text-right tabular-nums">${{ number_format($model->cost, 2) }}</td></tr>
                        @empty
                            <tr><td class="text-muted-foreground">Nothing yet.</td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card title="Most expensive runs" flush>
        <x-ui.table>
            <thead><tr><th>Run</th><th>Machine</th><th>Kind</th><th class="text-right">Tokens</th><th class="text-right">Cost</th></tr></thead>
            <tbody>
                @forelse ($top as $run)
                    <tr wire:key="t{{ $run->id }}" class="cursor-pointer" onclick="Livewire.navigate('{{ route('scans.show', $run->parent_run_id ?? $run->id) }}')">
                        <td class="text-muted-foreground">#{{ $run->id }} · {{ $run->created_at->format('M d H:i') }}</td>
                        <td>{{ $run->machine?->name }}</td>
                        <td>{{ $run->profileLabel() ?? $run->trigger }} · {{ $run->trigger }}</td>
                        <td class="text-right tabular-nums">{{ number_format($run->input_tokens + $run->output_tokens) }}</td>
                        <td class="text-right tabular-nums">${{ number_format($run->cost_usd, 3) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted-foreground">No priced run in this month.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
    @if ($unpriced)<p class="text-xs text-muted-foreground">{{ $unpriced }} run(s) have tokens but no price: add their model to sentinel.pricing or run php artisan sentinel:pricing.</p>@endif
</div>
