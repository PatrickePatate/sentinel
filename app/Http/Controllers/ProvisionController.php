<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Ssh\AuditTrail;
use App\Ssh\HostKeyPinner;
use App\Ssh\Provisioning\ClientUpdater;
use App\Ssh\Provisioning\ProvisionScript;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The machine-facing side of one-line provisioning (`curl -fsSL <url> | sudo bash`). Both endpoints are authenticated
 * by the short-lived token in the URL, which a dashboard admin issues for one machine.
 */
class ProvisionController extends Controller
{
    public function script(string $machine, string $token, ProvisionScript $script, AuditTrail $audit): Response
    {
        $machine = $this->machineFor($machine, $token);

        $audit->record($machine, null, 'provision_script_fetched', 'provision script fetched', ['ip' => request()->ip()]);

        return response($script->render($machine, $machine->provisionUrl().'/callback'), 200, ['Content-Type' => 'text/x-shellscript; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** The script reports the machine's host key fingerprints: pin the one Sentinel sees if it is among them. */
    public function callback(Request $request, string $machine, string $token, HostKeyPinner $pinner, ClientUpdater $updater): Response
    {
        $machine = $this->machineFor($machine, $token);

        $fingerprints = collect(preg_split('/\s+/', (string) $request->input('fingerprints'), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $f) => preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $f))
            ->unique()->take(8)->values()->all();

        if ($fingerprints === []) {
            return $this->text("No valid host key fingerprint was reported.\n", 422);
        }

        // One callback per token: the URL ends up in shell history and proxy logs, and a second report must not
        // replace the one the machine made. A retry takes a new link from the dashboard.
        $machine->forceFill(['host_keys_reported' => $fingerprints, 'provisioned_at' => now(), 'provision_token' => null, 'provision_token_expires_at' => null])->save();

        [$status, $presented] = $pinner->pinIfReported($machine);

        $lines = match ($status) {
            HostKeyPinner::PINNED => ["Host key pinned ({$presented})."],
            HostKeyPinner::MISMATCH => ["Sentinel sees host key {$presented}, which this machine did not report: nothing was pinned. Something sits between Sentinel and this machine, or the address is wrong. This link is now used up: get a new one from the dashboard to retry."],
            default => ['Sentinel could not open an SSH connection to this machine from its side (firewall, wrong host or port?). Pin the host key from the dashboard once it can.'],
        };

        if ($status === HostKeyPinner::PINNED) {
            $client = $updater->check($machine->refresh());
            $lines[] = $client->state === 'unreachable'
                ? "Sentinel could not log in yet: {$client->error}"
                : "Sentinel can log in; client {$client->label()}.";
        }

        return $this->text(implode("\n", $lines)."\n", $status === HostKeyPinner::PINNED ? 200 : 202);
    }

    private function machineFor(string $id, string $token): Machine
    {
        $machine = ctype_digit($id) ? Machine::find((int) $id) : null;

        abort_unless($machine && $machine->acceptsProvisionToken($token), 404);

        return $machine;
    }

    private function text(string $body, int $status): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
