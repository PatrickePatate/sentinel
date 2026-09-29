<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class MakeAdmin extends Command
{
    protected $signature = 'sentinel:admin {email} {--name=Admin}';

    protected $description = 'Create a user who can log in to the Sharp back-office';

    public function handle(): int
    {
        User::create([
            'name' => $this->option('name'),
            'email' => $this->argument('email'),
            'password' => password('Password', required: true),
        ]);

        $this->info('Admin created. Log in at /sharp.');

        return self::SUCCESS;
    }
}
