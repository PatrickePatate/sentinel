<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

class MakeAdmin extends Command
{
    protected $signature = 'sentinel:admin {email} {--name=Admin}';

    protected $description = 'Create a user who can log in to the Sharp back-office (approve root actions, chat with the agent)';

    public function handle(): int
    {
        $plain = password('Password (12+ characters, mixed case, digits)', required: true);

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $plain],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', Password::min(12)->mixedCase()->numbers()->uncompromised()],
            ],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = new User(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $plain]);
        $user->is_admin = true;
        $user->save();

        $this->info('Admin created. Log in at /sharp.');

        return self::SUCCESS;
    }
}
