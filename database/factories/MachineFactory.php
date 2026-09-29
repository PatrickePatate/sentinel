<?php

namespace Database\Factories;

use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Machine> */
class MachineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->domainWord(),
            'host' => fake()->ipv4(),
            'port' => 22,
            'username' => 'sentinel',
            'private_key' => 'fake-private-key',
            'environment' => 'production',
        ];
    }
}
