<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Enums\UserRole;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The only way to create an admin. The public register endpoint refuses the admin role, so
 * an admin can only be made by someone who can run commands on the server.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {email : The admin\'s email}
        {--name=Admin : Full name}
        {--password= : For scripts only. Leave it out and you are asked for it, so it stays out of your shell history}';

    protected $description = 'Create an admin user';

    public function handle(): int
    {
        $password = $this->option('password') ?? $this->secret('Password (at least 8 characters, with a letter and a digit)');

        $validator = Validator::make([
            'email' => $this->argument('email'),
            'name' => $this->option('name'),
            'password' => $password,
        ], [
            'email' => ['required', 'email', 'max:254'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            // The same rules as registration.
            'password' => ['required', 'string', 'min:8', 'max:128', 'regex:/\p{L}/u', 'regex:/\d/'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $email = User::normalizeEmail($this->argument('email'));
        if (User::where('email', $email)->exists()) {
            $this->error("A user with the email $email already exists.");

            return self::FAILURE;
        }

        User::create([
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'full_name' => $this->option('name'),
            'locale' => 'en',
        ]);

        $this->info("Admin $email created.");

        return self::SUCCESS;
    }
}
