<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

class MakeAdmin extends Command
{
    protected $signature = 'sentinel:admin {email} {--name=Admin} {--role=admin : viewer, approver or admin}';

    protected $description = 'Create a user who can log in to the dashboard (admin by default; see the Users page for what each role may do)';

    public function handle(): int
    {
        if (! array_key_exists($this->option('role'), User::ROLES)) {
            $this->error('The role must be one of: '.implode(', ', array_keys(User::ROLES)).'.');

            return self::FAILURE;
        }

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
        $user->role = $this->option('role');
        $user->save();

        $this->info(ucfirst($user->role).' created. Log in at /login; two-factor authentication is set up at the first login.');

        return self::SUCCESS;
    }
}
