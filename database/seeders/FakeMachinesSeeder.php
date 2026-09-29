<?php

namespace Database\Seeders;

use App\Models\Machine;
use App\Ssh\Fake\FakeTransport;
use Illuminate\Database\Seeder;

/**
 * One simulated machine per scenario, host key already pinned, so a dev can
 * scan, chat and approve actions right away. Re-running resets their state.
 */
class FakeMachinesSeeder extends Seeder
{
    public function run(): void
    {
        $fleet = [
            'healthy' => ['web-prod-01', 'production'],
            'disk-full' => ['db-prod-01', 'production'],
            'degraded' => ['app-prod-02', 'production'],
            'attacked' => ['bastion-01', 'production'],
            'unreachable' => ['legacy-01', 'staging'],
        ];

        foreach ($fleet as $profile => [$name, $environment]) {
            $machine = Machine::updateOrCreate(['name' => $name], [
                'host' => "{$profile}.fake.test",
                'port' => 22,
                'username' => 'sentinel',
                'private_key' => 'fake-private-key',
                'environment' => $environment,
            ]);

            FakeTransport::reset($machine);
            $machine->update(['host_key_fingerprint' => FakeTransport::fingerprintFor($machine)]);
        }
    }
}
