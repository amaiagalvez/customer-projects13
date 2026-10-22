<?php

namespace App\Models;

use Basics13\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    /**
     * Give the account the `admin` system role, which the permission migrations grant full access
     * to every module.
     */
    public function admin(): static
    {
        return $this->afterCreating(static function (User $user): void {
            $user->assignRole(Role::query()->where('slug', 'admin')->firstOrFail());
        });
    }
}
