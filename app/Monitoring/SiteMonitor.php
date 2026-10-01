<?php

namespace App\Monitoring;

use App\Ai\ScanRunner;
use App\Jobs\RunScan;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\SiteCheck;
use App\Notifications\Notifier;
use App\Support\Realtime;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks at a machine's public sites from Sentinel itself, which a check on the machine cannot do (DNS, firewall, the
 * certificate visitors really get). A site that stays down is announced once, and (if the site asks for it and the machine has
 * the web server analysis on) starts an analysis of the machine.
 */
class SiteMonitor
{
    public const MAX_PER_MACHINE = 10;

    public function __construct(private CertificateReader $certificates, private Notifier $notifier, private ScanRunner $scans) {}

    public function checkAll(): int
    {
        $sites = SiteCheck::query()->with('machine')->whereHas('machine', fn ($q) => $q->whereNull('revoked_at'))->get();

        $sites->each(fn (SiteCheck $site) => $this->check($site));

        return $sites->count();
    }

    public function check(SiteCheck $site): SiteCheck
    {
        $machine = $site->machine;
        $status = null;
        $error = null;
        $started = microtime(true);

        try {
            $status = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => ['max' => 3]])->withHeaders(['User-Agent' => 'Sentinel-Uptime/1.0'])->get($site->url)->status();
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $ok = $status !== null && $status < 500;
        $parts = parse_url($site->url);
        $cert = ($parts['scheme'] ?? '') === 'https' ? $this->certificates->expiry($parts['host'], $parts['port'] ?? 443) : null;

        $site->fill([
            'ok' => $ok,
            'status_code' => $status,
            'response_ms' => $ok ? $ms : null,
            'cert_expires_at' => $cert ?? $site->cert_expires_at,
            'error' => $ok ? null : ($error ?? "HTTP {$status}"),
            'failures' => $ok ? 0 : $site->failures + 1,
            'checked_at' => now(),
        ]);

        $this->announce($machine, $site);
        $site->save();
        Realtime::push('site', $machine->id);

        return $site;
    }

    private function announce(Machine $machine, SiteCheck $check): void
    {
        $wasDown = $check->getOriginal('alerted') === 'down';
        $analysis = $this->willAnalyze($machine, $check);

        if (! $check->ok && $check->failures >= config('sentinel.sites.failures_before_alert') && ! $wasDown) {
            $check->alerted = 'down';
            $this->notifier->machineAlert($machine, "{$check->url} is down", "{$check->error}. It failed {$check->failures} checks in a row.".($analysis ? ' A web server analysis of the machine was started.' : ''));

            if ($analysis) {
                $this->analyze($machine);
            }

            return;
        }

        if ($check->ok && $wasDown) {
            $check->alerted = null;
            $this->notifier->machineAlert($machine, "{$check->url} is back", "The site answers again (HTTP {$check->status_code}, {$check->response_ms} ms).", urgent: false);
        }

        if (! $check->ok) {
            return;
        }

        $days = $check->cert_expires_at ? (int) floor(now()->diffInDays($check->cert_expires_at, false)) : null;

        if ($days !== null && $days <= config('sentinel.sites.cert_warning_days') && $check->alerted !== 'cert') {
            $check->alerted = 'cert';
            $this->notifier->machineAlert($machine, "Certificate of {$check->url} expires ".($days < 0 ? 'ago' : "in {$days} days"), "It expires on {$check->cert_expires_at->toDateString()}. Renewal (certbot renew) may need your approval.", urgent: $days <= 3);
        } elseif (($days === null || $days > config('sentinel.sites.cert_warning_days')) && $check->alerted === 'cert') {
            $check->alerted = null;
        }
    }

    /** Whether a down site starts an analysis: the site asks for it, the machine has the analysis on and can be reached. */
    public function willAnalyze(Machine $machine, SiteCheck $site): bool
    {
        return config('sentinel.sites.scan_on_down') && $site->analyze_on_down && $machine->webserver_enabled && $machine->host_key_fingerprint !== null;
    }

    /** Skipped when a web server analysis is already running or ran very recently. */
    private function analyze(Machine $machine): void
    {
        $busy = AgentRun::where('machine_id', $machine->id)->where('profile', 'webserver')
            ->where(fn ($q) => $q->whereIn('status', ['queued', 'running'])->orWhere('created_at', '>', now()->subMinutes(10)))->exists();

        if ($busy) {
            return;
        }

        $objective = config('sentinel.scheduling.profiles.webserver.objective');
        $run = $this->scans->queue($machine, $objective, 'site_down', 'webserver');
        RunScan::dispatch($machine->id, $objective, null, 'site_down', $run->id, 'webserver');
    }
}
