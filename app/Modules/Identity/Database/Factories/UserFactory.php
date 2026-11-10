<?php

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Enums\UserRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Passw0rd!',
            'role' => UserRole::Candidate,
            'full_name' => fake()->name(),
            'phone' => null,
            'locale' => 'ar',
        ];
    }

    public function recruiter(): static
    {
        return $this->state(['role' => UserRole::Recruiter]);
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin]);
    }
}
