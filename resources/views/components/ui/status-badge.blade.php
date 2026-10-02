{{-- One place that decides how scan / action / severity / client states look. --}}
@props(['status'])
@php
    $map = [
        'queued' => ['secondary', 'Queued'], 'running' => ['info', 'Running'], 'completed' => ['success', 'Completed'], 'failed' => ['destructive', 'Failed'],
        'pending' => ['warning', 'Awaiting approval'], 'scheduled' => ['info', 'Scheduled'], 'executed' => ['success', 'Executed'], 'rejected' => ['secondary', 'Rejected'], 'expired' => ['secondary', 'Expired'],
        'info' => ['secondary', 'Info'], 'low' => ['success', 'Low'], 'medium' => ['warning', 'Medium'], 'high' => ['destructive', 'High'], 'critical' => ['destructive', 'Critical'],
        'up_to_date' => ['success', 'Up to date'], 'outdated' => ['warning', 'Update available'], 'updater_outdated' => ['warning', 'Re-provision needed'], 'not_installed' => ['secondary', 'Not provisioned'], 'unreachable' => ['destructive', 'Unreachable'],
    ];
    [$variant, $label] = $map[$status] ?? ['secondary', ucfirst(str_replace('_', ' ', (string) $status))];
@endphp
<x-ui.badge :variant="$variant" :dot="in_array($status, ['running', 'pending'])" {{ $attributes }}>{{ $slot->isEmpty() ? $label : $slot }}</x-ui.badge>
