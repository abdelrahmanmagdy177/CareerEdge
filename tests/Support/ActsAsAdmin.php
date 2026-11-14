<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;

trait ActsAsAdmin
{
    /**
     * Creates an admin the real way (the artisan command) and returns a fresh access token.
     * Calling it again logs the same admin in again, which tests need after a time jump.
     */
    protected function adminToken(string $email = 'admin@example.com'): string
    {
        if (! User::where('email', $email)->exists()) {
            $this->artisan('admin:create', ['email' => $email, '--name' => 'Site Admin', '--password' => self::PASSWORD])
                ->assertSuccessful();
        }

        return $this->api('POST', '/auth/login', ['email' => $email, 'password' => self::PASSWORD])
            ->assertOk()
            ->json('accessToken');
    }
}
